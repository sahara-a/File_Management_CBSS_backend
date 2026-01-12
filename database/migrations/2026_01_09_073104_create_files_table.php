<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('folder_id')->nullable();

            $table->string('name', 255);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->nullable();

            // local storage path for now (later we can switch to Google Drive id)
            $table->string('storage_disk', 50)->default('local');
            $table->string('storage_path', 500);

            // later for google drive
            $table->string('drive_file_id', 200)->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('folder_id')->references('id')->on('folders')->onDelete('set null');

            $table->index(['user_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
