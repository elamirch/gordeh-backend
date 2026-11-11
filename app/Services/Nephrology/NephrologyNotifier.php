<?php

namespace App\Services\Nephrology;

use App\Models\NephroConsultation;
use App\Models\User;
use App\Services\ReminderScheduler;
use App\Services\SendSMS;
use App\Services\Support\Concerns\GuardsSmsSending;

/**
 * Kavenegar lookup SMS for the nephrology consultation flow. Each template has to exist on the
 * Kavenegar panel (a missing one is logged, never breaks the request). Tokens hold no spaces.
 */
class NephrologyNotifier
{
    use GuardsSmsSending;

    /** To the doctor: a patient paid and is waiting. token = patient name. */
    public function newConsultation(NephroConsultation $c): void
    {
        $this->send('nephro-doctor-new-consultation', $c->nephrologist?->phone_number, ReminderScheduler::nameToken($c->patient_name));
    }

    /** To the patient: the doctor wrote back for the first time. token = doctor name. */
    public function doctorReplied(NephroConsultation $c): void
    {
        $this->send('nephro-patient-doctor-replied', $c->patient?->phone_number, ReminderScheduler::nameToken($c->nephrologist?->last_name));
    }

    /** To the patient: the doctor closed the consultation and published the summary. token = doctor name. */
    public function summaryPublished(NephroConsultation $c): void
    {
        $this->send('nephro-patient-summary', $c->patient?->phone_number, ReminderScheduler::nameToken($c->nephrologist?->last_name));
    }

    /** To the patient: the fee went back to the wallet. token = doctor name. */
    public function refunded(NephroConsultation $c): void
    {
        $this->send('nephro-patient-refunded', $c->patient?->phone_number, ReminderScheduler::nameToken($c->nephrologist?->last_name));
    }

    /** To the doctor: the application was approved. token = doctor name. */
    public function applicationApproved(User $doctor): void
    {
        $this->send('nephro-doctor-approved', $doctor->phone_number, ReminderScheduler::nameToken($doctor->last_name));
    }

    /** To the doctor: a document needs fixing. token = doctor name. */
    public function applicationNeedsFix(User $doctor): void
    {
        $this->send('nephro-doctor-needs-fix', $doctor->phone_number, ReminderScheduler::nameToken($doctor->last_name));
    }

    /** To the doctor: the payout was sent. token = amount in toman, digits only. */
    public function payoutPaid(User $doctor, int $amountRial): void
    {
        $this->send('nephro-doctor-payout-paid', $doctor->phone_number, (string) intdiv($amountRial, 10));
    }

    private function send(string $template, ?string $phone, string $token): void
    {
        if (! $phone) {
            return;
        }

        $this->sendSmsSafely($template, fn () => (new SendSMS)->instant($phone, $template, $token));
    }
}
