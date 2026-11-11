<?php

namespace App\Console\Commands;

use App\Models\NephroConsultation;
use App\Services\Nephrology\NephrologyService;
use Illuminate\Console\Command;

class NephrologyHousekeeping extends Command
{
    protected $signature = 'gordeh:nephrology-housekeeping';

    protected $description = 'Refunds nephrology consultations the doctor never answered and closes conversations whose window ran out';

    public function handle(NephrologyService $service): int
    {
        $refunded = 0;
        $closed = 0;

        NephroConsultation::where('status', 'awaiting_reply')->where('reply_due_at', '<=', now())
            ->chunkById(100, function ($items) use ($service, &$refunded) {
                foreach ($items as $c) {
                    $refunded += $service->refund($c, 'no_reply') ? 1 : 0;
                }
            });

        NephroConsultation::where('status', 'active')->where('expires_at', '<=', now())
            ->chunkById(100, function ($items) use ($service, &$closed) {
                foreach ($items as $c) {
                    $closed += $service->expire($c) ? 1 : 0;
                }
            });

        if ($refunded || $closed) {
            $this->info("Refunded {$refunded}, closed {$closed}.");
        }

        return self::SUCCESS;
    }
}
