<?php

namespace App\Events;

use App\Models\ChatSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An agent has seen the user's messages up to (and including) lastReadMessageId. */
class ChatMessagesRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatSession $session) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->session->id}")];
    }

    public function broadcastAs(): string
    {
        return 'ChatMessagesRead';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => $this->session->id,
            'lastReadMessageId' => $this->session->last_read_message_id,
        ];
    }
}
