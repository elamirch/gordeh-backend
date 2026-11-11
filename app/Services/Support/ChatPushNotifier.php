<?php

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Notifications\SupportChatPush;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pushes live-chat activity to support agents' phones: a session's first patient message goes
 * to all staff as a new chat, later ones to the agent who claimed the session (all staff while
 * nobody has). Failures are logged, never thrown — a push must not break the chat itself.
 *
 * Keyed on the first *message* rather than on session creation, since members' sessions are
 * created empty and then get their message — one chat must buzz the phone once, not twice.
 */
class ChatPushNotifier
{
    private const BODY_LIMIT = 120;

    public function patientMessage(ChatSession $session, ChatMessage $message): void
    {
        try {
            $this->notifyStaff($session, $message);
        } catch (Throwable $e) {
            Log::warning('support.chat_push_failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifyStaff(ChatSession $session, ChatMessage $message): void
    {
        $isFirst = ! ChatMessage::where('chat_session_id', $session->id)
            ->where('sender', 'user')
            ->where('id', '<', $message->id)
            ->exists();

        if ($isFirst) {
            $this->send($this->allStaff(), $session, 'گفتگوی جدید: '.$this->patientName($session), $this->preview($message));

            return;
        }

        $recipients = $session->agent_id
            ? User::whereKey($session->agent_id)->whereHas('pushSubscriptions')->get()
            : $this->allStaff();

        $this->send($recipients, $session, 'پیام از '.$this->patientName($session), $this->preview($message));
    }

    private function allStaff()
    {
        return User::whereIn('role', ['admin', 'support_agent'])->whereHas('pushSubscriptions')->get();
    }

    private function send($recipients, ChatSession $session, string $title, string $body): void
    {
        if ($recipients->isEmpty() || ! config('webpush.vapid.public_key')) {
            return;
        }

        Notification::send($recipients, new SupportChatPush($session->id, $title, $body));
    }

    private function patientName(ChatSession $session): string
    {
        $session->loadMissing('user');
        $name = $session->user
            ? trim(($session->user->first_name ?? '').' '.($session->user->last_name ?? ''))
            : ($session->guest_name ?? '');

        return $name !== '' ? $name : ($session->user ? 'بیمار' : 'مهمان');
    }

    private function preview(ChatMessage $message): string
    {
        $text = trim((string) $message->text);
        if ($text === '') {
            return $message->file_url ? '📎 فایل پیوست' : 'پیام جدید';
        }

        return Str::limit($text, self::BODY_LIMIT);
    }
}
