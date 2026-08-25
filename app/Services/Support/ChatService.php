<?php

namespace App\Services\Support;

use App\Events\ChatMessageSent;
use App\Events\ChatSessionStatusChanged;
use App\Events\NewChatSessionStarted;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ChatService
{
    public const STATUSES = ['waiting', 'active', 'closed'];

    public function findSession(int $sessionId): ?ChatSession
    {
        return ChatSession::find($sessionId);
    }

    public function createSession(User $patient): ChatSession
    {
        $session = ChatSession::create([
            'user_id' => $patient->id,
            'status' => 'waiting',
        ]);

        Log::info('support.chat_session_created', ['session_id' => $session->id, 'user_id' => $patient->id]);

        NewChatSessionStarted::dispatch($session);

        return $session;
    }

    public function addMessage(ChatSession $session, string $sender, string $text, ?string $fileUrl): ChatMessage
    {
        $message = ChatMessage::create([
            'chat_session_id' => $session->id,
            'sender' => $sender,
            'text' => $text,
            'file_url' => $fileUrl,
        ]);

        ChatMessageSent::dispatch($message);

        $this->applyAutoTransition($session, $sender);

        return $message;
    }

    /**
     * An agent reply always signals the session is now being handled, even if it was
     * previously closed. A patient message only reopens a closed session — it never touches
     * an already waiting/active one, since those already correctly reflect "needs attention".
     */
    private function applyAutoTransition(ChatSession $session, string $sender): void
    {
        if ($sender === 'support' && in_array($session->status, ['waiting', 'closed'], true)) {
            $this->updateStatus($session, 'active');
        } elseif ($sender === 'user' && $session->status === 'closed') {
            $this->updateStatus($session, 'waiting');
        }
    }

    public function updateStatus(ChatSession $session, string $status): ChatSession
    {
        if ($session->status === $status) {
            return $session;
        }

        $session->status = $status;
        $session->save();

        Log::info('support.chat_session_status_changed', ['session_id' => $session->id, 'status' => $status]);

        ChatSessionStatusChanged::dispatch($session);

        return $session;
    }

    public function history(ChatSession $session): Collection
    {
        return $session->messages;
    }

    public function listForAdmin(array $filters): LengthAwarePaginator
    {
        $query = ChatSession::with(['user', 'latestMessage'])->orderByDesc('updated_at');

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
            'fileUrl' => $message->file_url,
            'createdAt' => $message->created_at->toIso8601String(),
        ];
    }

    public function presentSessionForAdmin(ChatSession $session): array
    {
        $session->loadMissing(['user', 'latestMessage']);
        $last = $session->latestMessage;

        return [
            'sessionId' => $session->id,
            'patientId' => $session->user_id,
            'patientName' => $session->user ? $this->fullName($session->user) : null,
            'status' => $session->status,
            'createdAt' => $session->created_at->toIso8601String(),
            'updatedAt' => $session->updated_at->toIso8601String(),
            'lastMessage' => $last ? [
                'text' => $last->text,
                'sender' => $last->sender,
                'createdAt' => $last->created_at->toIso8601String(),
            ] : null,
        ];
    }

    private function fullName(User $user): ?string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }
}
