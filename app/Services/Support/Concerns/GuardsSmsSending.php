<?php

namespace App\Services\Support\Concerns;

use Illuminate\Support\Facades\Log;

trait GuardsSmsSending
{
    /**
     * Skips the real network call during automated tests (and local debug mode, matching
     * AuthController::sendotp's existing convention) so support flows never depend on a
     * live SMS gateway; a failed send is logged but never breaks the calling request.
     *
     * Kavenegar reports errors (unknown template, invalid token, no credit, ...) in the response
     * body instead of failing the request, so anything but status 200 is logged as a failure too.
     */
    private function sendSmsSafely(string $template, callable $send): void
    {
        if (app()->runningUnitTests() || config('app.debug')) {
            return;
        }

        try {
            $response = $send();
        } catch (\Throwable $e) {
            Log::error('support.sms_failed', ['template' => $template, 'error' => $e->getMessage()]);

            return;
        }

        $status = $response->return->status ?? null;
        if ($status !== 200) {
            Log::error('support.sms_failed', [
                'template' => $template,
                'status' => $status ?? 'no response',
                'message' => $response->return->message ?? null,
            ]);
        }
    }

    /**
     * Sends a heads-up to the support admin phone, if one is configured.
     */
    private function notifyAdminSafely(string $template, callable $send): void
    {
        $adminPhone = config('services.kavenegar.support_admin_phone');

        if (! $adminPhone) {
            return;
        }

        $this->sendSmsSafely($template, fn () => $send($adminPhone));
    }
}
