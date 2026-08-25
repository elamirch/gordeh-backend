<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminSupportReportTest extends TestCase
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

    private function agent(): User
    {
        return User::factory()->create([
            'role' => 'support_agent',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    private function patient(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    public function test_kpis_and_topics_reflect_seeded_tickets(): void
    {
        $patient = $this->patient();
        $agentUser = $this->agent();

        $open = Ticket::create(['user_id' => $patient->id, 'subject' => 'A', 'category' => 'payment', 'status' => 'open']);
        $closed = Ticket::create(['user_id' => $patient->id, 'subject' => 'B', 'category' => 'payment', 'status' => 'closed']);
        Ticket::create(['user_id' => $patient->id, 'subject' => 'C', 'category' => 'app', 'status' => 'open']);

        TicketMessage::create([
            'ticket_id' => $closed->id, 'author' => 'agent', 'author_id' => $agentUser->id, 'text' => 'reply',
        ]);

        $response = $this->withHeaders($this->authHeaders($agentUser))->getJson('/api/admin/support/reports');

        $response->assertStatus(200);
        $response->assertJsonPath('kpis.totalTickets', 3);
        $response->assertJsonPath('kpis.openTickets', 2);
        $response->assertJsonPath('kpis.resolvedTickets', 1);
        $response->assertJsonCount(14, 'daily');

        $topics = collect($response->json('topics'));
        $paymentTopic = $topics->firstWhere('category', 'payment');
        $this->assertSame(2, $paymentTopic['count']);
    }

    public function test_normal_user_cannot_view_reports(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->patient()))->getJson('/api/admin/support/reports');

        $response->assertStatus(403);
    }
}
