<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DriveItem;
use App\Services\GoogleDriveService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DriveItemController extends Controller
{
    public function __construct(private GoogleDriveService $drive)
    {
    }

    public function index(Request $request)
    {
        $folderId = $request->query('folder_id');
        $search = $request->query('search');

        $query = DriveItem::query()
            ->notTrashed()
            ->inFolder($folderId);

        if ($search) {
            $query->search($search);
        }

        $items = $query->orderByDesc('type')->orderBy('name')->get();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function show(DriveItem $driveItem)
    {
        if ($driveItem->trashed) {
            abort(404);
        }

        return response()->json([
            'item' => $driveItem,
            'breadcrumbs' => $driveItem->getBreadcrumbs(),
            'children' => $driveItem->children()->notTrashed()->orderByDesc('type')->orderBy('name')->get(),
        ]);
    }

    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'parent_id' => ['nullable', 'exists:drive_items,id'],
        ]);

        $parent = $this->resolveParent($data['parent_id'] ?? null);

        try {
            $file = $data['file'];
            $uploaded = $this->drive->uploadFile(
                $file->getPathname(),
                $file->getClientOriginalName(),
                $parent?->gdrive_id ?? null,
                $file->getMimeType()
            );

            $driveItem = DriveItem::create([
                'gdrive_id' => $uploaded->getId(),
                'name' => $uploaded->getName(),
                'type' => 'file',
                'parent_id' => $parent?->id,
                'size' => $uploaded->getSize() ? (int) $uploaded->getSize() : null,
                'mime_type' => $uploaded->getMimeType(),
                'trashed' => (bool) $uploaded->getTrashed(),
                'gdrive_created_at' => $this->toCarbon($uploaded->getCreatedTime()),
                'gdrive_modified_at' => $this->toCarbon($uploaded->getModifiedTime()),
            ]);

            $this->logAction($request, 'file_uploaded', $driveItem);

            return response()->json([
                'item' => $driveItem,
            ], Response::HTTP_CREATED);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to upload file to Google Drive',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function createFolder(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:drive_items,id'],
        ]);

        $parent = $this->resolveParent($data['parent_id'] ?? null);

        try {
            $folder = $this->drive->createFolder($data['name'], $parent?->gdrive_id ?? null);

            $driveItem = DriveItem::create([
                'gdrive_id' => $folder->getId(),
                'name' => $folder->getName(),
                'type' => 'folder',
                'parent_id' => $parent?->id,
                'trashed' => (bool) $folder->getTrashed(),
                'gdrive_created_at' => $this->toCarbon($folder->getCreatedTime()),
                'gdrive_modified_at' => $this->toCarbon($folder->getModifiedTime()),
            ]);

            $this->logAction($request, 'folder_created', $driveItem);

            return response()->json([
                'item' => $driveItem,
            ], Response::HTTP_CREATED);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to create folder on Google Drive',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function rename(Request $request, DriveItem $driveItem)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $oldName = $driveItem->name;
            $updated = $this->drive->renameFile($driveItem->gdrive_id, $data['name']);

            $driveItem->update([
                'name' => $updated->getName(),
                'gdrive_modified_at' => $this->toCarbon($updated->getModifiedTime()) ?? now(),
            ]);

            $this->logAction($request, $driveItem->isFolder() ? 'folder_renamed' : 'file_renamed', $driveItem, [
                'old_name' => $oldName,
                'new_name' => $data['name'],
            ]);

            return response()->json(['item' => $driveItem]);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to rename item on Google Drive',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function move(Request $request, DriveItem $driveItem)
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:drive_items,id'],
        ]);

        $newParent = $this->resolveParent($data['parent_id'] ?? null);
        $oldParentGDriveId = $driveItem->parent?->gdrive_id ?? $this->drive->getRootFolderId();
        $newParentGDriveId = $newParent?->gdrive_id ?? $this->drive->getRootFolderId();

        try {
            $updated = $this->drive->moveFile(
                $driveItem->gdrive_id,
                $newParentGDriveId,
                $oldParentGDriveId !== $newParentGDriveId ? $oldParentGDriveId : null
            );

            $driveItem->update([
                'parent_id' => $newParent?->id,
                'gdrive_modified_at' => $this->toCarbon($updated->getModifiedTime()) ?? now(),
            ]);

            $this->logAction($request, $driveItem->isFolder() ? 'folder_moved' : 'file_moved', $driveItem, [
                'new_parent_id' => $driveItem->parent_id,
            ]);

            return response()->json(['item' => $driveItem]);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to move item on Google Drive',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Request $request, DriveItem $driveItem)
    {
        try {
            $this->drive->deleteFile($driveItem->gdrive_id);

            $driveItem->update([
                'trashed' => true,
                'gdrive_modified_at' => now(),
            ]);

            $this->logAction($request, $driveItem->isFolder() ? 'folder_deleted' : 'file_deleted', $driveItem);

            return response()->json(['message' => 'Item trashed on Google Drive']);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to delete item from Google Drive',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function download(DriveItem $driveItem)
    {
        if ($driveItem->trashed || $driveItem->isFolder()) {
            abort(404);
        }

        try {
            $content = $this->drive->downloadFile($driveItem->gdrive_id);

            return response()->streamDownload(
                fn () => print($content),
                $driveItem->name,
                [
                    'Content-Type' => $driveItem->mime_type ?? 'application/octet-stream',
                ]
            );
        } catch (Exception $e) {
            report($e);

            abort(404, 'Unable to download file from Google Drive');
        }
    }

    public function sync(Request $request)
    {
        try {
            $seen = [];
            $stats = [
                'files' => 0,
                'folders' => 0,
            ];

            $this->syncFolder($this->drive->getRootFolderId(), null, $seen, $stats);

            $this->logAction($request, 'sync_completed', null, $stats);

            return response()->json([
                'message' => 'Sync completed',
                'stats' => $stats,
                'synced_at' => now()->toIso8601String(),
            ]);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to sync with Google Drive',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function syncStatus()
    {
        return response()->json([
            'status' => 'idle',
            'updated_at' => DriveItem::max('updated_at')?->toIso8601String(),
        ]);
    }

    private function syncFolder(string $folderId, ?DriveItem $parent, array &$seen, array &$stats): void
    {
        $items = $this->drive->listFiles($folderId, ['includeTrashed' => true]);

        foreach ($items as $file) {
            $isFolder = $this->isFolderMime($file->getMimeType());

            $driveItem = DriveItem::updateOrCreate(
                ['gdrive_id' => $file->getId()],
                [
                    'name' => $file->getName(),
                    'type' => $isFolder ? 'folder' : 'file',
                    'parent_id' => $parent?->id,
                    'size' => $isFolder ? null : ($file->getSize() ? (int) $file->getSize() : null),
                    'mime_type' => $file->getMimeType(),
                    'trashed' => (bool) $file->getTrashed(),
                    'gdrive_created_at' => $this->toCarbon($file->getCreatedTime()),
                    'gdrive_modified_at' => $this->toCarbon($file->getModifiedTime()),
                ]
            );

            $seen[] = $file->getId();
            $isFolder ? $stats['folders']++ : $stats['files']++;

            if ($isFolder && ! $file->getTrashed()) {
                $this->syncFolder($file->getId(), $driveItem, $seen, $stats);
            }
        }
    }

    private function resolveParent(?int $parentId): ?DriveItem
    {
        if ($parentId === null) {
            return null;
        }

        $parent = DriveItem::query()->notTrashed()->findOrFail($parentId);

        if (! $parent->isFolder()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Parent must be a folder');
        }

        return $parent;
    }

    private function toCarbon(?string $time): ?Carbon
    {
        return $time ? Carbon::parse($time) : null;
    }

    private function isFolderMime(?string $mimeType): bool
    {
        return $mimeType === 'application/vnd.google-apps.folder';
    }

    private function logAction(Request $request, string $action, ?DriveItem $item = null, array $metadata = []): void
    {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'target_id' => $item?->id,
            'target_name' => $item?->name,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
