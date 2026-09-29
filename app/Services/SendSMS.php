<?php

namespace App\Services;

class SendSMS {

    private $SMS_API_URL;
    private $curl;

    public function __construct()
    {
        $this->SMS_API_URL = env('SMS_API_URL');
        $this->curl = new Curl;
    }
    
    
    public function otp($phoneNumber, $otp_code) {

        $payload = http_build_query([
            'receptor' => $phoneNumber,
            'token' => $otp_code,
            'template' => 'otp-kcp'
        ]);

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }

    public function insurance_code($phoneNumber, $identification_code, $userFirstName) {

        $payload = http_build_query([
            'receptor' => $phoneNumber,
            'token' => $userFirstName,
            'token2' => $identification_code,
            'template' => 'insurance-generated'
        ]);

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }

    /**
     * Sends a scheduled reminder. Only the tokens a template uses are set on the row, so empty
     * ones are left out of the request.
     */
    public function reminder($phoneNumber, $template, $token, $token2 = null, $token3 = null) {

        $payload = http_build_query(array_filter([
            'receptor' => $phoneNumber,
            'token' => $token,
            'token2' => $token2,
            'token3' => $token3,
            'template' => $template
        ], fn ($value) => $value !== null && $value !== ''));

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }

    public function supportNewTicket($phoneNumber, $userFirstName, $ticketId) {

        $payload = http_build_query([
            'receptor' => $phoneNumber,
            'token' => $userFirstName,
            'token2' => $ticketId,
            'template' => 'support-new-ticket'
        ]);

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }

    public function supportCallbackRequest($phoneNumber, $userFirstName) {

        $payload = http_build_query([
            'receptor' => $phoneNumber,
            'token' => $userFirstName,
            'template' => 'support-callback-request'
        ]);

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }

    public function supportReply($phoneNumber, $userFirstName, $ticketId) {

        $payload = http_build_query([
            'receptor' => $phoneNumber,
            'token' => $userFirstName,
            'token2' => $ticketId,
            'template' => 'support-reply'
        ]);

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }

    public function supportTicketClosed($phoneNumber, $userFirstName, $ticketId) {

        $payload = http_build_query([
            'receptor' => $phoneNumber,
            'token' => $userFirstName,
            'token2' => $ticketId,
            'template' => 'support-ticket-closed'
        ]);

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }
}