<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('user')
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($logs);
    }

    public function show(AuditLog $auditLog)
    {
        return response()->json($auditLog->load('user'));
    }

    public function exportCsv(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="audit_logs.csv"',
        ];

        $callback = function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'User ID', 'Action', 'Target ID', 'Target Name', 'IP', 'User Agent', 'Created At']);

            AuditLog::orderByDesc('created_at')->chunk(200, function ($logs) use ($output) {
                foreach ($logs as $log) {
                    fputcsv($output, [
                        $log->id,
                        $log->user_id,
                        $log->action,
                        $log->target_id,
                        $log->target_name,
                        $log->ip_address,
                        $log->user_agent,
                        $log->created_at,
                    ]);
                }
            });

            fclose($output);
        };

        return response()->stream($callback, 200, $headers);
    }
}
