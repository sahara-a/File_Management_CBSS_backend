<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileBrowserController;




Route::prefix('auth')->group(function () {

    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/2fa/setup', [AuthController::class, 'twoFactorSetupWithToken']);
    Route::post('/2fa/enable', [AuthController::class, 'twoFactorEnableWithToken']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);

        
        Route::post('/2fa/disable', [AuthController::class, 'twoFactorDisable']); // disables 2FA (optionally ask password)
        
    });

    
});
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/items', [FileBrowserController::class, 'list']);
    Route::post('/folders', [FileBrowserController::class, 'createFolder']);
    Route::post('/files/upload', [FileBrowserController::class, 'upload']);
    Route::get('/files/{id}/open', [FileBrowserController::class, 'openFile']);
    Route::get('/files/{id}/download', [FileBrowserController::class, 'downloadFile']);

});
