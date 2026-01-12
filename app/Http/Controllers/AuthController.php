<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use App\Services\AuditLogger;

class AuthController extends Controller
{
    /**
     * Login user (API – token based)
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'otp_code' => ['nullable', 'string', 'max:10'], // frontend-safe
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            AuditLogger::log($request, 'auth.login_failed', 'User', null, [
                'email' => $data['email'] ?? null,
            ]);

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // Optional active check (safe even if column doesn't exist)
        if (isset($user->is_active) && (int) $user->is_active !== 1) {
            throw ValidationException::withMessages([
                'email' => ['Account is inactive.'],
            ]);
        }

        // ✅ Force all users to setup 2FA before issuing token
        if ((int)$user->two_factor_enabled !== 1) {

            // create short-lived setup token
            $setupToken = Str::random(64);

            // store mapping for 10 minutes
            Cache::put('2fa_setup:' . $setupToken, [
                'user_id' => $user->id,
            ], now()->addMinutes(10));

            return response()->json([
                'success' => false,
                'message' => '2FA setup required',
                'error_code' => 'TWOFA_SETUP_REQUIRED',
                'setup_token' => $setupToken,
            ], 401);
        }


        // Optional 2FA requirement (no verification yet)
        if ((int)$user->two_factor_enabled === 1) {
            $otp = trim((string)($request->otp_code ?? ''));

            if ($otp === '') {
                return response()->json([
                    'success' => false,
                    'message' => '2FA OTP required',
                    'error_code' => 'OTP_REQUIRED',
                ], 401);
            }

            $secret = $user->getTwoFactorSecretPlain();
            if (!$secret) {
                return response()->json([
                    'success' => false,
                    'message' => '2FA is enabled but not configured',
                    'error_code' => 'OTP_NOT_CONFIGURED',
                ], 401);
            }

            $google2fa = new Google2FA();

            // window=1 allows slight clock drift (previous/next 30s)
            $isValid = $google2fa->verifyKey($secret, $otp, 1);

            if (!$isValid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid OTP',
                    'error_code' => 'OTP_INVALID',
                ], 401);
            }
        }


        // Remove old tokens (recommended)
        $user->tokens()->delete();

        // Create new token
        $token = $user->createToken('web')->plainTextToken;

        AuditLogger::log($request, 'auth.login', 'User', $user->id, [
            'email' => $user->email,
        ]);


        // Optional last login timestamp
        if (isset($user->last_login_at)) {
            $user->last_login_at = now();
            $user->save();
        }

        return response()->json([
            'success' => true,
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        return response()->json([
            'success' => true,
            'user'    => $request->user(),
        ]);
    }


    public function twoFactorSetupWithToken(Request $request)
    {
        $request->validate([
            'setup_token' => ['required', 'string'],
        ]);

        $payload = Cache::get('2fa_setup:' . $request->setup_token);
        if (!$payload || empty($payload['user_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Setup token expired or invalid',
                'error_code' => 'SETUP_TOKEN_INVALID',
            ], 401);
        }

        $user = \App\Models\User::find($payload['user_id']);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey(32);

        $appName = config('app.name', 'App');
        $email = $user->email ?? ('user-' . $user->id);

        $otpauthUrl = $google2fa->getQRCodeUrl($appName, $email, $secret);

        // save secret but do NOT enable yet
        // (encrypt at rest if you added the mutator; otherwise still works)
        $user->two_factor_secret = $secret;
        $user->two_factor_enabled = 0;
        $user->save();

        return response()->json([
            'success' => true,
            'data' => [
                'otpauth_url' => $otpauthUrl,
                'secret' => $secret, // optional manual entry
            ],
        ]);
    }


    public function twoFactorEnableWithToken(Request $request)
    {
        $request->validate([
            'setup_token' => ['required', 'string'],
            'otp_code' => ['required', 'string'],
        ]);

        $payload = Cache::get('2fa_setup:' . $request->setup_token);
        if (!$payload || empty($payload['user_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Setup token expired or invalid',
                'error_code' => 'SETUP_TOKEN_INVALID',
            ], 401);
        }

        $user = \App\Models\User::find($payload['user_id']);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $secret = method_exists($user, 'getTwoFactorSecretPlain')
            ? $user->getTwoFactorSecretPlain()
            : $user->two_factor_secret;

        if (!$secret) {
            return response()->json([
                'success' => false,
                'message' => '2FA secret not generated. Run setup first.',
            ], 422);
        }

        $google2fa = new Google2FA();
        $otp = trim((string)$request->otp_code);

        if (!$google2fa->verifyKey($secret, $otp, 1)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP',
                'error_code' => 'OTP_INVALID',
            ], 422);
        }

        $user->two_factor_enabled = 1;
        $user->save();

        // burn token so it can't be reused
        Cache::forget('2fa_setup:' . $request->setup_token);

        // issue token now (user is fully verified)
        $user->tokens()->delete(); // optional: same behavior as login
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => '2FA enabled',
            'token' => $token,
            'user' => $user,
        ]);
    }


    public function twoFactorDisable(Request $request)
    {
        $user = $request->user();

        // optional hardening: require password confirmation here
        // keep simple for now

        $user->two_factor_enabled = 0;
        $user->two_factor_secret = null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => '2FA disabled',
        ]);
    }


    /**
     * Logout user (revoke token)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        AuditLogger::log($request, 'auth.logout', 'User', $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}
