<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Basic identity
            $table->string('name');
            $table->string('email')->unique();

            // Auth
            $table->string('password');

            // Status & control
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();

            // Optional 2FA (future-safe)
            $table->boolean('two_factor_enabled')->default(false);
            $table->string('two_factor_secret')->nullable();

            // Laravel defaults
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
