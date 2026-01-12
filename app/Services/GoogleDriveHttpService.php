<?php

namespace App\Services;

use GuzzleHttp\Client;

class GoogleDriveHttpService
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 60,
            'verify' => false, // TEMP: disable SSL verification
        ]);
    }

    private function getAccessToken(): string
    {
        $clientId = env('GOOGLE_DRIVE_CLIENT_ID');
        $clientSecret = env('GOOGLE_DRIVE_CLIENT_SECRET');
        $refreshToken = env('GOOGLE_DRIVE_REFRESH_TOKEN');

        if (!$clientId || !$clientSecret || !$refreshToken) {
            throw new \RuntimeException('Google Drive env vars missing (CLIENT_ID/SECRET/REFRESH_TOKEN).');
        }

        $res = $this->http->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ],
        ]);

        $json = json_decode((string) $res->getBody(), true);

        if (empty($json['access_token'])) {
            throw new \RuntimeException('Failed to obtain access_token from Google.');
        }

        return $json['access_token'];
    }

    public function upload(string $absolutePath, string $fileName, string $mimeType, ?string $folderId = null): string
    {
        $accessToken = $this->getAccessToken();

        $parentId = $folderId ?: (env('GOOGLE_DRIVE_FOLDER_ID') ?: null);

        $metadata = ['name' => $fileName];
        if ($parentId && $parentId !== 'root') {
            $metadata['parents'] = [$parentId];
        }

        $res = $this->http->post('https://www.googleapis.com/upload/drive/v3/files', [
            'query' => [
                'uploadType' => 'multipart',
                'fields' => 'id',
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'multipart' => [
                [
                    'name' => 'metadata',
                    'contents' => json_encode($metadata),
                    'headers' => [
                        'Content-Type' => 'application/json; charset=UTF-8',
                    ],
                ],
                [
                    'name' => 'file',
                    'contents' => fopen($absolutePath, 'r'),
                    'filename' => $fileName,
                    'headers' => [
                        'Content-Type' => $mimeType ?: 'application/octet-stream',
                    ],
                ],
            ],
        ]);

        $json = json_decode((string) $res->getBody(), true);

        if (empty($json['id'])) {
            throw new \RuntimeException('Upload succeeded but no file id returned from Google Drive.');
        }

        return $json['id'];
    }

    public function getFileMeta(string $fileId): array
    {
        $accessToken = $this->getAccessToken();

        $res = $this->http->get("https://www.googleapis.com/drive/v3/files/{$fileId}", [
            'query' => [
                'fields' => 'id,name,mimeType,webViewLink,webContentLink',
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);

        return json_decode((string) $res->getBody(), true) ?: [];
    }

    public function downloadFileStream(string $driveFileId)
    {
        // This should return a readable stream (resource)
        // Uses Drive API: GET https://www.googleapis.com/drive/v3/files/{fileId}?alt=media
        $accessToken = $this->getAccessToken(); // whatever you already use internally

        $url = "https://www.googleapis.com/drive/v3/files/" . urlencode($driveFileId) . "?alt=media";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        // Create temp stream
        $fp = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_FILE, $fp);

        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $httpCode >= 400) {
            fclose($fp);
            throw new \RuntimeException('Drive download failed: HTTP ' . $httpCode . ' ' . $err);
        }

        rewind($fp);
        return $fp;
    }


    public function buildDownloadUrl(string $fileId): string
    {
        // Direct download endpoint (requires Authorization header if you proxy it),
        // BUT for browser open, better use webViewLink.
        return "https://drive.google.com/uc?id={$fileId}&export=download";
    }


    public function streamFileToOutput(string $driveFileId): void
    {
        $accessToken = $this->getAccessToken();
        $url = "https://www.googleapis.com/drive/v3/files/" . urlencode($driveFileId) . "?alt=media";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
        ]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        // stream directly to output
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            echo $data;
            return strlen($data);
        });

        $ok = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $http >= 400) {
            throw new \RuntimeException("Drive stream failed: HTTP {$http} {$err}");
        }
    }


}
