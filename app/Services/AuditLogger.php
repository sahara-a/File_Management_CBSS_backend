<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(Request $request, string $action, ?string $entityType = null, $entityId = null, array $meta = []): void
    {
        try {
            AuditLog::create([
                'user_id' => optional($request->user())->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 1000),
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            // Do NOT break app if audit fails
        }
    }
}
