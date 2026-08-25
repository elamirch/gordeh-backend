<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminAgentTest extends TestCase
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

    public function test_agents_list_includes_active_ticket_count(): void
    {
        $agent = $this->agent();
        $patient = User::factory()->create(['role' => 'user', 'phone_number' => '09'.fake()->unique()->numerify('#########')]);
        Ticket::create(['user_id' => $patient->id, 'subject' => 'A', 'category' => 'app', 'status' => 'open', 'agent_id' => $agent->id]);
        Ticket::create(['user_id' => $patient->id, 'subject' => 'B', 'category' => 'app', 'status' => 'closed', 'agent_id' => $agent->id]);

        $response = $this->withHeaders($this->authHeaders($agent))->getJson('/api/admin/agents');

        $response->assertStatus(200);
        $row = collect($response->json())->firstWhere('id', $agent->id);
        $this->assertSame(1, $row['activeTicketCount']);
    }

    public function test_normal_user_cannot_list_agents(): void
    {
        $user = User::factory()->create(['role' => 'user', 'phone_number' => '09'.fake()->unique()->numerify('#########')]);

        $response = $this->withHeaders($this->authHeaders($user))->getJson('/api/admin/agents');

        $response->assertStatus(403);
    }

    public function test_shift_grid_covers_full_week_and_can_be_assigned(): void
    {
        $agent = $this->agent();

        $empty = $this->withHeaders($this->authHeaders($agent))->getJson('/api/admin/agents/shifts');
        $empty->assertStatus(200);
        $empty->assertJsonCount(21); // 7 weekdays x 3 windows

        $assign = $this->withHeaders($this->authHeaders($agent))
            ->patchJson('/api/admin/agents/shifts', [
                'agentId' => $agent->id,
                'weekday' => 0,
                'window' => 'morning',
                'assigned' => true,
            ]);
        $assign->assertStatus(200);

        $cell = collect($assign->json())->first(fn ($c) => $c['weekday'] === 0 && $c['window'] === 'morning');
        $this->assertCount(1, $cell['agents']);
        $this->assertSame($agent->id, $cell['agents'][0]['id']);

        $unassign = $this->withHeaders($this->authHeaders($agent))
            ->patchJson('/api/admin/agents/shifts', [
                'agentId' => $agent->id,
                'weekday' => 0,
                'window' => 'morning',
                'assigned' => false,
            ]);
        $cell = collect($unassign->json())->first(fn ($c) => $c['weekday'] === 0 && $c['window'] === 'morning');
        $this->assertCount(0, $cell['agents']);
    }
}
