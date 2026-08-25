<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ConsultationType;
use App\Services\ScheduleService;
use App\Support\JalaliDate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    private const STATUSES = ['requested', 'confirmed', 'done', 'canceled', 'noshow'];

    public function index()
    {
        $appointments = Appointment::with(['user', 'provider', 'booking'])->orderByDesc('date')->orderByDesc('time')->get();

        return response()->json($appointments->map(fn (Appointment $a) => $this->present($a))->values());
    }

    public function updateStatus(Request $request, Appointment $appointment)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $appointment->update(['status' => $data['status']]);

        return response()->json($this->present($appointment->fresh(['user', 'provider', 'booking'])));
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
            'dur' => JalaliDate::toPersianDigits((string) ScheduleService::SLOT_MINUTES).' دقیقه',
            'type' => $type ? (ConsultationType::find($type)?->title ?? $type) : null,
            'status' => $a->status,
            'note' => $a->note,
        ];
    }
}
