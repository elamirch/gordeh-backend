<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Lock-screen notification for support agents about the live chat. Queued so a patient's
 * message never waits on the push services; the console's service worker (public/sw-support.js)
 * shows it and opens the session on tap.
 */
class SupportChatPush extends Notification implements ShouldQueue
{
    use Queueable;

    public const TTL_SECONDS = 24 * 60 * 60;

    public function __construct(
        private readonly int $sessionId,
        private readonly string $title,
        private readonly string $body,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/images/favicon.png')
            ->dir('rtl')
            ->lang('fa')
            // One notification per session: a burst of messages replaces instead of stacking.
            ->tag("chat-{$this->sessionId}")
            ->renotify(true)
            ->data(['sessionId' => $this->sessionId])
            // Kept by the push service for a day while the phone is offline (no signal, airplane
            // mode) and delivered once it reconnects — iOS then shows only the latest per app.
            ->options(['TTL' => self::TTL_SECONDS, 'urgency' => 'high']);
    }
}
