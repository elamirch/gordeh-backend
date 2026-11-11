<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ConsultationType;
use App\Services\NutritionConsultation\ConsultationSms;
use App\Services\ScheduleService;
use App\Support\JalaliDate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    private const STATUSES = ['requested', 'confirmed', 'done', 'canceled', 'noshow'];

    public function index()
    {
        $appointments = $this->visibleAppointments()->with(['user', 'provider', 'booking'])->orderByDesc('date')->orderByDesc('time')->get();

        return response()->json($appointments->map(fn (Appointment $a) => $this->present($a))->values());
    }

    public function updateStatus(Request $request, Appointment $appointment)
    {
        // 404 (not 403) so another provider's appointment ids aren't confirmed to exist.
        abort_unless($this->visibleAppointments()->whereKey($appointment->id)->exists(), 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $previousStatus = $appointment->status;
        $appointment->update(['status' => $data['status']]);
        $appointment = $appointment->fresh(['user', 'provider', 'booking']);

        if ($appointment->status !== $previousStatus) {
            match ($appointment->status) {
                'confirmed' => (new ConsultationSms)->appointmentConfirmed($appointment),
                'canceled' => (new ConsultationSms)->appointmentCanceled($appointment),
                default => null,
            };
        }

        return response()->json($this->present($appointment));
    }

    /** A provider sees only their own appointments; admins see all of them. */
    private function visibleAppointments()
    {
        $query = Appointment::query();

        return auth()->user()->role === 'admin' ? $query : $query->where('provider_id', auth()->id());
    }

    private function present(Appointment $a): array
    {
        $type = $a->booking?->type;

        return [
            'id' => $a->id,
            'pid' => $a->user_id,
            'patientName' => $a->user ? trim(($a->user->first_name ?? '').' '.($a->user->last_name ?? '')) : '—',
            'patientPhone' => $a->user?->phone_number ?? '—',
            'date' => JalaliDate::relativeDayMonth($a->date),
            'time' => $a->time,
            'dur' => JalaliDate::toPersianDigits((string) ($a->duration_minutes ?: ScheduleService::SLOT_MINUTES)).' دقیقه',
            'type' => $type ? (ConsultationType::find($type)?->title ?? $type) : null,
            'status' => $a->status,
            'note' => $a->note,
        ];
    }
}
