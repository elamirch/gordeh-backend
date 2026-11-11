<?php

namespace App\Support;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is talking to the support chat endpoints: a logged-in member (JWT) or a guest holding
 * the token of exactly one session (X-Chat-Token). Resolved once by ResolveChatActor.
 *
 * Support staff are deliberately not special here — they use /admin/chat/* only.
 */
final class ChatActor
{
    public const REQUEST_ATTRIBUTE = 'chat_actor';

    private function __construct(
        public readonly ?User $user,
        public readonly ?ChatSession $guestSession,
    ) {}

    public static function member(User $user): self
    {
        return new self($user, null);
    }

    public static function guest(ChatSession $session): self
    {
        return new self(null, $session);
    }

    public static function from(Request $request): ?self
    {
        $actor = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $actor instanceof self ? $actor : null;
    }

    public function isGuest(): bool
    {
        return $this->user === null;
    }

    /** The shared ownership rule for every session-scoped endpoint. */
    public function canAccess(ChatSession $session): bool
    {
        if ($this->user !== null) {
            return $session->user_id !== null && (int) $session->user_id === (int) $this->user->id;
        }

        return $this->guestSession !== null && $this->guestSession->id === $session->id;
    }
}
