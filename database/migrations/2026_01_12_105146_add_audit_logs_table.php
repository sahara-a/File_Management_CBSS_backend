<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // who
            $table->foreignId('user_id')->nullable()->index(); // nullable for failed login attempts etc.

            // what
            $table->string('action', 100)->index(); // e.g. auth.login, file.upload, file.download
            $table->string('entity_type', 100)->nullable()->index(); // e.g. FileItem, Folder
            $table->unsignedBigInteger('entity_id')->nullable()->index();

            // context
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();

            // extra data
            $table->json('meta')->nullable(); // folder_id, file_name, drive_file_id, errors, etc.

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
