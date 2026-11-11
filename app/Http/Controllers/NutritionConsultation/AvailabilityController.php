<?php

namespace App\Http\Controllers\NutritionConsultation;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ScheduleService;
use App\Support\JalaliDate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AvailabilityController extends Controller
{
    public function index(Request $request, ScheduleService $scheduleService)
    {
        $data = $request->validate([
            'type' => 'nullable|string|in:initial,follow',
            'days' => 'nullable|integer|min:1|max:30',
            'date' => 'nullable|date',
            'provider_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('id', User::activeProvider()->select('id')))],
        ]);

        $providerId = isset($data['provider_id']) ? (int) $data['provider_id'] : null;

        $numDays = $data['days'] ?? 6;
        $today = Carbon::today();

        $days = [];
        for ($i = 0; $i < $numDays; $i++) {
            $date = $today->copy()->addDays($i);
            $days[] = [
                'label' => $scheduleService->weekdayLabel($date),
                'date' => JalaliDate::dayMonth($date),
                'isoDate' => $date->toDateString(),
                'available' => $scheduleService->isDateAvailable($date, $providerId),
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
            $scheduleService->slotsForDate($selectedDate, $providerId)
        );

        return response()->json([
            'days' => $days,
            'slots' => $slots,
        ]);
    }
}
