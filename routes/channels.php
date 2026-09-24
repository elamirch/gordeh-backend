<?php

use App\Models\ChatSession;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Every channel below is explicitly scoped to the 'api' guard, rather than relying on
// Broadcaster::retrieveUser()'s no-args auth()->user() fallback. That fallback happens to
// resolve correctly here too (the auth:api middleware calls Auth::shouldUse('api'), which
// shifts the request's default guard for the rest of the lifecycle) — but that's an
// internal Authenticate-middleware detail, not a guarantee. Naming the guard explicitly
// keeps channel authorization correct even if that detail ever changes.
$apiGuardOnly = ['guards' => ['api']];

// The patient who owns the session, or any support staff member.
Broadcast::channel('chat.{sessionId}', function (User $user, int $sessionId) {
    $session = ChatSession::find($sessionId);
    if (! $session) {
        return false;
    }

    return (int) $user->id === (int) $session->user_id || $user->isSupportStaff();
}, $apiGuardOnly);

// The patient who owns the ticket, or any support staff member. Internal notes are never
// broadcast on this channel (see TicketMessageSent::broadcastOn) — this authorization rule
// only controls who can *open* the channel, not which events land on it, so notes still
// need to be kept off it entirely rather than relying on this check alone.
Broadcast::channel('ticket.{ticketId}', function (User $user, int $ticketId) {
    $ticket = Ticket::find($ticketId);
    if (! $ticket) {
        return false;
    }

    return (int) $user->id === (int) $ticket->user_id || $user->isSupportStaff();
}, $apiGuardOnly);

// General queue channel for the admin panel: new tickets/chats, internal notes, SLA
// breaches — anything staff should see regardless of which specific ticket/chat they're
// currently looking at. Never joinable by a patient.
Broadcast::channel('support.agents', function (User $user) {
    return $user->isSupportStaff();
}, $apiGuardOnly);
