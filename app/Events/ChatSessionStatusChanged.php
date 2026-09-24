<?php

namespace App\Events;

use App\Models\ChatSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatSessionStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatSession $session) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('support.agents')];
    }

    public function broadcastAs(): string
    {
        return 'ChatSessionStatusChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => $this->session->id,
            'status' => $this->session->status,
        ];
    }
}
