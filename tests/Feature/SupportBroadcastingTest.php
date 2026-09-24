<?php

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Events\ChatSessionStatusChanged;
use App\Events\NewChatSessionStarted;
use App\Events\TicketMessageSent;
use App\Models\ChatSession;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SupportBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        $this->resetJwtState();
        $token = JWTAuth::fromUser($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function patient(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    private function agent(): User
    {
        return User::factory()->create([
            'role' => 'support_agent',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    private function authorize(User $user, string $channel): int
    {
        // phpunit.xml pins BROADCAST_CONNECTION=null for the rest of the suite (Laravel's
        // stock default, so other tests never touch a real broadcaster). NullBroadcaster::auth()
        // is a hard no-op — it never runs the routes/channels.php callbacks at all — so channel
        // authorization can only be exercised by switching to a real (still fully local, no
        // network) driver just for this call. Reverb signs the auth response with HMAC using
        // the app secret; it never talks to a live Reverb server for this specific request.
        //
        // Broadcast::channel() registers onto whichever driver instance is current *at boot
        // time* (routes/channels.php ran once, against the 'null' driver). forgetDrivers()
        // discards that instance — including its channel registrations — so the freshly
        // resolved 'reverb' instance starts with none at all. Re-requiring the file rebinds
        // every channel onto the new instance; safe to call repeatedly since it only ever
        // calls Broadcast::channel(), never declares a function/class.
        config(['broadcasting.default' => 'reverb']);
        $this->app->make(\Illuminate\Broadcasting\BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');

        return $this->withHeaders($this->authHeaders($user))
            ->post('/api/broadcasting/auth', [
                'channel_name' => 'private-'.$channel,
                'socket_id' => '1234.5678',
            ])->getStatusCode();
    }

    // --- Channel authorization ---

    public function test_patient_can_authorize_own_ticket_channel(): void
    {
        $patient = $this->patient();
        $ticket = Ticket::create(['user_id' => $patient->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $this->assertSame(200, $this->authorize($patient, "ticket.{$ticket->id}"));
    }

    public function test_patient_cannot_authorize_another_patients_ticket_channel(): void
    {
        $owner = $this->patient();
        $stranger = $this->patient();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $this->assertSame(403, $this->authorize($stranger, "ticket.{$ticket->id}"));
    }

    public function test_agent_can_authorize_any_ticket_channel(): void
    {
        $ticket = Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $this->assertSame(200, $this->authorize($this->agent(), "ticket.{$ticket->id}"));
    }

    public function test_patient_can_authorize_own_chat_channel(): void
    {
        $patient = $this->patient();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'active']);

        $this->assertSame(200, $this->authorize($patient, "chat.{$session->id}"));
    }

    public function test_patient_cannot_authorize_another_patients_chat_channel(): void
    {
        $owner = $this->patient();
        $stranger = $this->patient();
        $session = ChatSession::create(['user_id' => $owner->id, 'status' => 'active']);

        $this->assertSame(403, $this->authorize($stranger, "chat.{$session->id}"));
    }

    public function test_patient_cannot_authorize_support_agents_channel(): void
    {
        $this->assertSame(403, $this->authorize($this->patient(), 'support.agents'));
    }

    public function test_agent_can_authorize_support_agents_channel(): void
    {
        $this->assertSame(200, $this->authorize($this->agent(), 'support.agents'));
    }

    // --- Event dispatching ---

    public function test_patient_ticket_message_broadcasts_ticket_message_sent(): void
    {
        Event::fake([TicketMessageSent::class]);
        $patient = $this->patient();
        $ticket = Ticket::create(['user_id' => $patient->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $this->withHeaders($this->authHeaders($patient))
            ->postJson("/api/support/tickets/{$ticket->id}/messages", ['text' => 'hi']);

        Event::assertDispatched(TicketMessageSent::class, function (TicketMessageSent $event) use ($ticket) {
            $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name);

            return $event->message->author === 'user'
                && $channels->contains("private-ticket.{$ticket->id}")
                && $channels->contains('private-support.agents');
        });
    }

    public function test_agent_reply_broadcasts_on_ticket_and_agents_channel(): void
    {
        Event::fake([TicketMessageSent::class]);
        $agent = $this->agent();
        $ticket = Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $this->withHeaders($this->authHeaders($agent))
            ->postJson("/api/admin/tickets/{$ticket->id}/messages", ['text' => 'reply', 'type' => 'reply']);

        Event::assertDispatched(TicketMessageSent::class, function (TicketMessageSent $event) use ($ticket) {
            $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name);

            return $event->message->author === 'agent'
                && $channels->contains("private-ticket.{$ticket->id}")
                && $channels->contains('private-support.agents');
        });
    }

    public function test_internal_note_only_broadcasts_on_agents_channel_not_ticket_channel(): void
    {
        Event::fake([TicketMessageSent::class]);
        $agent = $this->agent();
        $ticket = Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $this->withHeaders($this->authHeaders($agent))
            ->postJson("/api/admin/tickets/{$ticket->id}/messages", ['text' => 'note', 'type' => 'note']);

        Event::assertDispatched(TicketMessageSent::class, function (TicketMessageSent $event) use ($ticket) {
            $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name);

            return $event->message->author === 'note'
                && ! $channels->contains("private-ticket.{$ticket->id}")
                && $channels->contains('private-support.agents');
        });
    }

    public function test_chat_message_broadcasts_on_its_session_channel(): void
    {
        Event::fake([ChatMessageSent::class, NewChatSessionStarted::class]);
        $patient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/support/chat/messages', ['text' => 'hello']);

        $sessionId = $response->json('sessionId');

        Event::assertDispatched(NewChatSessionStarted::class);
        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($sessionId) {
            $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name);

            return $channels->contains("private-chat.{$sessionId}");
        });
    }

    public function test_second_chat_message_does_not_redispatch_new_session_event(): void
    {
        Event::fake([NewChatSessionStarted::class]);
        $patient = $this->patient();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'active']);

        $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'hello again']);

        Event::assertNotDispatched(NewChatSessionStarted::class);
    }

    public function test_chat_session_status_changed_broadcasts_on_agents_channel(): void
    {
        Event::fake([ChatSessionStatusChanged::class]);
        $agent = $this->agent();
        $session = ChatSession::create(['user_id' => $this->patient()->id, 'status' => 'active']);

        $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/chat/sessions/{$session->id}", ['status' => 'closed']);

        Event::assertDispatched(ChatSessionStatusChanged::class, function (ChatSessionStatusChanged $event) {
            $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name);

            return $event->session->status === 'closed'
                && $channels->contains('private-support.agents');
        });
    }

    public function test_automatic_transition_also_broadcasts_status_changed(): void
    {
        Event::fake([ChatSessionStatusChanged::class]);
        $agent = $this->agent();
        $session = ChatSession::create(['user_id' => $this->patient()->id, 'status' => 'waiting']);

        $this->withHeaders($this->authHeaders($agent))
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'سلام']);

        Event::assertDispatched(ChatSessionStatusChanged::class, fn (ChatSessionStatusChanged $event) => $event->session->status === 'active'
        );
    }

    // --- GET history ---

    public function test_patient_can_fetch_own_chat_history(): void
    {
        $patient = $this->patient();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'active']);
        $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'msg1']);

        $response = $this->withHeaders($this->authHeaders($patient))
            ->getJson("/api/support/chat/messages?sessionId={$session->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.text', 'msg1');
    }

    public function test_patient_cannot_fetch_another_patients_chat_history(): void
    {
        $owner = $this->patient();
        $stranger = $this->patient();
        $session = ChatSession::create(['user_id' => $owner->id, 'status' => 'active']);

        $response = $this->withHeaders($this->authHeaders($stranger))
            ->getJson("/api/support/chat/messages?sessionId={$session->id}");

        $response->assertStatus(403);
    }

    public function test_agent_can_fetch_and_reply_to_chat_session(): void
    {
        $patient = $this->patient();
        $agent = $this->agent();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'active']);

        $history = $this->withHeaders($this->authHeaders($agent))
            ->getJson("/api/admin/chat/messages?sessionId={$session->id}");
        $history->assertStatus(200);
        $history->assertJsonCount(0);

        $reply = $this->withHeaders($this->authHeaders($agent))
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'پاسخ کارشناس']);

        $reply->assertStatus(201);
        $reply->assertJsonPath('sender', 'support');
        $this->assertDatabaseHas('chat_messages', ['chat_session_id' => $session->id, 'sender' => 'support']);
    }

    public function test_normal_user_cannot_reply_via_admin_chat_endpoint(): void
    {
        $patient = $this->patient();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'active']);

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'x']);

        $response->assertStatus(403);
    }
}
