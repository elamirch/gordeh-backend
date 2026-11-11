<?php

namespace App\Events;

use App\Models\ChatSession;
use App\Services\Support\ChatService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The widget's session snapshot, pushed whenever it changes (claim, status, end, rating, queue position). */
class ChatSessionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatSession $session) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->session->id}")];
    }

    public function broadcastAs(): string
    {
        return 'ChatSessionUpdated';
    }

    /** Same shape as GET /support/chat/sessions/{id} (never contains the guest token). */
    public function broadcastWith(): array
    {
        return app(ChatService::class)->presentSession($this->session);
    }
}
