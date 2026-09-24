<?php

namespace App\Services\Support\Concerns;

use Illuminate\Support\Facades\Log;

trait GuardsSmsSending
{
    /**
     * Skips the real network call during automated tests (and local debug mode, matching
     * AuthController::sendotp's existing convention) so support flows never depend on a
     * live SMS gateway; a failed send is logged but never breaks the calling request.
     */
    private function sendSmsSafely(callable $send): void
    {
        if (app()->runningUnitTests() || env('APP_DEBUG')) {
            return;
        }

        try {
            $send();
        } catch (\Throwable $e) {
            Log::error('support.sms_failed', ['error' => $e->getMessage()]);
        }
    }
}
