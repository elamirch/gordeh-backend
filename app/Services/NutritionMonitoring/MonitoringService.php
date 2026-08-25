<?php

namespace App\Services\NutritionMonitoring;

use App\Models\AlertRule;
use App\Models\Appointment;
use App\Models\ConsultationBooking;
use App\Models\ConsultationCall;
use App\Models\DietPlan;
use App\Models\MonitoringSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Presentation is intentionally locale-agnostic (ISO dates, English enums, raw numbers) —
 * Persian digit/duration/status-label formatting stays in the frontend, matching how the
 * rest of this codebase already works (see e.g. QUEUE_STATUS_META on the frontend).
 * SLA/breach state is business logic and is computed here once, then reused by the queue,
 * alerts, and overview endpoints so the three stay consistent.
 */
class MonitoringService
{
    private const OPEN_BOOKING_STATUSES_EXCLUDING = ['sent', 'done'];

    public function settings(): MonitoringSetting
    {
        return MonitoringSetting::current();
    }

    public function updateSettings(array $data): MonitoringSetting
    {
        $settings = $this->settings();
        $settings->update($data);

        return $settings->fresh();
    }

    // --- Queue -----------------------------------------------------------

    public function queue(): array
    {
        $settings = $this->settings();

        $bookings = ConsultationBooking::with([
            'provider',
            'calls' => fn ($q) => $q->orderByDesc('created_at'),
        ])->orderByDesc('created_at')->get();

        $userIds = $bookings->pluck('user_id')->filter()->unique();
        $bookingIds = $bookings->pluck('id');

        $dietPlansByUser = DietPlan::whereIn('user_id', $userIds)->get()->groupBy('user_id');
        $appointmentsByBooking = Appointment::whereIn('consultation_booking_id', $bookingIds)->get()->keyBy('consultation_booking_id');

        return $bookings->map(fn (ConsultationBooking $b) => $this->presentQueueItem(
            $b,
            $settings,
            $appointmentsByBooking->get($b->id),
            $dietPlansByUser->get($b->user_id),
        ))->values()->all();
    }

    public function queueItem(ConsultationBooking $b): array
    {
        $b->loadMissing(['provider', 'calls' => fn ($q) => $q->orderByDesc('created_at')]);

        $appointment = Appointment::where('consultation_booking_id', $b->id)->first();
        $userDietPlans = DietPlan::where('user_id', $b->user_id)->get();

        return $this->presentQueueItem($b, $this->settings(), $appointment, $userDietPlans);
    }

    private function presentQueueItem(
        ConsultationBooking $b,
        MonitoringSetting $settings,
        ?Appointment $appointment,
        ?Collection $userDietPlans
    ): array {
        $lastCall = $b->calls->first();
        $lastPlan = $userDietPlans?->sortByDesc('created_at')->first();

        return [
            'id' => $b->id,
            'patientId' => $b->user_id,
            'patientName' => $b->full_name,
            'ownerId' => $b->provider_id,
            'ownerName' => $b->provider ? $this->fullName($b->provider) : null,
            'createdAt' => $b->created_at?->toIso8601String(),
            'updatedAt' => $b->updated_at?->toIso8601String(),
            'status' => $b->status,
            'sla' => $this->slaState($b, $settings),
            'hasCalls' => $b->calls->isNotEmpty(),
            'lastCallOutcome' => $lastCall?->outcome,
            'hasAppointment' => (bool) $appointment,
            'appointmentDate' => $appointment?->date?->toDateString(),
            'hasDietPlan' => (bool) $lastPlan,
            'dietPlanVersion' => $lastPlan?->version,
            'channel' => $b->channel,
        ];
    }

    /**
     * One active SLA clock per booking, tied to whichever checkpoint it's currently
     * waiting on. `updated_at` is used as a proxy for "entered this status at" since
     * there's no dedicated status-history table.
     */
    public function slaState(ConsultationBooking $b, MonitoringSetting $settings): string
    {
        if (in_array($b->status, self::OPEN_BOOKING_STATUSES_EXCLUDING, true)) {
            return 'ok';
        }

        if ($b->status === 'new') {
            return $this->evaluateThreshold($b->created_at, $settings->first_call_hours);
        }

        if (is_null($b->provider_id)) {
            return $this->evaluateThreshold($b->created_at, $settings->assign_hours);
        }

        if ($b->status === 'review') {
            return $this->evaluateThreshold($b->updated_at, $settings->plan_hours);
        }

        return 'ok';
    }

    private function evaluateThreshold(Carbon $since, int $thresholdHours): string
    {
        $hours = $since->diffInHours(now());

        if ($hours > $thresholdHours) {
            return 'late';
        }

        if ($hours >= $thresholdHours * 0.8) {
            return 'warn';
        }

        return 'ok';
    }

    // --- Specialists -------------------------------------------------------

    public function specialists(): array
    {
        $providers = User::where('role', 'provider')->with('providerProfile')->get();
        $providerIds = $providers->pluck('id');

        $bookings = ConsultationBooking::whereIn('provider_id', $providerIds)->get()->groupBy('provider_id');
        $plans = DietPlan::whereIn('provider_id', $providerIds)->get()->groupBy('provider_id');
        $calls = ConsultationCall::whereIn('provider_id', $providerIds)->get()->groupBy('provider_id');

        return $providers->map(fn (User $p) => $this->presentSpecialist(
            $p,
            $bookings->get($p->id, collect()),
            $plans->get($p->id, collect()),
            $calls->get($p->id, collect()),
        ))->values()->all();
    }

    private function presentSpecialist(User $p, Collection $ownBookings, Collection $ownPlans, Collection $ownCalls): array
    {
        $profile = $p->providerProfile;

        $open = $ownBookings->whereNotIn('status', self::OPEN_BOOKING_STATUSES_EXCLUDING)->count();
        $done = $ownBookings->where('status', 'done')->count();
        $pendingPlan = $ownBookings->where('status', 'review')->count();
        $closed = $ownBookings->whereIn('status', self::OPEN_BOOKING_STATUSES_EXCLUDING)->count();

        return [
            'id' => $p->id,
            'name' => $this->fullName($p),
            'specialty' => $profile?->specialty,
            'active' => $profile?->is_active ?? true,
            'load' => $open,
            'capacity' => $profile?->capacity ?? 20,
            'avgFirstCallMinutes' => $this->avgFirstCallMinutes($ownBookings, $ownCalls),
            'avgPlanMinutes' => $this->avgPlanMinutes($ownBookings, $ownPlans),
            'done' => $done,
            'pendingPlan' => $pendingPlan,
            'completionRate' => $ownBookings->isNotEmpty() ? round($closed / $ownBookings->count(), 2) : null,
            'open' => $open,
        ];
    }

    private function avgFirstCallMinutes(Collection $bookings, Collection $calls): ?int
    {
        $callsByBooking = $calls->groupBy('consultation_booking_id');

        $diffs = $bookings
            ->map(function (ConsultationBooking $b) use ($callsByBooking) {
                $firstCall = $callsByBooking->get($b->id)?->sortBy('created_at')->first();

                return $firstCall ? $b->created_at->diffInMinutes($firstCall->created_at) : null;
            })
            ->filter(fn ($v) => $v !== null);

        return $diffs->isEmpty() ? null : (int) round($diffs->avg());
    }

    /**
     * diet_plans isn't linked to a specific consultation_booking, so this pairs each plan
     * with the most recent booking for the same patient+provider created before the plan —
     * a best-effort proxy for "time from consult to plan sent" until that FK exists.
     */
    private function avgPlanMinutes(Collection $bookings, Collection $plans): ?int
    {
        $bookingsByUser = $bookings->groupBy('user_id');

        $diffs = $plans
            ->map(function (DietPlan $plan) use ($bookingsByUser) {
                $candidate = $bookingsByUser->get($plan->user_id, collect())
                    ->filter(fn (ConsultationBooking $b) => $b->created_at->lte($plan->created_at))
                    ->sortByDesc('created_at')
                    ->first();

                return $candidate ? $candidate->created_at->diffInMinutes($plan->created_at) : null;
            })
            ->filter(fn ($v) => $v !== null);

        return $diffs->isEmpty() ? null : (int) round($diffs->avg());
    }

    // --- Alert rules & breaches --------------------------------------------

    public function alertRules(): array
    {
        $settings = $this->settings();

        return AlertRule::orderBy('id')->get()
            ->map(fn (AlertRule $rule) => $this->presentAlertRule($rule, $settings))
            ->values()->all();
    }

    private function presentAlertRule(AlertRule $rule, MonitoringSetting $settings): array
    {
        [$thresholdValue, $thresholdUnit] = match ($rule->type) {
            'no_first_call' => [$settings->first_call_hours, 'hours'],
            'unassigned' => [$settings->assign_hours, 'hours'],
            'plan_not_sent' => [$settings->plan_hours, 'hours'],
            'capacity_over' => [$rule->threshold_value, 'percent'],
            'late_cancellation' => [$rule->threshold_value, 'hours'],
            default => [$rule->threshold_value, 'hours'],
        };

        return [
            'id' => $rule->id,
            'title' => $rule->title,
            'type' => $rule->type,
            'thresholdValue' => $thresholdValue,
            'thresholdUnit' => $thresholdUnit,
            'count' => $this->ruleCount($rule, $settings),
            'tone' => $rule->tone,
            'enabled' => $rule->enabled,
        ];
    }

    public function updateAlertRule(AlertRule $rule, bool $enabled): AlertRule
    {
        $rule->update(['enabled' => $enabled]);

        return $rule->fresh();
    }

    private function ruleCount(AlertRule $rule, MonitoringSetting $settings): int
    {
        return match ($rule->type) {
            'no_first_call' => ConsultationBooking::where('status', 'new')
                ->where('created_at', '<', now()->subHours($settings->first_call_hours))
                ->count(),
            'unassigned' => ConsultationBooking::whereNull('provider_id')
                ->whereNotIn('status', self::OPEN_BOOKING_STATUSES_EXCLUDING)
                ->where('created_at', '<', now()->subHours($settings->assign_hours))
                ->count(),
            'plan_not_sent' => ConsultationBooking::where('status', 'review')
                ->where('updated_at', '<', now()->subHours($settings->plan_hours))
                ->count(),
            'capacity_over' => collect($this->specialists())
                ->filter(fn ($s) => $s['capacity'] > 0 && ($s['load'] / $s['capacity']) * 100 > ($rule->threshold_value ?? 90))
                ->count(),
            'late_cancellation' => $this->lateCancellationCount($rule->threshold_value ?? 6),
            default => 0,
        };
    }

    private function lateCancellationCount(int $thresholdHours): int
    {
        return Appointment::where('status', 'canceled')
            ->get()
            ->filter(function (Appointment $a) use ($thresholdHours) {
                $scheduledAt = Carbon::parse($a->date->toDateString().' '.$a->time);

                return $a->updated_at->lte($scheduledAt)
                    && $a->updated_at->diffInHours($scheduledAt) < $thresholdHours;
            })
            ->count();
    }

    /**
     * Only the three time-based booking rules produce itemized rows — capacity/cancellation
     * breaches don't map onto a single patient/booking the same way, so they only ever
     * surface as a count on the rule itself (same as the original mock data).
     */
    public function breaches(): array
    {
        $settings = $this->settings();
        $rules = AlertRule::where('enabled', true)
            ->whereIn('type', ['no_first_call', 'unassigned', 'plan_not_sent'])
            ->get();

        $breaches = [];

        foreach ($rules as $rule) {
            $bookings = match ($rule->type) {
                'no_first_call' => ConsultationBooking::where('status', 'new')
                    ->where('created_at', '<', now()->subHours($settings->first_call_hours))
                    ->with('provider')->get(),
                'unassigned' => ConsultationBooking::whereNull('provider_id')
                    ->whereNotIn('status', self::OPEN_BOOKING_STATUSES_EXCLUDING)
                    ->where('created_at', '<', now()->subHours($settings->assign_hours))
                    ->with('provider')->get(),
                'plan_not_sent' => ConsultationBooking::where('status', 'review')
                    ->where('updated_at', '<', now()->subHours($settings->plan_hours))
                    ->with('provider')->get(),
                default => collect(),
            };

            foreach ($bookings as $b) {
                $breaches[] = [
                    'id' => "rule-{$rule->id}-booking-{$b->id}",
                    'ruleId' => $rule->id,
                    'ruleLabel' => $rule->title,
                    'bookingId' => $b->id,
                    'patientId' => $b->user_id,
                    'patientName' => $b->full_name,
                    'ownerName' => $b->provider ? $this->fullName($b->provider) : null,
                    'ageSeconds' => $b->created_at->diffInSeconds(now()),
                    'tone' => $rule->tone,
                ];
            }
        }

        return $breaches;
    }

    // --- Overview ------------------------------------------------------------

    public function overview(): array
    {
        $settings = $this->settings();
        $bookings = ConsultationBooking::with('calls')->get();

        $open = $bookings->whereNotIn('status', self::OPEN_BOOKING_STATUSES_EXCLUDING);
        $openBreached = $open->filter(fn (ConsultationBooking $b) => $this->slaState($b, $settings) !== 'ok');

        $thisWeek = $bookings->where('created_at', '>=', now()->subDays(7))->count();
        $lastWeek = $bookings->whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count();

        $plansSent = DietPlan::where('status', 'sent')->count();
        $closed = $bookings->whereIn('status', self::OPEN_BOOKING_STATUSES_EXCLUDING)->count();

        $calls = ConsultationCall::all();
        $bookingsThisWeek = $bookings->where('created_at', '>=', now()->subDays(7));
        $bookingsLastWeek = $bookings->whereBetween('created_at', [now()->subDays(14), now()->subDays(7)]);
        $avgFirstCallThisWeek = $this->avgFirstCallMinutes($bookingsThisWeek, $calls);
        $avgFirstCallLastWeek = $this->avgFirstCallMinutes($bookingsLastWeek, $calls);

        return [
            'kpis' => [
                'totalRequests' => $bookings->count(),
                'totalRequestsDelta' => $thisWeek - $lastWeek,
                'openRequests' => $open->count(),
                'openRequestsBreached' => $openBreached->count(),
                'avgFirstCallMinutes' => $this->avgFirstCallMinutes($bookings, $calls),
                // negative = faster than last week (fewer minutes to first call), null if either week has no data
                'avgFirstCallMinutesDelta' => ($avgFirstCallThisWeek !== null && $avgFirstCallLastWeek !== null)
                    ? $avgFirstCallThisWeek - $avgFirstCallLastWeek
                    : null,
                'plansSent' => $plansSent,
                'completionRate' => $bookings->isNotEmpty() ? round($closed / $bookings->count(), 2) : null,
            ],
            'trend' => $this->monthlyTrend($bookings),
            'funnel' => $this->funnel($bookings),
            'statusDist' => $this->statusDistribution($open),
            'followUp' => collect($this->queue())->filter(fn ($q) => $q['sla'] !== 'ok')->values()->all(),
            'openRules' => collect($this->alertRules())->filter(fn ($r) => $r['enabled'] && $r['count'] > 0)->values()->all(),
        ];
    }

    /**
     * Gregorian month buckets — this codebase has no Gregorian-to-Jalali conversion
     * utility anywhere yet, so month labeling (Jalali display names) is left to the
     * frontend using the raw "YYYY-MM" key; it will not exactly match the Jalali
     * calendar until that conversion exists.
     */
    private function monthlyTrend(Collection $bookings): array
    {
        $months = collect(range(8, 0))->map(fn ($i) => now()->subMonths($i)->format('Y-m'));

        return $months->map(function (string $monthKey) use ($bookings) {
            $inMonth = $bookings->filter(fn (ConsultationBooking $b) => $b->created_at->format('Y-m') === $monthKey);

            return [
                'month' => $monthKey,
                'requests' => $inMonth->count(),
                'completed' => $inMonth->whereIn('status', self::OPEN_BOOKING_STATUSES_EXCLUDING)->count(),
            ];
        })->values()->all();
    }

    private function funnel(Collection $bookings): array
    {
        $bookingIds = $bookings->pluck('id');
        $withCalls = ConsultationCall::whereIn('consultation_booking_id', $bookingIds)
            ->distinct('consultation_booking_id')->count('consultation_booking_id');
        $scheduledOrLater = $bookings->whereIn('status', ['scheduled', 'review', 'sent', 'done'])->count();
        $consultedOrLater = $bookings->whereIn('status', ['review', 'sent', 'done'])->count();
        $planSent = $bookings->where('status', 'sent')->count() + $bookings->where('status', 'done')->count();

        return [
            ['key' => 'submitted', 'value' => $bookings->count()],
            ['key' => 'first_call', 'value' => $withCalls],
            ['key' => 'scheduled', 'value' => $scheduledOrLater],
            ['key' => 'consulted', 'value' => $consultedOrLater],
            ['key' => 'plan_sent', 'value' => $planSent],
        ];
    }

    private function statusDistribution(Collection $openBookings): array
    {
        return collect(['new', 'waiting', 'called', 'scheduled', 'review'])
            ->map(fn ($status) => ['key' => $status, 'value' => $openBookings->where('status', $status)->count()])
            ->values()->all();
    }

    private function fullName(User $u): string
    {
        return trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
    }
}
