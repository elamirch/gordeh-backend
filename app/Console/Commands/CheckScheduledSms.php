<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ScheduledSMS;
use App\Jobs\SendSmsJob;

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
    public function handle()
    {
        ScheduledSMS::where('status', 'pending')
            ->where('send_at', '<=', now())
            ->chunkById(100, function ($messages) {
                foreach ($messages as $sms) {

                    $sms->update([
                        'status' => 'processing'
                    ]);

                    SendSmsJob::dispatch($sms->id);
                }

            });

        return self::SUCCESS;
    }
}
