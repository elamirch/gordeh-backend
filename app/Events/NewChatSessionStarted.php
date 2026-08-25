<?php

namespace App\Events;

use App\Models\ChatSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewChatSessionStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatSession $session) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('support.agents')];
    }

    public function broadcastAs(): string
    {
        return 'NewChatSessionStarted';
    }

    public function broadcastWith(): array
    {
        $this->session->loadMissing('user');
        $patient = $this->session->user;
        $name = $patient ? trim(($patient->first_name ?? '').' '.($patient->last_name ?? '')) : null;

        return [
            'sessionId' => (string) $this->session->id,
            'patientId' => $this->session->user_id,
            'patientName' => $name !== '' ? $name : null,
            'createdAt' => $this->session->created_at->toIso8601String(),
        ];
    }
}
