<?php

namespace App\Jobs;

use App\Models\ScheduledSMS;
use App\Services\ReminderScheduler;
use App\Services\SendSMS;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $smsId
    ) {}

    public function handle(SendSMS $SendSMS)
    {
        $sms = ScheduledSMS::find($this->smsId);

        if (!$sms || $sms->status !== 'processing') {
            return;
        }

        try {
            $name = ReminderScheduler::nameToken($sms->token);

            if($sms->template == "cron-assess-reminder-7d") {
                $response = $SendSMS->assessmentReminder7d($sms->phone_number, $name, $sms->token2, $sms->token3);
            } elseif(substr($sms->template, 0, 11) == "cron-assess") {
                $response = $SendSMS->assessmentReminder($sms->phone_number, $name, $sms->token2, $sms->template);
            } else {
                $response = $SendSMS->insuranceReminder($sms->phone_number, $name, $sms->template);
            }

            // Kavenegar reports errors (unknown template, invalid token, no credit, ...) in the response
            // body instead of failing the request, so anything but status 200 is a failed send.
            $status = $response->return->status ?? null;
            if ($status !== 200) {
                throw new RuntimeException(
                    'Kavenegar ' . ($status ?? 'no response') . ': ' . ($response->return->message ?? '')
                );
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

    /**
     * Covers failures that never reach handle()'s catch (worker timeout, killed process), which
     * would otherwise leave the row stuck in "processing".
     */
    public function failed(?Throwable $e): void
    {
        ScheduledSMS::where('id', $this->smsId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'error' => $e?->getMessage(),
            ]);
    }
}
