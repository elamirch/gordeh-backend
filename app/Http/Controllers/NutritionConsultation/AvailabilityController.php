<?php

namespace App\Http\Controllers\NutritionConsultation;

use App\Http\Controllers\Controller;
use App\Services\ScheduleService;
use App\Support\JalaliDate;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function index(Request $request, ScheduleService $scheduleService)
    {
        $data = $request->validate([
            'type' => 'nullable|string|in:initial,follow',
            'days' => 'nullable|integer|min:1|max:30',
            'date' => 'nullable|date',
        ]);

        $numDays = $data['days'] ?? 6;
        $today = Carbon::today();

        $days = [];
        for ($i = 0; $i < $numDays; $i++) {
            $date = $today->copy()->addDays($i);
            $days[] = [
                'label' => $scheduleService->weekdayLabel($date),
                'date' => JalaliDate::dayMonth($date),
                'isoDate' => $date->toDateString(),
                'available' => $scheduleService->isDateAvailable($date),
            ];
        }

        $selectedDate = isset($data['date']) ? Carbon::parse($data['date']) : $today;
        $slots = array_map(
            fn (array $slot) => [
                'isoDate' => $selectedDate->toDateString(),
                'time' => JalaliDate::toPersianDigits($slot['time']),
                'isoTime' => $slot['time'],
                'available' => $slot['available'],
            ],
            $scheduleService->slotsForDate($selectedDate)
        );

        return response()->json([
            'days' => $days,
            'slots' => $slots,
        ]);
    }
}
