<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ScheduledSMS;
use App\Services\ReminderScheduler;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tracking for the scheduled reminder SMSs (gordeh:sms) and the ones sent right away
 * (SendSMS::instant), broken down by segment (which message of which campaign) and, for
 * assessment reminders, by CKD stage.
 */
class SmsReminderController extends Controller
{
    public const STATUSES = ['pending', 'processing', 'sent', 'failed', 'missed'];

    /**
     * A row still "processing" after this long was claimed by gordeh:sms but its job never finished:
     * the queue worker is down or the job was lost.
     */
    public const STUCK_AFTER_MINUTES = 30;

    /**
     * A row still "pending" this long after its send time was never picked up: gordeh:sms (the
     * scheduler cron) isn't running.
     */
    public const OVERDUE_AFTER_MINUTES = 10;

    // GET /admin/sms-reminders/overview
    public function overview(): JsonResponse
    {
        $byTemplate = ScheduledSMS::select('template', 'status', DB::raw('count(*) as total'))
            ->groupBy('template', 'status')
            ->get();

        $lastSent = ScheduledSMS::where('status', 'sent')
            ->selectRaw('template, max(sent_at) as at')
            ->groupBy('template')
            ->pluck('at', 'template');
        $nextSend = ScheduledSMS::where('status', 'pending')
            ->selectRaw('template, min(send_at) as at')
            ->groupBy('template')
            ->pluck('at', 'template');

        $segments = [];
        foreach ([...array_keys(ReminderScheduler::SEGMENTS), 'other'] as $key) {
            $segments[$key] = ['key' => $key, 'templates' => ReminderScheduler::SEGMENTS[$key] ?? []]
                + self::emptyCounts() + ['lastSentAt' => null, 'nextSendAt' => null];
        }

        $totals = self::emptyCounts();
        foreach ($byTemplate as $row) {
            $key = ReminderScheduler::segmentOf($row->template);
            $segments[$key][$row->status] += (int) $row->total;
            $segments[$key]['total'] += (int) $row->total;
            $totals[$row->status] += (int) $row->total;
            $totals['total'] += (int) $row->total;

            if ($key === 'other' && ! in_array($row->template, $segments[$key]['templates'], true)) {
                $segments[$key]['templates'][] = $row->template;
            }
        }

        foreach ($segments as $key => $segment) {
            foreach ($segment['templates'] as $template) {
                $segments[$key]['lastSentAt'] = self::latest($segments[$key]['lastSentAt'], $lastSent[$template] ?? null);
                $segments[$key]['nextSendAt'] = self::earliest($segments[$key]['nextSendAt'], $nextSend[$template] ?? null);
            }
            // Raw column values are UTC without an offset; send them as ISO so the browser doesn't
            // read them as local time.
            foreach (['lastSentAt', 'nextSendAt'] as $field) {
                $at = $segments[$key][$field];
                $segments[$key][$field] = $at ? Carbon::parse($at, 'UTC')->toIso8601String() : null;
            }
        }

        // Assessment reminders carry the CKD stage in token2.
        $stages = [];
        foreach (range(1, 5) as $stage) {
            $stages[$stage] = ['stage' => $stage] + self::emptyCounts();
        }
        ScheduledSMS::where('template', 'like', 'cron-assess%')
            ->whereIn('token2', array_map('strval', range(1, 5)))
            ->select('token2', 'status', DB::raw('count(*) as total'))
            ->groupBy('token2', 'status')
            ->get()
            ->each(function ($row) use (&$stages) {
                $stages[(int) $row->token2][$row->status] += (int) $row->total;
                $stages[(int) $row->token2]['total'] += (int) $row->total;
            });

        $since = now()->subDays(30);
        $sent30 = ScheduledSMS::where('status', 'sent')->where('sent_at', '>=', $since)->count();
        $failed30 = ScheduledSMS::where('status', 'failed')->where('updated_at', '>=', $since)->count();

        return response()->json([
            'totals' => $totals,
            'stuck' => self::stuck(ScheduledSMS::query())->count(),
            'overdue' => self::overdue(ScheduledSMS::query())->count(),
            'upcoming7d' => ScheduledSMS::where('status', 'pending')
                ->whereBetween('send_at', [now(), now()->addDays(7)])
                ->count(),
            'sentLast30d' => $sent30,
            'failedLast30d' => $failed30,
            'deliveryRate30d' => $sent30 + $failed30 > 0 ? round($sent30 / ($sent30 + $failed30) * 100, 1) : null,
            'segments' => array_values(array_filter(
                $segments,
                fn ($s) => $s['key'] !== 'other' || $s['total'] > 0
            )),
            'stages' => array_values($stages),
        ]);
    }

    // GET /admin/sms-reminders
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'segment' => ['nullable', Rule::in([...array_keys(ReminderScheduler::SEGMENTS), 'other'])],
            'status' => ['nullable', Rule::in([...self::STATUSES, 'stuck', 'overdue'])],
            'stage' => 'nullable|integer|between:1,5',
            'search' => 'nullable|string|max:100',
        ]);

        $page = max(1, intval($request->query('page', 1)));
        $limit = $request->has('limit') ? intval($request->query('limit')) : 20;
        if ($limit <= 0 || $limit > 1000) $limit = 20;

        $query = ScheduledSMS::query()->with('user:id,first_name,last_name');

        if ($segment = $request->query('segment')) {
            $known = array_merge(...array_values(ReminderScheduler::SEGMENTS));
            $segment === 'other'
                ? $query->whereNotIn('template', $known)
                : $query->whereIn('template', ReminderScheduler::SEGMENTS[$segment]);
        }
        if ($status = $request->query('status')) {
            match ($status) {
                'stuck' => self::stuck($query),
                'overdue' => self::overdue($query),
                default => $query->where('status', $status),
            };
        }
        if ($stage = $request->query('stage')) {
            $query->where('template', 'like', 'cron-assess%')->where('token2', (string) $stage);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('phone_number', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)));
        }

        $count = $query->count();
        $rows = $query->orderByDesc('send_at')->orderByDesc('id')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ScheduledSMS $sms) => $this->present($sms)),
            'count' => $count,
        ]);
    }

    // POST /admin/sms-reminders/{scheduledSms}/retry — put a failed reminder back in the queue
    public function retry(ScheduledSMS $scheduledSms): JsonResponse
    {
        // Only failed rows: a "processing" one may still have its job queued, and requeueing it
        // could send it twice once the worker is back.
        if ($scheduledSms->status !== 'failed') {
            return response()->json(['message' => 'فقط پیامک‌های ناموفق را می‌توان دوباره ارسال کرد.'], 422);
        }
        if (self::isInstant($scheduledSms)) {
            return response()->json(['message' => 'پیامک‌های لحظه‌ای را نمی‌توان دوباره ارسال کرد.'], 422);
        }

        // send_at is kept: gordeh:sms sends it on its next run, or, if it is too late to send as-is,
        // folds it into the "missed" replacement message like any other late reminder.
        $scheduledSms->update(['status' => 'pending', 'error' => null]);

        AuditLog::record('sms_reminder.retried', $scheduledSms);

        return response()->json($this->present($scheduledSms->load('user:id,first_name,last_name')));
    }

    private function present(ScheduledSMS $sms): array
    {
        $isAssessment = str_starts_with($sms->template, 'cron-assess');

        return [
            'id' => $sms->id,
            'userId' => $sms->user_id,
            'firstName' => $sms->user?->first_name,
            'lastName' => $sms->user?->last_name,
            'phone' => $sms->phone_number,
            'template' => $sms->template,
            'segment' => ReminderScheduler::segmentOf($sms->template),
            'stage' => $isAssessment && is_numeric($sms->token2) ? (int) $sms->token2 : null,
            'status' => $sms->status,
            'retryable' => ! self::isInstant($sms),
            'isStuck' => $sms->status === 'processing'
                && $sms->updated_at?->lt(now()->subMinutes(self::STUCK_AFTER_MINUTES)),
            'isOverdue' => $sms->status === 'pending'
                && $sms->send_at->lt(now()->subMinutes(self::OVERDUE_AFTER_MINUTES)),
            'error' => $sms->error,
            'sendAt' => $sms->send_at?->toIso8601String(),
            'sentAt' => $sms->sent_at?->toIso8601String(),
            'labTestId' => $sms->lab_test_id,
            'insuranceId' => $sms->insurance_id,
            'createdAt' => $sms->created_at?->toIso8601String(),
        ];
    }

    private static function isInstant(ScheduledSMS $sms): bool
    {
        return in_array(ReminderScheduler::segmentOf($sms->template), ReminderScheduler::INSTANT_SEGMENTS, true);
    }

    private static function stuck(Builder $query): Builder
    {
        return $query->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES));
    }

    private static function overdue(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('send_at', '<', now()->subMinutes(self::OVERDUE_AFTER_MINUTES));
    }

    private static function emptyCounts(): array
    {
        return array_fill_keys([...self::STATUSES, 'total'], 0);
    }

    private static function latest(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    private static function earliest(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }
}
