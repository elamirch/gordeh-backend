<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        // Guards are resolved once per test-app container; without this, switching
        // actors mid-test can leak the previous request's resolved user into auth().
        $this->resetJwtState();

        $token = JWTAuth::fromUser($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function user(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    public function test_user_can_create_ticket(): void
    {
        $user = $this->user();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/tickets', [
                'category' => 'payment',
                'subject' => 'مشکل در پرداخت',
                'description' => 'توضیحات مشکل من در پرداخت است',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('subject', 'مشکل در پرداخت');
        $response->assertJsonPath('category', 'payment');
        $response->assertJsonPath('status', 'open');
        $response->assertJsonStructure(['id', 'subject', 'category', 'status', 'createdAt', 'updatedAt']);

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_messages', 1);

        $ticket = Ticket::first();
        $this->assertSame($user->id, $ticket->user_id);
        $this->assertNotNull($ticket->code);
        $message = TicketMessage::first();
        $this->assertSame('user', $message->author);
        $this->assertSame('توضیحات مشکل من در پرداخت است', $message->text);
    }

    public function test_create_ticket_rejects_invalid_category(): void
    {
        $user = $this->user();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/tickets', [
                'category' => 'not-a-real-category',
                'subject' => 'موضوع',
                'description' => 'توضیح',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_create_ticket_rejects_missing_subject(): void
    {
        $user = $this->user();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/tickets', [
                'category' => 'account',
                'description' => 'توضیح',
            ]);

        $response->assertStatus(422);
    }

    public function test_user_can_list_own_tickets(): void
    {
        $user = $this->user();
        $other = $this->user();
        Ticket::create(['user_id' => $user->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);
        Ticket::create(['user_id' => $other->id, 'subject' => 'B', 'category' => 'app', 'status' => 'open']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/support/tickets');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.subject', 'A');
    }

    public function test_user_can_view_own_ticket(): void
    {
        $user = $this->user();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->getJson("/api/support/tickets/{$ticket->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('id', (string) $ticket->id);
    }

    public function test_user_can_send_message_on_own_ticket(): void
    {
        $user = $this->user();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'A', 'category' => 'app', 'status' => 'waiting_patient']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson("/api/support/tickets/{$ticket->id}/messages", [
                'text' => 'پیام جدید کاربر',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('sender', 'user');
        $response->assertJsonPath('text', 'پیام جدید کاربر');

        $this->assertSame('in_progress', $ticket->fresh()->status);
    }

    public function test_message_on_closed_ticket_is_rejected(): void
    {
        $user = $this->user();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'A', 'category' => 'app', 'status' => 'closed']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson("/api/support/tickets/{$ticket->id}/messages", [
                'text' => 'پیام جدید',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('ticket_messages', 0);
    }

    public function test_user_cannot_set_status_via_message_payload(): void
    {
        $user = $this->user();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'A', 'category' => 'app', 'status' => 'waiting_patient']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson("/api/support/tickets/{$ticket->id}/messages", [
                'text' => 'پیام جدید',
                'status' => 'closed',
                'author' => 'agent',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('sender', 'user');
        $this->assertSame('in_progress', $ticket->fresh()->status);
    }

    public function test_waiting_patient_status_is_mapped_to_answered_for_patient(): void
    {
        $user = $this->user();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'A', 'category' => 'app', 'status' => 'waiting_patient']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->getJson("/api/support/tickets/{$ticket->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'answered');
    }

    public function test_patient_cannot_see_internal_notes(): void
    {
        $user = $this->user();
        $agent = User::factory()->create(['role' => 'support_agent', 'phone_number' => '09'.fake()->unique()->numerify('#########')]);
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);
        TicketMessage::create(['ticket_id' => $ticket->id, 'author' => 'user', 'author_id' => $user->id, 'text' => 'سلام']);
        TicketMessage::create(['ticket_id' => $ticket->id, 'author' => 'note', 'author_id' => $agent->id, 'text' => 'یادداشت داخلی محرمانه']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->getJson("/api/support/tickets/{$ticket->id}/messages");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $texts = collect($response->json())->pluck('text');
        $this->assertFalse($texts->contains('یادداشت داخلی محرمانه'));
    }

    // --- Authorization ---

    public function test_cannot_view_another_users_ticket(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $response = $this->withHeaders($this->authHeaders($stranger))
            ->getJson("/api/support/tickets/{$ticket->id}");

        $response->assertStatus(403);
    }

    public function test_cannot_view_another_users_ticket_messages(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);
        TicketMessage::create(['ticket_id' => $ticket->id, 'author' => 'user', 'text' => 'hi']);

        $response = $this->withHeaders($this->authHeaders($stranger))
            ->getJson("/api/support/tickets/{$ticket->id}/messages");

        $response->assertStatus(403);
    }

    public function test_cannot_send_message_on_another_users_ticket(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $response = $this->withHeaders($this->authHeaders($stranger))
            ->postJson("/api/support/tickets/{$ticket->id}/messages", ['text' => 'hacked']);

        $response->assertStatus(403);
        $this->assertDatabaseCount('ticket_messages', 0);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/support/tickets');

        $response->assertStatus(401);
    }
}
