<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** A support agent's effective presence changed — keeps the live "agents" list current. */
class AgentPresenceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $agentId, public string $state) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('support.agents')];
    }

    public function broadcastAs(): string
    {
        return 'AgentPresenceChanged';
    }

    public function broadcastWith(): array
    {
        return ['agentId' => $this->agentId, 'state' => $this->state];
    }
}
