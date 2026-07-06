<?php

namespace App\Jobs;

use App\Models\ScheduledSms;
use App\Services\SendSMS;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $smsId
    ) {}

    public function handle(SendSMS $SendSMS)
    {
        $sms = ScheduledSms::find($this->smsId);

        if (!$sms || $sms->status !== 'processing') {
            return;
        }

        try {
            if($sms->template == "cron-assess-reminder-7d") {
                $SendSMS->assessmentReminder7d($sms->phone_number, $sms->token, $sms->token2, $sms->token3);
            } if(substr($sms->template, 0, 11) == "cron-assess") {
                $SendSMS->assessmentReminder($sms->phone_number, $sms->token, $sms->token2, $sms->template);
            } else {
                $SendSMS->insuranceReminder($sms->phone_number, $sms->token, $sms->template);
            }

            $sms->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

        } catch (\Throwable $e) {

            $sms->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
