<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one user's inbox (patient or doctor) that a consultation changed — a new request, a
 * status change, an unread message — so the lists refresh without polling. Carries ids only;
 * the client refetches what it shows.
 */
class NephroConsultationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $userId, public int $consultationId, public string $reason) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("nephro.inbox.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'NephroConsultationUpdated';
    }

    public function broadcastWith(): array
    {
        return ['consultationId' => $this->consultationId, 'reason' => $this->reason];
    }
}
