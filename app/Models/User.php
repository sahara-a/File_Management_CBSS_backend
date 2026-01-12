<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Crypt;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'two_factor_enabled',
        'two_factor_secret',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    public function setTwoFactorSecretAttribute($value)
    {
        // store encrypted (or null)
        $this->attributes['two_factor_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getTwoFactorSecretPlain()
    {
        $enc = $this->two_factor_secret;

        if (!$enc) return null;

        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable $e) {
            // if old data was stored unencrypted, fallback
            return $enc;
        }
    }
}
