<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\FileItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Services\GoogleDriveOAuthService;
use App\Services\GoogleDriveHttpService;
use App\Services\AuditLogger;

class FileBrowserController extends Controller
{
    // List items inside a folder (or root if null)
    public function list(Request $request)
    {
        $folderId = $request->query('folder_id'); // null => root
        $userId = $request->user()->id;

        if ($folderId !== null) {
            $exists = Folder::where('id', $folderId)->exists();
            if (!$exists) {
                return response()->json(['success' => false, 'message' => 'Folder not found'], 404);
            }
        }

        $folders = Folder::where('parent_id', $folderId)
            ->orderBy('name')
            ->get()
            ->map(fn($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'type' => 'folder',
                'parent_id' => $f->parent_id,
                'updated_at' => $f->updated_at,
                'size' => null,
            ]);

        $files = FileItem::where('folder_id', $folderId)
            ->orderBy('name')
            ->get()
            ->map(fn($fi) => [
                'id' => $fi->id,
                'name' => $fi->name,
                'type' => 'file',
                'parent_id' => $fi->folder_id,
                'updated_at' => $fi->updated_at,
                'size' => $fi->size,
                'mime_type' => $fi->mime_type,
            ]);

        AuditLogger::log($request, 'folder.list', 'Folder', $folderId, [
            'folder_id' => $folderId,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'folders' => $folders,
                'files' => $files,
            ]
        ]);
    }

    // Create folder/subfolder
    public function createFolder(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'parent_id' => ['nullable','integer'],
        ]);

        $userId = $request->user()->id;

        if (!empty($data['parent_id'])) {
            $exists = Folder::where('id', $data['parent_id'])->where('user_id', $userId)->exists();
            if (!$exists) {
                return response()->json(['success' => false, 'message' => 'Parent folder not found'], 404);
            }
        }

        try {
            $folder = Folder::create([
                'user_id' => $userId,
                'parent_id' => $data['parent_id'] ?? null,
                'name' => trim($data['name']),
            ]);
            AuditLogger::log($request, 'folder.create', 'Folder', $folder->id, [
                'parent_id' => $folder->parent_id,
                'name' => $folder->name,
            ]);

        } catch (\Throwable $e) {
            // unique constraint violation usually => duplicate name in same parent
            throw ValidationException::withMessages([
                'name' => ['Folder with same name already exists.'],
            ]);
        }

        return response()->json([
            'success' => true,
            'folder' => $folder,
        ], 201);
    }

    public function openFile(Request $request, int $id)
    {
        $userId = $request->user()->id;

        $file = FileItem::where('id', $id)->where('user_id', $userId)->first();
        if (!$file) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        if (empty($file->drive_file_id)) {
            return response()->json(['success' => false, 'message' => 'Drive file id missing'], 400);
        }

        $drive = new GoogleDriveHttpService();
        $meta = $drive->getFileMeta($file->drive_file_id);

        // webViewLink is best to open in browser
        $url = $meta['webViewLink'] ?? null;

        if (!$url) {
            // fallback (download style)
            $url = "https://drive.google.com/file/d/{$file->drive_file_id}/view";
        }

        return response()->json([
            'success' => true,
            'url' => $url,
            'meta' => [
                'name' => $meta['name'] ?? $file->name,
                'mimeType' => $meta['mimeType'] ?? $file->mime_type,
            ],
        ]);
    }

    public function downloadFile(Request $request, int $id)
    {
        // $userId = $request->user()->id;

        // $file = FileItem::where('id', $id)->where('user_id', $userId)->first();
        $file = FileItem::where('id', $id)->first();
        if (!$file) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        // If it's local storage, download from Laravel storage
        if ($file->storage_disk === 'local') {

            if (empty($file->storage_path)) {
                return response()->json(['success' => false, 'message' => 'Storage path missing'], 400);
            }

            // Ensure it exists
            if (!\Storage::disk('local')->exists($file->storage_path)) {
                return response()->json(['success' => false, 'message' => 'File missing on disk'], 404);
            }

            // download with original filename
            $downloadName = $file->original_name ?: $file->name;

            return \Storage::disk('local')->download($file->storage_path, $downloadName);
        }

        // Google Drive case
        if (empty($file->drive_file_id)) {
            return response()->json(['success' => false, 'message' => 'Drive file id missing'], 400);
        }

        $drive = new GoogleDriveHttpService();

        // 1) meta (to get name/mime if you want)
        $meta = $drive->getFileMeta($file->drive_file_id);

        // 2) stream download
        $downloadName = $meta['name'] ?? ($file->original_name ?: $file->name);
        $mimeType     = $meta['mimeType'] ?? $file->mime_type ?? 'application/octet-stream';

        AuditLogger::log($request, 'file.download', 'FileItem', $file->id, [
            'name' => $downloadName,
            'storage_disk' => $file->storage_disk,
            'drive_file_id' => $file->drive_file_id,
        ]);


        return response()->stream(function () use ($drive, $file) {
            $drive->streamFileToOutput($file->drive_file_id);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . addslashes($downloadName) . '"',
        ]);

    }


    // Upload file into folder (or root)
    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required','file','max:102400'], // 100MB (KB)
            'folder_id' => ['nullable','integer'],
        ]);

        $userId = $request->user()->id;
        $folderId = $data['folder_id'] ?? null;

        if ($folderId !== null) {
            // $exists = Folder::where('id', $folderId)->where('user_id', $userId)->exists();
            $exists = Folder::where('id', $folderId)->exists();
            if (!$exists) {
                return response()->json(['success' => false, 'message' => 'Folder not found'], 404);
            }
        }

        $uploaded = $request->file('file');

        $uploaded = $request->file('file');

        // existing
        $tmpPath = $uploaded->store("tmp/uploads/{$userId}", 'local');
        // $absolutePath = storage_path('app/' . $tmpPath);

        // replace with this
        $absolutePath = Storage::disk('local')->path($tmpPath);

        if (!is_file($absolutePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Upload temp file not found on server.',
                'debug' => [
                    'tmpPath' => $tmpPath,
                    'absolutePath' => $absolutePath,
                ]
            ], 500);
        }

        $drive = new GoogleDriveHttpService();
        $driveFileId = $drive->upload(
            $absolutePath,
            $uploaded->getClientOriginalName(),
            $uploaded->getClientMimeType() ?: 'application/octet-stream',
            null
        );

        // 3) Save DB record
        $item = FileItem::create([
            'user_id' => $userId,
            'folder_id' => $folderId,
            'name' => $uploaded->getClientOriginalName(),
            'original_name' => $uploaded->getClientOriginalName(),
            'mime_type' => $uploaded->getClientMimeType(),
            'size' => $uploaded->getSize(),
            'storage_disk' => 'google_drive',
            'storage_path' => $tmpPath,      // optional: keep temp path for audit/debug
            'drive_file_id' => $driveFileId, // IMPORTANT
        ]);

        // 4) cleanup temp file (recommended)
        Storage::disk('local')->delete($tmpPath);

        AuditLogger::log($request, 'file.upload', 'FileItem', $item->id, [
            'folder_id' => $item->folder_id,
            'name' => $item->name,
            'size' => $item->size,
            'drive_file_id' => $item->drive_file_id,
        ]);

        return response()->json([
            'success' => true,
            'file' => $item,
        ], 201);

    }

    public function streamFileToOutput(string $driveFileId): void
    {
        $accessToken = $this->getAccessToken(); // keep your existing token method

        $url = "https://www.googleapis.com/drive/v3/files/" . urlencode($driveFileId) . "?alt=media";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
        ]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);

        // Stream chunks directly to the HTTP response
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            echo $data;
            if (function_exists('flush')) flush();
            return strlen($data);
        });

        $ok = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $http >= 400) {
            throw new \RuntimeException("Drive download failed: HTTP {$http} {$err}");
        }
    }

}
