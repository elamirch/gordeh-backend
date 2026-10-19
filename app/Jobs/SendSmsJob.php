<?php

namespace App\Jobs;

use App\Models\ScheduledSMS;
use App\Services\Jalali;
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

    public function handle(SendSMS $SendSMS, ReminderScheduler $scheduler)
    {
        $sms = ScheduledSMS::find($this->smsId);

        if (!$sms || $sms->status !== 'processing') {
            return;
        }

        // Sat in the queue too long (worker was down): send one replacement instead of the stale text.
        if ($scheduler->isMissed($sms)) {
            $scheduler->replaceMissed($sms);
            return;
        }

        try {
            // Rows keep the due date as "1405/8/26" in token3, but Kavenegar rejects slashes there,
            // so it goes out in token10 as "26 مهر 1405" (the templates read %token10).
            $date = Jalali::spoken($sms->token3);

            $response = $SendSMS->reminder(
                $sms->phone_number,
                $sms->template,
                ReminderScheduler::nameToken($sms->token),
                $sms->token2,
                $date ? null : $sms->token3,
                $date
            );

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
