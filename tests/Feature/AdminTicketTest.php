<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminTicketTest extends TestCase
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

    private function patient(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    private function agent(string $role = 'support_agent'): User
    {
        return User::factory()->create([
            'role' => $role,
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    // --- Shared Support: patient creates, admin sees, admin replies, patient sees ---

    public function test_ticket_created_by_patient_is_visible_to_admin(): void
    {
        $patient = $this->patient();
        $agent = $this->agent();

        $this->withHeaders($this->authHeaders($patient))->postJson('/api/support/tickets', [
            'category' => 'payment',
            'subject' => 'مشکل در پرداخت',
            'description' => 'توضیحات',
        ]);
        $ticket = Ticket::first();

        $response = $this->withHeaders($this->authHeaders($agent))
            ->getJson("/api/admin/tickets/{$ticket->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('subject', 'مشکل در پرداخت');
        $response->assertJsonPath('patient.id', $patient->id);
        $response->assertJsonCount(1, 'messages');
    }

    public function test_admin_reply_is_visible_to_patient(): void
    {
        $patient = $this->patient();
        $agent = $this->agent();
        $ticket = Ticket::create(['user_id' => $patient->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $reply = $this->withHeaders($this->authHeaders($agent))
            ->postJson("/api/admin/tickets/{$ticket->id}/messages", [
                'text' => 'پاسخ پشتیبانی',
                'type' => 'reply',
            ]);

        $reply->assertStatus(201);
        $reply->assertJsonPath('author', 'agent');
        $this->assertSame('waiting_patient', $ticket->fresh()->status);

        $patientView = $this->withHeaders($this->authHeaders($patient))
            ->getJson("/api/support/tickets/{$ticket->id}/messages");

        $patientView->assertStatus(200);
        $patientView->assertJsonPath('0.sender', 'support');
        $patientView->assertJsonPath('0.text', 'پاسخ پشتیبانی');
    }

    public function test_admin_internal_note_is_not_visible_to_patient(): void
    {
        $patient = $this->patient();
        $agent = $this->agent();
        $ticket = Ticket::create(['user_id' => $patient->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $note = $this->withHeaders($this->authHeaders($agent))
            ->postJson("/api/admin/tickets/{$ticket->id}/messages", [
                'text' => 'یادداشت داخلی',
                'type' => 'note',
            ]);

        $note->assertStatus(201);
        $note->assertJsonPath('author', 'note');
        // a note must never flip the ticket into "waiting on patient"
        $this->assertSame('open', $ticket->fresh()->status);

        $patientView = $this->withHeaders($this->authHeaders($patient))
            ->getJson("/api/support/tickets/{$ticket->id}/messages");

        $patientView->assertStatus(200);
        $patientView->assertJsonCount(0);
    }

    public function test_note_cannot_forge_author_or_author_id(): void
    {
        $patient = $this->patient();
        $agent = $this->agent();
        $ticket = Ticket::create(['user_id' => $patient->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $this->withHeaders($this->authHeaders($agent))
            ->postJson("/api/admin/tickets/{$ticket->id}/messages", [
                'text' => 'x',
                'type' => 'reply',
                'author' => 'note',
                'authorId' => 999999,
            ]);

        $message = TicketMessage::first();
        $this->assertSame('agent', $message->author);
        $this->assertSame($agent->id, $message->author_id);
    }

    // --- Ticket lifecycle ---

    public function test_ticket_lifecycle_open_to_closed(): void
    {
        $patient = $this->patient();
        $agent = $this->agent();
        $ticket = Ticket::create(['user_id' => $patient->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/tickets/{$ticket->id}", ['agentId' => $agent->id]);
        $this->assertSame('in_progress', $ticket->fresh()->status);

        $this->withHeaders($this->authHeaders($agent))
            ->postJson("/api/admin/tickets/{$ticket->id}/messages", ['text' => 'پاسخ', 'type' => 'reply']);
        $this->assertSame('waiting_patient', $ticket->fresh()->status);

        $this->withHeaders($this->authHeaders($patient))
            ->postJson("/api/support/tickets/{$ticket->id}/messages", ['text' => 'ادامه']);
        $this->assertSame('in_progress', $ticket->fresh()->status);

        $close = $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/tickets/{$ticket->id}", ['status' => 'closed']);
        $close->assertStatus(200);
        $this->assertSame('closed', $ticket->fresh()->status);
    }

    // --- PATCH validation / authorization ---

    public function test_patch_ignores_unlisted_fields(): void
    {
        $patient = $this->patient();
        $otherPatient = $this->patient();
        $agent = $this->agent();
        $ticket = Ticket::create(['user_id' => $patient->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);
        $originalCreatedAt = $ticket->created_at;

        $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/tickets/{$ticket->id}", [
                'priority' => 'urgent',
                'patientId' => $otherPatient->id,
                'createdAt' => '2000-01-01T00:00:00Z',
            ]);

        $fresh = $ticket->fresh();
        $this->assertSame('urgent', $fresh->priority);
        $this->assertSame($patient->id, $fresh->user_id);
        $this->assertTrue($fresh->created_at->equalTo($originalCreatedAt));
    }

    public function test_patch_rejects_invalid_status(): void
    {
        $agent = $this->agent();
        $ticket = Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $response = $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/tickets/{$ticket->id}", ['status' => 'resolved']);

        $response->assertStatus(422);
    }

    public function test_urgent_priority_recomputes_a_tighter_sla_deadline(): void
    {
        $agent = $this->agent();
        $ticket = Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/tickets/{$ticket->id}", ['priority' => 'urgent']);

        $fresh = $ticket->fresh();
        $this->assertNotNull($fresh->sla_deadline);
        // urgent = 30 minutes from creation, per TicketService::SLA_HOURS
        $this->assertTrue($fresh->sla_deadline->equalTo($fresh->created_at->copy()->addMinutes(30)));
    }

    // --- Authorization: normal user cannot access admin APIs ---

    public function test_normal_user_cannot_list_admin_tickets(): void
    {
        $user = $this->patient();

        $response = $this->withHeaders($this->authHeaders($user))->getJson('/api/admin/tickets');

        $response->assertStatus(403);
    }

    public function test_normal_user_cannot_reply_as_agent(): void
    {
        $user = $this->patient();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson("/api/admin/tickets/{$ticket->id}/messages", ['text' => 'x', 'type' => 'reply']);

        $response->assertStatus(403);
    }

    public function test_admin_role_can_also_access_support_admin_apis(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'phone_number' => '09'.fake()->unique()->numerify('#########')]);

        $response = $this->withHeaders($this->authHeaders($admin))->getJson('/api/admin/tickets');

        $response->assertStatus(200);
    }

    // --- List filters / pagination / queue summary ---

    public function test_index_supports_filters_and_pagination(): void
    {
        $agent = $this->agent();
        Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'urgent one', 'category' => 'app', 'status' => 'open', 'priority' => 'urgent']);
        Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'normal one', 'category' => 'app', 'status' => 'closed', 'priority' => 'normal']);

        $response = $this->withHeaders($this->authHeaders($agent))
            ->getJson('/api/admin/tickets?priority=urgent');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.subject', 'urgent one');
    }

    public function test_queue_summary_counts_are_correct(): void
    {
        $agent = $this->agent();
        Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open']);
        Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'B', 'category' => 'app', 'status' => 'in_progress']);
        Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'C', 'category' => 'app', 'status' => 'waiting_patient']);
        Ticket::create(['user_id' => $this->patient()->id, 'subject' => 'D', 'category' => 'app', 'status' => 'open', 'priority' => 'urgent']);
        Ticket::create([
            'user_id' => $this->patient()->id, 'subject' => 'E', 'category' => 'app',
            'status' => 'open', 'sla_deadline' => now()->subHour(),
        ]);

        $response = $this->withHeaders($this->authHeaders($agent))->getJson('/api/admin/tickets/queue-summary');

        $response->assertStatus(200);
        $response->assertJsonPath('openCount', 3);
        $response->assertJsonPath('inProgressCount', 1);
        $response->assertJsonPath('waitingPatientCount', 1);
        $response->assertJsonPath('pastSlaCount', 1);
        $response->assertJsonPath('urgentCount', 1);
    }
}
