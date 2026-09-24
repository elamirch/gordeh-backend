<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ProviderWeeklyHours;
use Carbon\Carbon;

class ScheduleService
{
    public const SLOT_MINUTES = 30;

    private const WEEKDAY_LABELS = [
        0 => 'یکشنبه',
        1 => 'دوشنبه',
        2 => 'سه‌شنبه',
        3 => 'چهارشنبه',
        4 => 'پنجشنبه',
        5 => 'جمعه',
        6 => 'شنبه',
    ];

    public function weekdayLabel(Carbon $date): string
    {
        return self::WEEKDAY_LABELS[$date->dayOfWeek];
    }

    /**
     * Returns [{time, available}] for the given date.
     * When $providerId is null, aggregates across every provider with working hours that day —
     * a time is available if at least one provider is free.
     */
    public function slotsForDate(Carbon $date, ?int $providerId = null): array
    {
        $weeklyHoursQuery = ProviderWeeklyHours::where('d', $date->dayOfWeek)->where('on', true);
        if ($providerId) {
            $weeklyHoursQuery->where('provider_id', $providerId);
        }

        $providersByTime = [];
        foreach ($weeklyHoursQuery->get() as $weeklyHours) {
            foreach (($weeklyHours->slots ?? []) as $range) {
                [$start, $end] = $range;
                foreach ($this->generateTimes($start, $end) as $time) {
                    $providersByTime[$time][] = $weeklyHours->provider_id;
                }
            }
        }
        ksort($providersByTime);

        $bookedQuery = Appointment::whereDate('date', $date->toDateString())
            ->whereIn('status', ['requested', 'confirmed']);
        if ($providerId) {
            $bookedQuery->where('provider_id', $providerId);
        }

        $bookedProvidersByTime = [];
        foreach ($bookedQuery->get(['provider_id', 'time']) as $appointment) {
            $bookedProvidersByTime[$appointment->time][] = $appointment->provider_id;
        }

        $slots = [];
        foreach ($providersByTime as $time => $providerIds) {
            $freeProviders = array_diff(array_unique($providerIds), $bookedProvidersByTime[$time] ?? []);
            $slots[] = [
                'time' => $time,
                'available' => count($freeProviders) > 0,
            ];
        }

        return $slots;
    }

    public function isDateAvailable(Carbon $date, ?int $providerId = null): bool
    {
        foreach ($this->slotsForDate($date, $providerId) as $slot) {
            if ($slot['available']) {
                return true;
            }
        }

        return false;
    }

    private function generateTimes(string $start, string $end): array
    {
        $times = [];
        $cursor = Carbon::createFromFormat('H:i', $start);
        $endTime = Carbon::createFromFormat('H:i', $end);

        while ($cursor->lt($endTime)) {
            $times[] = $cursor->format('H:i');
            $cursor->addMinutes(self::SLOT_MINUTES);
        }

        return $times;
    }
}
