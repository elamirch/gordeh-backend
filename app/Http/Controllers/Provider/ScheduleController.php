<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderWeeklyHours;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function weeklyHours()
    {
        $providerId = auth()->id();
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

        return response()->json($week);
    }

    public function updateWeeklyHours(Request $request)
    {
        $data = $request->validate([
            '*.d' => 'required|integer|between:0,6',
            '*.on' => 'required|boolean',
            '*.slots' => 'nullable|array',
        ]);

        $providerId = auth()->id();

        foreach ($data as $day) {
            ProviderWeeklyHours::updateOrCreate(
                ['provider_id' => $providerId, 'd' => $day['d']],
                ['on' => $day['on'], 'slots' => $day['slots'] ?? []]
            );
        }

        return response()->json(
            ProviderWeeklyHours::where('provider_id', $providerId)->orderBy('d')->get()
        );
    }

    public function availableSlots(Request $request, ScheduleService $scheduleService)
    {
        $data = $request->validate([
            'date' => 'required|date',
        ]);

        $slots = $scheduleService->slotsForDate(Carbon::parse($data['date']), auth()->id());

        return response()->json($slots);
    }
}
