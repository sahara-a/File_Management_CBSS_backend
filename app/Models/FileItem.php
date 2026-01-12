<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileItem extends Model
{
    protected $table = 'files';

    protected $fillable = [
        'user_id',
        'folder_id',
        'name',
        'original_name',
        'mime_type',
        'size',
        'storage_disk',
        'storage_path',
        'drive_file_id',
    ];
}
