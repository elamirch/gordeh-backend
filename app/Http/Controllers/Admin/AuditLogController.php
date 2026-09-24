<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'before' => ['nullable', 'date'],
        ]);

        $query = AuditLog::with('actor')->orderByDesc('created_at')->orderByDesc('id');

        if (! empty($data['before'])) {
            $query->where('created_at', '<', $data['before']);
        }

        $logs = $query->limit($data['limit'] ?? 50)->get();

        return response()->json($logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'createdAt' => $log->created_at?->toIso8601String(),
            'actorId' => $log->actor_id,
            'who' => $log->actor ? trim(($log->actor->first_name ?? '').' '.($log->actor->last_name ?? '')) : null,
            'action' => $log->action,
            'subjectType' => $log->subject_type,
            'subjectId' => $log->subject_id,
            'ip' => $log->ip,
        ])->values());
    }
}
