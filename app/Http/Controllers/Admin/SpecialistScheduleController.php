<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ProviderWeeklyHours;
use App\Models\User;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lets an admin see and change a specialist's weekly working hours and the free slots
 * those hours leave on a given date — the same data the provider edits in their own panel.
 */
class SpecialistScheduleController extends Controller
{
    public function weeklyHours(User $user)
    {
        abort_unless($user->role === 'provider', 404);

        return response()->json($this->week($user->id));
    }

    public function updateWeeklyHours(Request $request, User $user)
    {
        abort_unless($user->role === 'provider', 404);

        $data = $request->validate([
            '*.d' => 'required|integer|between:0,6',
            '*.on' => 'required|boolean',
            '*.slots' => 'nullable|array',
            '*.slots.*' => 'array|size:2',
            '*.slots.*.*' => 'date_format:H:i',
        ]);

        foreach ($data as $i => $day) {
            foreach ($day['slots'] ?? [] as $k => [$start, $end]) {
                if ($start >= $end) {
                    throw ValidationException::withMessages([
                        "$i.slots.$k" => 'ساعت پایان باید بعد از ساعت شروع باشد.',
                    ]);
                }
            }
        }

        foreach ($data as $day) {
            ProviderWeeklyHours::updateOrCreate(
                ['provider_id' => $user->id, 'd' => $day['d']],
                ['on' => $day['on'], 'slots' => $day['on'] ? ($day['slots'] ?? []) : []]
            );
        }

        AuditLog::record('specialist.schedule_updated', $user);

        return response()->json($this->week($user->id));
    }

    public function availableSlots(Request $request, User $user, ScheduleService $scheduleService)
    {
        abort_unless($user->role === 'provider', 404);

        $data = $request->validate(['date' => 'required|date']);
        $date = Carbon::parse($data['date']);

        return response()->json([
            'date' => $date->toDateString(),
            'weekday' => $scheduleService->weekdayLabel($date),
            'slots' => $scheduleService->slotsForDate($date, $user->id),
        ]);
    }

    private function week(int $providerId): array
    {
        $byDay = ProviderWeeklyHours::where('provider_id', $providerId)->get()->keyBy('d');

        $week = [];
        for ($d = 0; $d <= 6; $d++) {
            $row = $byDay->get($d);
            $week[] = [
                'd' => $d,
                'on' => $row->on ?? false,
                'slots' => $row->slots ?? [],
            ];
        }

        return $week;
    }
}
