<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->message->chat_session_id}")];
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }

    /** Identical shape to POST /support/chat/messages' response — same field names, same casts. */
    public function broadcastWith(): array
    {
        return [
            'id' => (string) $this->message->id,
            'sessionId' => (string) $this->message->chat_session_id,
            'sender' => $this->message->sender,
            'text' => $this->message->text,
            'fileUrl' => $this->message->file_url,
            'createdAt' => $this->message->created_at->toIso8601String(),
        ];
    }
}
