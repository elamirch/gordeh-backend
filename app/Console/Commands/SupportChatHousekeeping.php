<?php

namespace App\Console\Commands;

use App\Models\ChatSession;
use App\Services\Support\ChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SupportChatHousekeeping extends Command
{
    protected $signature = 'gordeh:support-chat-housekeeping';

    protected $description = 'Closes abandoned support chats: empty or long-unanswered waiting ones, idle active ones and expired guest sessions';

    public function handle(ChatService $chat): int
    {
        $emptyCutoff = now()->subMinutes((int) config('support.chat.empty_waiting_minutes'));
        $unansweredCutoff = now()->subHours((int) config('support.chat.waiting_with_messages_hours'));
        $idleCutoff = now()->subMinutes((int) config('support.chat.idle_active_minutes'));
        $guestCutoff = now()->subHours((int) config('support.chat.guest_ttl_hours'));

        $closed = 0;
        $leftTheQueue = false;

        // Each rule is its own query: a session closed by an earlier rule no longer matches later ones.
        $rules = [
            // The widget opens a session for every member who opens it; most leave without writing.
            ChatSession::where('status', 'waiting')
                ->where('created_at', '<=', $emptyCutoff)
                ->whereNotExists($this->messagesQuery()),

            // Left waiting with messages (an overnight message) — kept for a day or two, then let go.
            // updated_at is "waiting since": it is stamped whenever the status changes, so a long-closed
            // chat the user just reopened is not mistaken for an old one.
            ChatSession::where('status', 'waiting')
                ->where('updated_at', '<=', $unansweredCutoff),

            // No message since the cutoff (and the session itself untouched since then).
            ChatSession::where('status', 'active')
                ->where('updated_at', '<=', $idleCutoff)
                ->whereNotExists($this->messagesQuery()->where('chat_messages.created_at', '>', $idleCutoff)),

            // Guest tokens stop working at this age, so the conversation is over either way.
            ChatSession::whereNull('user_id')
                ->where('status', '!=', 'closed')
                ->where('created_at', '<=', $guestCutoff),
        ];

        foreach ($rules as $query) {
            $query->chunkById(100, function ($sessions) use ($chat, &$closed, &$leftTheQueue) {
                foreach ($sessions as $session) {
                    $leftTheQueue = $leftTheQueue || $session->status === 'waiting';

                    // Queue positions are re-broadcast once below instead of once per closed session.
                    $chat->updateStatus($session, 'closed', 'system', refreshQueue: false);
                    $closed++;
                }
            });
        }

        if ($leftTheQueue) {
            $chat->broadcastQueuePositions();
        }

        $this->info("Closed {$closed} support chat session(s).");

        return self::SUCCESS;
    }

    /** Correlated sub-select over the session's messages, for whereNotExists(). */
    private function messagesQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('chat_messages')
            ->select(DB::raw(1))
            ->whereColumn('chat_messages.chat_session_id', 'chat_sessions.id');
    }
}
