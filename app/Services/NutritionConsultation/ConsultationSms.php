<?php

namespace App\Services\NutritionConsultation;

use App\Models\Appointment;
use App\Models\ConsultationBooking;
use App\Models\DietPlan;
use App\Models\User;
use App\Services\Jalali;
use App\Services\ReminderScheduler;
use App\Services\SendSMS;
use App\Services\Support\Concerns\GuardsSmsSending;
use Carbon\CarbonInterface;

/**
 * Kavenegar lookup SMS for the nutrition consultation flow, to the patient and to the specialist.
 * Dates go out as Jalali Y/m/d and times as H:i, so no token holds a space.
 */
class ConsultationSms
{
    use GuardsSmsSending;

    // --- To the patient ----------------------------------------------------

    /** token = name, token2 = requested date, token3 = requested time. */
    public function bookingRegistered(ConsultationBooking $booking): void
    {
        $this->send('nutrition-booking-registered', $booking->phone_number,
            ReminderScheduler::nameToken($booking->full_name), $this->date($booking->date), $booking->time);
    }

    /** token = name, token2 = date, token3 = time. */
    public function appointmentConfirmed(Appointment $appointment): void
    {
        $this->send('nutrition-appointment-confirmed', $this->patientPhone($appointment),
            $this->patientName($appointment), $this->date($appointment->date), $appointment->time);
    }

    /** Canceled by the specialist. token = name, token2 = date. */
    public function appointmentCanceled(Appointment $appointment): void
    {
        $this->send('nutrition-appointment-canceled', $this->patientPhone($appointment),
            $this->patientName($appointment), $this->date($appointment->date));
    }

    /** token = name. */
    public function planSent(DietPlan $plan, User $patient): void
    {
        $this->send('nutrition-plan-sent', $patient->phone_number,
            ReminderScheduler::nameToken($patient->first_name));
    }

    // --- To the specialist -------------------------------------------------

    /** To the specialist the patient picked. token = patient name, token2 = request id. */
    public function newRequest(ConsultationBooking $booking): void
    {
        $this->send('nutrition-provider-new-request', $booking->provider?->phone_number,
            ReminderScheduler::nameToken($booking->full_name), $booking->id);
    }

    /** token = patient name, token2 = request id. */
    public function requestAssigned(ConsultationBooking $booking): void
    {
        $this->send('nutrition-provider-assigned', $booking->provider?->phone_number,
            ReminderScheduler::nameToken($booking->full_name), $booking->id);
    }

    /** Admin nudged the specialist from the monitoring queue. token = patient name, token2 = request id. */
    public function requestReminder(ConsultationBooking $booking): void
    {
        $this->send('nutrition-provider-reminder', $booking->provider?->phone_number,
            ReminderScheduler::nameToken($booking->full_name), $booking->id);
    }

    /** token = patient name, token2 = new date, token3 = new time. */
    public function patientRescheduled(Appointment $appointment): void
    {
        $this->send('nutrition-provider-rescheduled', $appointment->provider?->phone_number,
            $this->patientName($appointment), $this->date($appointment->date), $appointment->time);
    }

    /** token = patient name, token2 = date. */
    public function patientCanceled(Appointment $appointment): void
    {
        $this->send('nutrition-provider-canceled', $appointment->provider?->phone_number,
            $this->patientName($appointment), $this->date($appointment->date));
    }

    // -----------------------------------------------------------------------

    private function send(string $template, ?string $phone, $token, $token2 = null, $token3 = null): void
    {
        if (! $phone) {
            return;
        }

        $this->sendSmsSafely($template, fn () => (new SendSMS)->instant(
            $phone, $template, $token, $token2 !== null ? (string) $token2 : null, $token3
        ));
    }

    private function date(?CarbonInterface $date): ?string
    {
        return $date ? Jalali::format($date) : null;
    }

    private function patientPhone(Appointment $appointment): ?string
    {
        return $appointment->booking?->phone_number ?? $appointment->user?->phone_number;
    }

    private function patientName(Appointment $appointment): string
    {
        return ReminderScheduler::nameToken($appointment->booking?->full_name ?? $appointment->user?->first_name);
    }
}
