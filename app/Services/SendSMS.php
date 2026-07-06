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

    public function insuranceReminder($phoneNumber, $userFirstName, $template) {

        $payload = http_build_query([
            'receptor' => $phoneNumber,
            'token' => $userFirstName,
            'template' => $template
        ]);

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }

    public function assessmentReminder7d($phoneNumber, $userFirstName, $stage, $nextAppointment) {

        $payload = http_build_query([
            'receptor' => $phoneNumber,
            'token' => $userFirstName,
            'token2' => $stage,
            'token3' => $nextAppointment,
            'template' => "cron-assess-reminder-7d"
        ]);

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }

    public function assessmentReminder($phoneNumber, $userFirstName, $stage, $template) {

        $payload = http_build_query([
            'receptor' => $phoneNumber,
            'token' => $userFirstName,
            'token2' => $stage,
            'template' => $template
        ]);

        return json_decode($this->curl->curl($this->SMS_API_URL, $payload));
    }
}