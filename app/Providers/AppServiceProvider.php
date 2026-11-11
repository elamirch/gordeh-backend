<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Dedoc\Scramble\Scramble;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind the host's TLS-terminating proxy every request reaches Laravel as
        // plain http on an internal host, so generated links (signed file URLs) would
        // point there and be blocked as mixed content on the https site. Build them
        // from the public APP_URL instead.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        // Support chat widget. The plain `throttle:N,1` form shares one bucket per caller across
        // every route that uses it, so each endpoint gets a named limiter of its own. Members are
        // keyed by account, everyone else by IP.
        $perCaller = fn (Request $request) => $request->user()?->getAuthIdentifier() ?? $request->ip();
        RateLimiter::for('chat-config', fn (Request $r) => Limit::perMinute(60)->by($perCaller($r)));
        RateLimiter::for('chat-session', fn (Request $r) => Limit::perMinute(10)->by($perCaller($r)));
        RateLimiter::for('chat-upload', fn (Request $r) => Limit::perMinute(10)->by($perCaller($r)));
        // Agent console presence heartbeat: one per minute is normal, a few tabs are fine.
        RateLimiter::for('agent-presence', fn (Request $r) => Limit::perMinute(30)->by($perCaller($r)));
        RateLimiter::for('chat-callback', fn (Request $r) => Limit::perMinute(5)->by($perCaller($r)));

        // Public webinar sign-up form.
        RateLimiter::for('webinar-register', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));

        // Lab-report OCR: each call is a paid, slow Gemini request.
        RateLimiter::for('lab-ocr', fn (Request $r) => Limit::perMinute(10)->by($perCaller($r)));
        // The app reporting OCR failures it saw (timeouts, proxy errors).
        RateLimiter::for('lab-ocr-errors', fn (Request $r) => Limit::perMinute(10)->by($perCaller($r)));

        // Support console Web Push: one line per delivery attempt, so "the notification never
        // came" can be told apart from "the push service accepted it, the phone didn't show it".
        Event::listen(NotificationSent::class, fn (NotificationSent $e) => Log::info('support.chat_push_sent', [
            'user_id' => $e->subscription->subscribable_id,
            'push_host' => parse_url($e->subscription->endpoint, PHP_URL_HOST),
        ]));
        Event::listen(NotificationFailed::class, fn (NotificationFailed $e) => Log::warning('support.chat_push_rejected', [
            'user_id' => $e->subscription->subscribable_id,
            'push_host' => parse_url($e->subscription->endpoint, PHP_URL_HOST),
            'status' => $e->report->getResponse()?->getStatusCode(),
            'reason' => $e->report->getReason(),
            'subscription_removed' => $e->report->isSubscriptionExpired(),
        ]));

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer', 'JWT')
                );
            });
    }
}
