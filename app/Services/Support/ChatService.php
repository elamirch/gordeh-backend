<?php

namespace App\Services\Support;

use App\Events\ChatAgentTyping;
use App\Events\ChatMessageSent;
use App\Events\ChatMessagesRead;
use App\Events\ChatSessionStatusChanged;
use App\Events\ChatSessionUpdated;
use App\Events\NewChatSessionStarted;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Support\PlainText;
use App\Support\PrivateFiles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatService
{
    public const STATUSES = ['waiting', 'active', 'closed'];

    public const RATINGS = ['great', 'good', 'ok', 'bad'];

    /** Iranian mobile number as the frontend sends it (normalized, no +98). */
    public const PHONE_REGEX = '/^09\d{9}$/D';

    private const TYPING_SECONDS = 5;

    public function __construct(
        private readonly ChatAvailability $availability,
        private readonly ChatPushNotifier $push,
    ) {}

    public function findSession(int $sessionId): ?ChatSession
    {
        return ChatSession::find($sessionId);
    }

    // --- Starting a session -------------------------------------------------------------

    public function createSession(User $patient, ?string $firstMessage = null): ChatSession
    {
        return $this->open(['user_id' => $patient->id], $firstMessage, ['user_id' => $patient->id]);
    }

    /**
     * A member gets their still-open session back instead of a second one; a first message, if
     * any, lands in whichever session that is.
     *
     * @return array{0: ChatSession, 1: bool} [session, wasCreated]
     */
    public function startForMember(User $member, ?string $firstMessage = null): array
    {
        $open = ChatSession::where('user_id', $member->id)
            ->whereIn('status', ['waiting', 'active'])
            ->latest('id')
            ->first();

        if ($open) {
            if ($firstMessage !== null) {
                $this->addMessage($open, 'user', $firstMessage, null);
            }

            return [$open, false];
        }

        return [$this->createSession($member, $firstMessage), true];
    }

    /**
     * A guest always starts a fresh session. The raw token is returned for the caller to hand
     * out exactly once; only its sha256 is stored.
     *
     * @return array{0: ChatSession, 1: string} [session, rawToken]
     */
    public function startForGuest(string $phone, ?string $name, ?string $firstMessage = null): array
    {
        $token = Str::random(64);

        $session = $this->open([
            'user_id' => null,
            'guest_phone' => $phone,
            'guest_name' => PlainText::clean($name) ?: null,
            'guest_token_hash' => hash('sha256', $token),
        ], $firstMessage, ['guest' => true]);

        return [$session, $token];
    }

    /**
     * Creates a `waiting` session together with its optional first user message in one
     * transaction (never a session whose opening message failed), then announces both.
     */
    private function open(array $attributes, ?string $firstMessage, array $logContext): ChatSession
    {
        [$session, $message] = DB::transaction(function () use ($attributes, $firstMessage) {
            $session = ChatSession::create($attributes + ['status' => 'waiting']);

            $message = $firstMessage === null ? null : ChatMessage::create([
                'chat_session_id' => $session->id,
                'sender' => 'user',
                'text' => $firstMessage,
            ]);

            return [$session, $message];
        });

        Log::info('support.chat_session_created', ['session_id' => $session->id] + $logContext);

        NewChatSessionStarted::dispatch($session);
        if ($message !== null) {
            ChatMessageSent::dispatch($message);
            $this->push->patientMessage($session, $message);
        }

        return $session;
    }

    // --- Messages -----------------------------------------------------------------------

    /**
     * @param  User|null  $agent  the staff member sending a 'support' message; the first one claims the session
     */
    public function addMessage(ChatSession $session, string $sender, string $text, ?string $fileUrl, ?User $agent = null): ChatMessage
    {
        $message = ChatMessage::create([
            'chat_session_id' => $session->id,
            'sender' => $sender,
            'text' => $text,
            'file_url' => $fileUrl,
        ]);

        $claimed = false;
        if ($sender === 'support') {
            $claimed = $agent !== null && $this->claim($session, $agent);

            // The agent just sent a message; "typing…" is over.
            if ($session->agent_typing_until !== null) {
                $session->agent_typing_until = null;
                $this->saveQuietly($session);
            }
        }

        ChatMessageSent::dispatch($message);
        if ($sender === 'user') {
            $this->push->patientMessage($session, $message);
        }

        $statusChanged = $this->applyAutoTransition($session, $sender);

        // A status change already broadcasts the snapshot (claim included).
        if ($claimed && ! $statusChanged) {
            ChatSessionUpdated::dispatch($session);
        }

        return $message;
    }

    /**
     * An agent reply always signals the session is now being handled, even if it was
     * previously closed. A patient message only reopens a closed session — it never touches
     * an already waiting/active one, since those already correctly reflect "needs attention".
     */
    private function applyAutoTransition(ChatSession $session, string $sender): bool
    {
        if ($sender === 'support' && in_array($session->status, ['waiting', 'closed'], true)) {
            $this->updateStatus($session, 'active');

            return true;
        }

        if ($sender === 'user' && $session->status === 'closed') {
            $this->updateStatus($session, 'waiting');

            return true;
        }

        return false;
    }

    // --- Status / claim -----------------------------------------------------------------

    /**
     * Records the first agent to handle the session. Later agents never replace it.
     * Persists silently — the caller broadcasts the snapshot once, after any status change.
     */
    public function claim(ChatSession $session, User $agent): bool
    {
        if ($session->agent_id !== null) {
            return false;
        }

        $session->agent_id = $agent->id;
        $session->agent_joined_at = now();
        $this->saveQuietly($session);

        Log::info('support.chat_session_claimed', ['session_id' => $session->id, 'agent_id' => $agent->id]);

        return true;
    }

    /** Explicit status change by an agent (PATCH /admin/chat/sessions/{id}); `active` claims. */
    public function setStatusByAgent(ChatSession $session, string $status, User $agent): ChatSession
    {
        $claimed = $status === 'active' && $this->claim($session, $agent);
        $changed = $session->status !== $status;

        $session = $this->updateStatus($session, $status, 'agent');

        if ($claimed && ! $changed) {
            ChatSessionUpdated::dispatch($session);
        }

        return $session;
    }

    /**
     * @param  string  $closedBy  'user' | 'agent' | 'system' — recorded only when closing
     * @param  bool  $refreshQueue  false lets a batch caller broadcast queue positions once at the end
     */
    public function updateStatus(ChatSession $session, string $status, string $closedBy = 'agent', bool $refreshQueue = true): ChatSession
    {
        if ($session->status === $status) {
            return $session;
        }

        $previous = $session->status;

        $session->status = $status;
        $session->closed_at = $status === 'closed' ? now() : null;
        $session->closed_by = $status === 'closed' ? $closedBy : null;
        $session->save();

        Log::info('support.chat_session_status_changed', ['session_id' => $session->id, 'status' => $status]);

        ChatSessionStatusChanged::dispatch($session);
        ChatSessionUpdated::dispatch($session);

        // Entering or leaving the queue shifts everyone queued behind this session.
        if ($refreshQueue && ($previous === 'waiting' || $status === 'waiting')) {
            $this->broadcastQueuePositions($session->id);
        }

        return $session;
    }

    /**
     * Re-pushes the snapshot (new queue position / wait) to waiting members' sessions.
     * Guests poll instead, so they are skipped.
     *
     * @param  int|null  $afterId  only sessions queued behind this id (null = every waiting session)
     */
    public function broadcastQueuePositions(?int $afterId = null): void
    {
        ChatSession::where('status', 'waiting')
            ->whereNotNull('user_id')
            ->when($afterId !== null, fn ($q) => $q->where('id', '>', $afterId))
            ->get()
            ->each(fn (ChatSession $s) => ChatSessionUpdated::dispatch($s));
    }

    // --- User-side lifecycle ------------------------------------------------------------

    /** Idempotent: ending a closed session changes nothing and fires nothing. */
    public function end(ChatSession $session): ChatSession
    {
        if ($session->status === 'closed') {
            return $session;
        }

        $session = $this->updateStatus($session, 'closed', 'user');

        Log::info('support.chat_session_ended', ['session_id' => $session->id]);

        return $session;
    }

    public function rate(ChatSession $session, string $rating, ?string $note): ChatSession
    {
        abort_unless($session->status === 'closed', 409, 'گفتگو هنوز بسته نشده است');

        // Guarded in the UPDATE itself so two concurrent submissions can't both win.
        $updated = ChatSession::whereKey($session->id)
            ->where('status', 'closed')
            ->whereNull('rated_at')
            ->update([
                'rating' => $rating,
                'rating_note' => PlainText::clean($note) ?: null,
                'rated_at' => now(),
            ]);
        abort_if($updated === 0, 409, 'قبلاً نظر ثبت شده است');

        $session->refresh();

        Log::info('support.chat_rated', ['session_id' => $session->id, 'rating' => $rating]);

        ChatSessionUpdated::dispatch($session);

        return $session;
    }

    // --- Agent-side signals -------------------------------------------------------------

    public function markTyping(ChatSession $session): void
    {
        $session->agent_typing_until = now()->addSeconds(self::TYPING_SECONDS);
        $this->saveQuietly($session);

        ChatAgentTyping::dispatch($session);
    }

    /** Agent opened the conversation: everything the user sent so far counts as seen. */
    public function markReadByAgent(ChatSession $session): void
    {
        $latest = ChatMessage::where('chat_session_id', $session->id)->where('sender', 'user')->max('id');
        if ($latest === null || (int) $latest <= (int) $session->last_read_message_id) {
            return;
        }

        $session->last_read_message_id = (int) $latest;
        $this->saveQuietly($session);

        ChatMessagesRead::dispatch($session);
    }

    /** Presence/typing/read bookkeeping must not reorder the admin list (sorted by updated_at). */
    private function saveQuietly(ChatSession $session): void
    {
        ChatSession::withoutTimestamps(fn () => $session->save());
    }

    // --- Reads --------------------------------------------------------------------------

    public function history(ChatSession $session): Collection
    {
        return $session->messages;
    }

    public function listForAdmin(array $filters): LengthAwarePaginator
    {
        $query = ChatSession::with(['user', 'agent', 'latestMessage'])->orderByDesc('updated_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min(max((int) ($filters['limit'] ?? 20), 1), 100);

        return $query->paginate($perPage, ['*'], 'page', $filters['page'] ?? 1);
    }

    public function present(ChatMessage $message): array
    {
        return [
            'id' => (string) $message->id,
            'sessionId' => (string) $message->chat_session_id,
            'sender' => $message->sender,
            'text' => $message->text,
            'fileUrl' => PrivateFiles::signedUrl($message->file_url),
            'createdAt' => $message->created_at->toIso8601String(),
        ];
    }

    /**
     * The widget's view of a session. Kept small and index-backed: guests poll it every few
     * seconds. Never exposes the guest phone/token or any agent identifier besides a display name.
     * `estimatedWaitMinutes` is null while the chat is offline (nobody is answering right now).
     */
    public function presentSession(ChatSession $session): array
    {
        $session->loadMissing('agent');

        $waiting = $session->status === 'waiting';
        $position = $waiting
            ? 1 + ChatSession::where('status', 'waiting')->where('id', '<', $session->id)->count()
            : null;

        $lastMessageId = ChatMessage::where('chat_session_id', $session->id)->max('id');

        return [
            'id' => $session->id,
            'status' => $session->status,
            'queuePosition' => $position,
            'estimatedWaitMinutes' => $waiting ? $this->availability->currentWaitMinutes($position) : null,
            'agent' => $session->agent_id !== null ? [
                'name' => $this->agentDisplayName($session->agent),
                'joinedAt' => $session->agent_joined_at?->toIso8601String(),
            ] : null,
            'agentTyping' => $session->agent_typing_until?->isFuture() ?? false,
            'lastReadMessageId' => $session->last_read_message_id,
            'lastMessageId' => $lastMessageId !== null ? (int) $lastMessageId : null,
            'rated' => $session->rated_at !== null,
        ];
    }

    public function presentSessionForAdmin(ChatSession $session): array
    {
        $session->loadMissing(['user', 'agent', 'latestMessage']);
        $last = $session->latestMessage;
        $patient = $session->user;

        return [
            'sessionId' => $session->id,
            'patientId' => $session->user_id,
            'patientName' => $patient ? $this->fullName($patient) : ($session->guest_name ?: 'مهمان'),
            'patientPhone' => $patient ? $patient->phone_number : $session->guest_phone,
            'isGuest' => $session->isGuest(),
            'status' => $session->status,
            'agent' => $session->agent ? [
                'id' => $session->agent->id,
                'name' => $this->agentDisplayName($session->agent),
                'joinedAt' => $session->agent_joined_at?->toIso8601String(),
            ] : null,
            'rating' => $session->rating,
            'ratingNote' => $session->rating_note,
            'createdAt' => $session->created_at->toIso8601String(),
            'updatedAt' => $session->updated_at->toIso8601String(),
            'lastMessage' => $last ? [
                'text' => $last->text,
                'sender' => $last->sender,
                'createdAt' => $last->created_at->toIso8601String(),
            ] : null,
        ];
    }

    private function agentDisplayName(?User $agent): string
    {
        return ($agent ? $this->fullName($agent) : null) ?? 'کارشناس پشتیبانی';
    }

    private function fullName(User $user): ?string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }
}
