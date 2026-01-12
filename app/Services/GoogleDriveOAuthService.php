<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveOAuthService
{
    private Drive $drive;
    private ?string $defaultFolderId;

    public function __construct()
    {
        $clientId = env('GOOGLE_DRIVE_CLIENT_ID');
        $clientSecret = env('GOOGLE_DRIVE_CLIENT_SECRET');
        $refreshToken = env('GOOGLE_DRIVE_REFRESH_TOKEN');
        $scope = env('GOOGLE_DRIVE_SCOPE', 'https://www.googleapis.com/auth/drive.file');

        if (!$clientId || !$clientSecret || !$refreshToken) {
            throw new \RuntimeException('Google Drive OAuth env vars missing (CLIENT_ID/SECRET/REFRESH_TOKEN).');
        }

        $client = new Client();
        $client->setApplicationName('CBSS FMS');
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setScopes([$scope]);
        $client->setAccessType('offline');

        // Get access token using refresh token (no browser login needed)
        $client->refreshToken($refreshToken);

        $this->drive = new Drive($client);
        $this->defaultFolderId = env('GOOGLE_DRIVE_FOLDER_ID') ?: null;
    }

    public function upload(string $absolutePath, string $fileName, string $mimeType, ?string $folderId = null): string
    {
        $parentId = $folderId ?: $this->defaultFolderId;

        $meta = new DriveFile([
            'name' => $fileName,
            'parents' => $parentId ? [$parentId] : null,
        ]);

        $content = file_get_contents($absolutePath);

        $created = $this->drive->files->create($meta, [
            'data' => $content,
            'mimeType' => $mimeType ?: 'application/octet-stream',
            'uploadType' => 'multipart',
            'fields' => 'id',
        ]);

        return $created->id;
    }
}
