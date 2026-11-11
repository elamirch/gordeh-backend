<?php

namespace App\Events;

use App\Models\NephroMessage;
use App\Services\Nephrology\Presenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A new line in a nephrology consultation; both sides are subscribed to the consultation channel. */
class NephroMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public NephroMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("nephro.consultation.{$this->message->consultation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'NephroMessageSent';
    }

    public function broadcastWith(): array
    {
        return Presenter::message($this->message->loadMissing('file'));
    }
}
