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
        $name = $patient
            ? trim(($patient->first_name ?? '').' '.($patient->last_name ?? ''))
            : ($this->session->guest_name ?: 'مهمان');

        return [
            'sessionId' => (string) $this->session->id,
            'patientId' => $this->session->user_id,
            'patientName' => $name !== '' ? $name : null,
            // support.agents is staff-only, so the phone may travel here (never to the widget).
            'patientPhone' => $patient ? $patient->phone_number : $this->session->guest_phone,
            'isGuest' => $this->session->isGuest(),
            'createdAt' => $this->session->created_at->toIso8601String(),
        ];
    }
}
