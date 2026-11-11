<?php

namespace App\Services;

use App\Models\ScheduledSMS;
use App\Models\User;

class SendSMS {

    private $SMS_API_URL;
    private $curl;

    public function __construct()
    {
        $this->SMS_API_URL = config('services.kavenegar.lookup_url');
        $this->curl = app(Curl::class);
    }


    public function otp($phoneNumber, $otp_code) {

        // The code itself is never written to the log.
        return $this->instant($phoneNumber, 'otp-kcp', $otp_code, logTokens: false);
    }

    public function insurance_code($phoneNumber, $identification_code, $userFirstName) {

        return $this->instant(
            $phoneNumber,
            'insurance-generated',
            ReminderScheduler::nameToken($userFirstName),
            preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $identification_code)
        );
    }

    /**
     * Sends a scheduled reminder. Only the tokens a template uses are set on the row, so empty
     * ones are left out of the request. token10 is the one slot that may hold spaces (up to 5),
     * used for dates like "26 مهر 1405". The caller (SendSmsJob) records the outcome on its own row.
     */
    public function reminder($phoneNumber, $template, $token, $token2 = null, $token3 = null, $token10 = null) {

        return $this->lookup($phoneNumber, $template, $token, $token2, $token3, $token10);
    }

    /**
     * Sends a message right away (not through gordeh:sms) and records it in scheduled_sms as
     * sent or failed, so it shows up on the admin SMS page next to the scheduled reminders.
     */
    public function instant($phoneNumber, $template, $token, $token2 = null, $token3 = null, bool $logTokens = true) {

        $log = ScheduledSMS::create([
            'user_id' => User::where('phone_number', $phoneNumber)->value('id'),
            'phone_number' => $phoneNumber,
            'template' => $template,
            'token' => $logTokens ? self::column($token) : null,
            'token2' => $logTokens ? self::column($token2) : null,
            'token3' => $logTokens ? self::column($token3) : null,
            'send_at' => now(),
            'status' => 'processing',
        ]);

        try {
            $response = $this->lookup($phoneNumber, $template, $token, $token2, $token3);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);

            throw $e;
        }

        // Kavenegar reports errors (unknown template, invalid token, no credit, ...) in the response
        // body instead of failing the request, so anything but status 200 is a failed send.
        $status = $response->return->status ?? null;
        $log->update($status === 200
            ? ['status' => 'sent', 'sent_at' => now()]
            : ['status' => 'failed', 'error' => 'Kavenegar '.($status ?? 'no response').': '.($response->return->message ?? '')]);

        return $response;
    }

    public function supportNewTicket($phoneNumber, $userFirstName, $ticketId) {

        return $this->instant($phoneNumber, 'support-new-ticket', ReminderScheduler::nameToken($userFirstName), $ticketId);
    }

    public function supportCallbackRequest($phoneNumber, $userFirstName) {

        return $this->instant($phoneNumber, 'support-callback-request', ReminderScheduler::nameToken($userFirstName));
    }

    public function supportReply($phoneNumber, $userFirstName, $ticketId) {

        return $this->instant($phoneNumber, 'support-reply', ReminderScheduler::nameToken($userFirstName), $ticketId);
    }

    public function supportTicketClosed($phoneNumber, $userFirstName, $ticketId) {

        return $this->instant($phoneNumber, 'support-ticket-closed', ReminderScheduler::nameToken($userFirstName), $ticketId);
    }

    /**
     * Heads-up for the support admin. token = ticket id, token2 = patient name.
     */
    public function supportAdminNewTicket($phoneNumber, $ticketId, $patientFirstName) {

        return $this->instant($phoneNumber, 'support-admin-new-ticket', $ticketId, ReminderScheduler::nameToken($patientFirstName));
    }

    public function supportAdminPatientMessage($phoneNumber, $ticketId, $patientFirstName) {

        return $this->instant($phoneNumber, 'support-admin-patient-message', $ticketId, ReminderScheduler::nameToken($patientFirstName));
    }

    /** A new insurance request. token = request id, token2 = applicant name. */
    public function supportAdminNewInsurance($phoneNumber, $insuranceId, $applicantFirstName) {

        return $this->instant($phoneNumber, 'support-admin-new-insurance', $insuranceId, ReminderScheduler::nameToken($applicantFirstName));
    }

    /**
     * token = requester name, token2 = the phone number to call back.
     */
    public function supportAdminCallbackRequest($phoneNumber, $requesterName, $callbackPhone) {

        return $this->instant(
            $phoneNumber,
            'support-admin-callback-request',
            ReminderScheduler::nameToken($requesterName),
            preg_replace('/\D+/', '', (string) $callbackPhone)
        );
    }

    /** The token columns hold up to 50 characters. */
    private static function column($value): ?string
    {
        return $value === null ? null : mb_substr((string) $value, 0, 50);
    }

    private function lookup($phoneNumber, $template, $token, $token2 = null, $token3 = null, $token10 = null) {

        $payload = http_build_query(array_filter([
            'receptor' => $phoneNumber,
            'token' => $token,
            'token2' => $token2,
            'token3' => $token3,
            'token10' => $token10,
            'template' => $template
        ], fn ($value) => $value !== null && $value !== ''));

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }
}
