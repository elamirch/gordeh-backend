<?php

namespace App\Http\Controllers\NutritionConsultation;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ConsultationBooking;
use App\Models\ConsultationType;
use App\Services\ScheduleService;
use App\Support\JalaliDate;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    private const STATUS_LABELS = [
        'requested' => 'در انتظار تأیید',
        'confirmed' => 'تأیید شده',
        'done' => 'انجام شده',
        'canceled' => 'لغو شده',
        'noshow' => 'عدم حضور',
    ];

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string|in:initial,follow',
            'date' => 'required|date',
            'time' => 'required|string',
            'full_name' => 'required|string',
            'phone_number' => 'required|string',
            'kidney_stage' => 'nullable|string',
            'medications' => 'nullable|string',
            'conditions' => 'nullable|array',
            'conditions.*' => 'string',
            'use_existing_lab' => 'nullable|boolean',
        ]);

        $booking = ConsultationBooking::create(array_merge($data, [
            'user_id' => auth()->id(),
            'status' => 'new',
        ]));

        return response()->json($booking, 201);
    }

    public function upcoming(Request $request, ScheduleService $scheduleService)
    {
        $appointments = Appointment::where('user_id', auth()->id())
            ->whereIn('status', ['requested', 'confirmed'])
            ->whereDate('date', '>=', now()->toDateString())
            ->with(['provider', 'booking'])
            ->orderBy('date')
            ->orderBy('time')
            ->get();

        // Submitting a request only creates a ConsultationBooking — it doesn't become an
        // Appointment until a specialist reviews and schedules it. Without this, a patient's
        // request is invisible here from the moment they submit it until someone acts on it,
        // which reads as "my booking didn't go through". Surface it as a pending entry instead.
        $pendingBookings = ConsultationBooking::where('user_id', auth()->id())
            ->whereDoesntHave('appointment')
            ->whereNotIn('status', ['done', 'sent'])
            ->orderByDesc('created_at')
            ->get();

        $upcoming = $appointments->map(fn (Appointment $a) => $this->formatUpcoming($a, $scheduleService))
            ->concat($pendingBookings->map(fn (ConsultationBooking $b) => $this->formatPendingBooking($b, $scheduleService)));

        return response()->json($upcoming->values());
    }

    public function past(Request $request)
    {
        $appointments = Appointment::where('user_id', auth()->id())
            ->where(function ($q) {
                $q->whereDate('date', '<', now()->toDateString())
                    ->orWhereIn('status', ['done', 'canceled', 'noshow']);
            })
            ->with(['provider', 'booking'])
            ->orderByDesc('date')
            ->orderByDesc('time')
            ->get();

        // A request can be marked done/sent by the specialist without ever being scheduled as
        // an Appointment (e.g. handled entirely over a call). Without this, it has no Appointment
        // row and silently disappears from the patient's history the moment it leaves "upcoming".
        $finishedBookings = ConsultationBooking::where('user_id', auth()->id())
            ->whereDoesntHave('appointment')
            ->whereIn('status', ['done', 'sent'])
            ->with('provider')
            ->orderByDesc('date')
            ->get();

        $past = $appointments->map(fn (Appointment $a) => $this->formatPast($a))
            ->concat($finishedBookings->map(fn (ConsultationBooking $b) => $this->formatPastBooking($b)));

        return response()->json($past->values());
    }

    public function reschedule(Request $request, Appointment $appointment)
    {
        if ($appointment->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validate([
            'date' => 'required|date',
            'time' => 'required|string',
        ]);

        $appointment->update([
            'date' => $data['date'],
            'time' => $data['time'],
            'status' => 'requested',
        ]);

        return response()->json($appointment->fresh(['provider', 'booking']));
    }

    public function cancel(Appointment $appointment)
    {
        if ($appointment->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        $appointment->update(['status' => 'canceled']);

        return response()->json($appointment->fresh());
    }

    private const PENDING_STATUS_LABELS = [
        'new' => 'در انتظار تماس متخصص',
        'waiting' => 'در انتظار تماس متخصص',
        'called' => 'در حال هماهنگی نوبت',
        'review' => 'در حال بررسی توسط متخصص',
    ];

    private const PAST_BOOKING_STATUS_LABELS = [
        'done' => 'انجام شده',
        'sent' => 'برنامه غذایی ارسال شد',
    ];

    private function typeTitle(?string $type): string
    {
        return $type ? (ConsultationType::find($type)?->title ?? $type) : '—';
    }

    /**
     * A submitted request is a ConsultationBooking, not yet an Appointment — that only exists
     * once a specialist schedules it (see ProviderRequestController::updateStatus). Without this,
     * a freshly submitted request is invisible here, which reads as "the booking didn't happen".
     */
    private function formatPendingBooking(ConsultationBooking $b, ScheduleService $scheduleService): array
    {
        $conditions = $b->conditions ?? [];

        return [
            'id' => "booking-{$b->id}",
            'weekday' => $scheduleService->weekdayLabel($b->date),
            'date' => JalaliDate::dayMonth($b->date),
            'time' => JalaliDate::toPersianDigits($b->time),
            'type' => $this->typeTitle($b->type),
            'doctor' => $b->provider ? trim(($b->provider->first_name ?? '').' '.($b->provider->last_name ?? '')) : 'در انتظار تخصیص',
            'status' => self::PENDING_STATUS_LABELS[$b->status] ?? 'در انتظار بررسی',
            'duration' => JalaliDate::toPersianDigits((string) ScheduleService::SLOT_MINUTES).' دقیقه',
            'attachedLab' => $b->use_existing_lab ? 'آزمایش پیوست شد' : 'بدون آزمایش پیوست‌شده',
            'condition' => empty($conditions) ? '—' : implode('، ', $conditions),
            'stage' => $b->kidney_stage ? (int) $b->kidney_stage : 0,
            'joinUrl' => null,
            'isPending' => true,
        ];
    }

    private function formatUpcoming(Appointment $a, ScheduleService $scheduleService): array
    {
        $provider = $a->provider;
        $conditions = $a->booking->conditions ?? [];

        return [
            'id' => $a->id,
            'weekday' => $scheduleService->weekdayLabel($a->date),
            'date' => JalaliDate::dayMonth($a->date),
            'time' => JalaliDate::toPersianDigits($a->time),
            'type' => $this->typeTitle($a->booking->type ?? null),
            'doctor' => $provider ? trim(($provider->first_name ?? '').' '.($provider->last_name ?? '')) : 'در انتظار تخصیص',
            'status' => self::STATUS_LABELS[$a->status] ?? $a->status,
            'duration' => JalaliDate::toPersianDigits((string) ScheduleService::SLOT_MINUTES).' دقیقه',
            'attachedLab' => ($a->booking->use_existing_lab ?? false) ? 'آزمایش پیوست شد' : 'بدون آزمایش پیوست‌شده',
            'condition' => empty($conditions) ? '—' : implode('، ', $conditions),
            'stage' => $a->booking->kidney_stage ? (int) $a->booking->kidney_stage : 0,
            'joinUrl' => $a->join_url,
            'isPending' => false,
        ];
    }

    /**
     * Mirrors formatPendingBooking, but for a request that reached a terminal status
     * (done/sent) without ever being scheduled as an Appointment — see past().
     */
    private function formatPastBooking(ConsultationBooking $b): array
    {
        return [
            'id' => "booking-{$b->id}",
            'date' => JalaliDate::dayMonth($b->date),
            'time' => JalaliDate::toPersianDigits($b->time),
            'type' => $this->typeTitle($b->type),
            'doctor' => $b->provider ? trim(($b->provider->first_name ?? '').' '.($b->provider->last_name ?? '')) : '—',
            'status' => self::PAST_BOOKING_STATUS_LABELS[$b->status] ?? $b->status,
            'note' => '',
        ];
    }

    private function formatPast(Appointment $a): array
    {
        $provider = $a->provider;

        return [
            'id' => $a->id,
            'date' => JalaliDate::dayMonth($a->date),
            'time' => JalaliDate::toPersianDigits($a->time),
            'type' => $this->typeTitle($a->booking->type ?? null),
            'doctor' => $provider ? trim(($provider->first_name ?? '').' '.($provider->last_name ?? '')) : '—',
            'status' => self::STATUS_LABELS[$a->status] ?? $a->status,
            'note' => $a->note ?? '',
        ];
    }
}
