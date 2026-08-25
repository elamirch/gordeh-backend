<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ConsultationBooking;
use App\Models\ConsultationCall;
use App\Models\ConsultationType;
use App\Support\JalaliDate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RequestController extends Controller
{
    private const STATUSES = ['new', 'waiting', 'called', 'scheduled', 'review', 'sent', 'done'];

    public function index()
    {
        $requests = ConsultationBooking::with(['user', 'provider', 'calls' => fn ($q) => $q->orderByDesc('created_at')])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($requests->map(fn (ConsultationBooking $b) => $this->present($b))->values());
    }

    public function updateStatus(Request $request, ConsultationBooking $consultationRequest)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $previousProviderId = $consultationRequest->provider_id;

        $consultationRequest->status = $data['status'];
        $consultationRequest->provider_id = auth()->id();
        $consultationRequest->save();

        AuditLog::record('status_change', $consultationRequest);
        if ($previousProviderId !== $consultationRequest->provider_id) {
            AuditLog::record('assignment', $consultationRequest);
        }

        if ($data['status'] === 'scheduled') {
            // The date/time already live on the booking — the patient chose them via the
            // self-service flow when submitting the request. The provider is confirming
            // that slot, not picking a new one, so the Appointment reuses it as-is.
            Appointment::updateOrCreate(
                ['consultation_booking_id' => $consultationRequest->id],
                [
                    'user_id' => $consultationRequest->user_id,
                    'provider_id' => auth()->id(),
                    'date' => $consultationRequest->date,
                    'time' => $consultationRequest->time,
                    'status' => 'requested',
                ]
            );
        }

        return response()->json($this->present($consultationRequest->fresh(['user', 'provider', 'calls'])));
    }

    public function storeCall(Request $request, ConsultationBooking $consultationRequest)
    {
        $data = $request->validate([
            'durationSec' => 'required|integer|min:0',
            'outcome' => 'nullable|string',
        ]);

        $call = ConsultationCall::create([
            'consultation_booking_id' => $consultationRequest->id,
            'provider_id' => auth()->id(),
            'duration_sec' => $data['durationSec'],
            'outcome' => $data['outcome'] ?? null,
        ]);

        AuditLog::record('call_logged', $consultationRequest);

        return response()->json($call, 201);
    }

    private function present(ConsultationBooking $b): array
    {
        return [
            'id' => $b->id,
            'pid' => $b->user_id,
            // Captured directly on the booking at submission time, so this still renders even
            // when the submitter isn't a role=user account (e.g. staff testing the booking flow
            // on their own account) — see Provider\PatientController::index(), which only
            // returns role=user accounts and previously left rows like this unrenderable.
            'patientName' => $b->full_name,
            'patientPhone' => $b->phone_number,
            'type' => ConsultationType::find($b->type)?->title ?? $b->type,
            'date' => JalaliDate::dayMonth($b->created_at).' — '.$b->created_at->format('H:i'),
            'requestedDate' => JalaliDate::dayMonth($b->date),
            'requestedTime' => JalaliDate::toPersianDigits($b->time),
            'status' => $b->status,
            'owner' => $b->provider ? trim(($b->provider->first_name ?? '').' '.($b->provider->last_name ?? '')) : null,
            'last' => $this->lastActionLabel($b),
            'reason' => $this->reasonText($b),
        ];
    }

    private function lastActionLabel(ConsultationBooking $b): string
    {
        $lastCall = $b->calls->first();

        if ($b->status === 'sent' || $b->status === 'done') {
            return 'برنامه غذایی ارسال شد';
        }
        if ($lastCall) {
            return $lastCall->outcome ?? 'تماس ثبت شد';
        }

        return 'ثبت درخواست توسط بیمار';
    }

    private function reasonText(ConsultationBooking $b): string
    {
        $parts = array_filter([
            $b->kidney_stage ? "مرحله {$b->kidney_stage} بیماری کلیوی" : null,
            ! empty($b->conditions) ? implode('، ', $b->conditions) : null,
            $b->medications ? "داروها: {$b->medications}" : null,
        ]);

        return implode(' — ', $parts);
    }
}
