<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DriveItem Model
 * Represents a file or folder tracked in the system.
 */
class DriveItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'gdrive_id',
        'name',
        'type',
        'parent_id',
        'size',
        'mime_type',
        'trashed',
        'gdrive_created_at',
        'gdrive_modified_at',
    ];

    protected $casts = [
        'trashed' => 'boolean',
        'size' => 'integer',
        'gdrive_created_at' => 'datetime',
        'gdrive_modified_at' => 'datetime',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function folders()
    {
        return $this->hasMany(self::class, 'parent_id')->where('type', 'folder');
    }

    public function files()
    {
        return $this->hasMany(self::class, 'parent_id')->where('type', 'file');
    }

    public function isFolder(): bool
    {
        return $this->type === 'folder';
    }

    public function isFile(): bool
    {
        return $this->type === 'file';
    }

    public function scopeNotTrashed($query)
    {
        return $query->where('trashed', false);
    }

    public function scopeInFolder($query, $folderId = null)
    {
        return $query->where('parent_id', $folderId);
    }

    public function scopeFolders($query)
    {
        return $query->where('type', 'folder');
    }

    public function scopeFiles($query)
    {
        return $query->where('type', 'file');
    }

    public function getFormattedSizeAttribute(): ?string
    {
        if ($this->size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $this->size;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [];
        $current = $this;

        while ($current) {
            array_unshift($breadcrumbs, [
                'id' => $current->id,
                'name' => $current->name,
                'gdrive_id' => $current->gdrive_id,
            ]);
            $current = $current->parent;
        }

        array_unshift($breadcrumbs, [
            'id' => null,
            'name' => 'Home',
            'gdrive_id' => null,
        ]);

        return $breadcrumbs;
    }

    public function scopeSearch($query, $term)
    {
        return $query->where('name', 'like', "%{$term}%");
    }
}
