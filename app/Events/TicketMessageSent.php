<?php

namespace App\Events;

use App\Models\TicketMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TicketMessage $message) {}

    /**
     * Internal notes never reach ticket.{id} — that channel is joinable by the patient
     * (see routes/channels.php), and a note must never be visible to them. Notes only go
     * to the staff-only queue channel; replies and patient messages go to both.
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('support.agents')];

        if ($this->message->author !== 'note') {
            $channels[] = new PrivateChannel("ticket.{$this->message->ticket_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'TicketMessageSent';
    }

    /**
     * Superset shape (author/authorId/attachmentUrl) — same fields TicketService already
     * returns from the admin REST endpoints. A patient's Echo listener maps
     * author === 'agent' ? 'support' : 'user' client-side, mirroring what
     * TicketService::presentMessageForPatient already does server-side for the REST
     * response; that mapping is a display label, not a security boundary, so doing it
     * client-side here is fine — unlike notes, which never reach a patient subscriber at all.
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('authorUser');
        $author = $this->message->authorUser;
        $name = $author ? trim(($author->first_name ?? '').' '.($author->last_name ?? '')) : null;

        return [
            'id' => (string) $this->message->id,
            'ticketId' => (string) $this->message->ticket_id,
            'author' => $this->message->author,
            'authorId' => $this->message->author_id,
            'authorName' => $name !== '' ? $name : null,
            'text' => $this->message->text,
            'attachmentUrl' => $this->message->file_url,
            'createdAt' => $this->message->created_at->toIso8601String(),
        ];
    }
}
