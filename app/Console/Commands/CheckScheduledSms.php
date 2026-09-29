<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ScheduledSMS;
use App\Jobs\SendSmsJob;
use App\Services\ReminderScheduler;

class CheckScheduledSms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gordeh:sms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks scheduled SMSs and sends them if it\'s due';

    /**
     * Execute the console command.
     */
    public function handle(ReminderScheduler $scheduler)
    {
        // Reminders that are far past their time become one replacement message per user first,
        // so they don't all go out below in one burst.
        $scheduler->replaceMissedReminders();

        ScheduledSMS::where('status', 'pending')
            ->where('send_at', '<=', now())
            ->chunkById(100, function ($messages) {
                foreach ($messages as $sms) {

                    // Claim the row atomically so an overlapping run can't dispatch it twice.
                    $claimed = ScheduledSMS::where('id', $sms->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'processing']);

                    if ($claimed) {
                        SendSmsJob::dispatch($sms->id);
                    }
                }

            });

        return self::SUCCESS;
    }
}
