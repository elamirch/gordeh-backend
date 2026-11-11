<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ConsultationBooking;
use App\Models\ProviderWeeklyHours;
use App\Models\User;
use Carbon\Carbon;

class ScheduleService
{
    public const SLOT_MINUTES = 15;

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
     * a time is available if at least one provider is free. Inactive providers never count.
     */
    public function slotsForDate(Carbon $date, ?int $providerId = null): array
    {
        $weeklyHoursQuery = ProviderWeeklyHours::where('d', $date->dayOfWeek)->where('on', true)
            ->whereIn('provider_id', User::activeProvider()->select('id'));
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

        // An appointment occupies every slot from its start until start + duration_minutes, so
        // appointments booked on the old 30-minute grid still block both of their 15-minute slots.
        $bookedProvidersByTime = [];
        foreach ($bookedQuery->get(['provider_id', 'time', 'duration_minutes']) as $appointment) {
            $cursor = Carbon::createFromFormat('H:i', $appointment->time);
            $end = $cursor->copy()->addMinutes($appointment->duration_minutes ?: self::SLOT_MINUTES);
            while ($cursor->lt($end)) {
                $bookedProvidersByTime[$cursor->format('H:i')][] = $appointment->provider_id;
                $cursor->addMinutes(self::SLOT_MINUTES);
            }
        }

        // A request the patient booked with a specialist holds that slot until it is scheduled
        // (then the Appointment above holds it) or finished.
        $pendingQuery = ConsultationBooking::whereDate('date', $date->toDateString())
            ->whereNotNull('provider_id')
            ->whereNotIn('status', ['done', 'sent', 'canceled'])
            ->whereDoesntHave('appointment');
        if ($providerId) {
            $pendingQuery->where('provider_id', $providerId);
        }
        foreach ($pendingQuery->get(['provider_id', 'time']) as $booking) {
            $bookedProvidersByTime[$booking->time][] = $booking->provider_id;
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

    public function isSlotAvailable(Carbon $date, string $time, int $providerId): bool
    {
        foreach ($this->slotsForDate($date, $providerId) as $slot) {
            if ($slot['time'] === $time) {
                return $slot['available'];
            }
        }

        return false;
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
