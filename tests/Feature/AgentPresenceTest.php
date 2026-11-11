<?php

namespace Tests\Feature;

use App\Events\AgentPresenceChanged;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithSupportChat;
use Tests\TestCase;

class AgentPresenceTest extends TestCase
{
    use InteractsWithSupportChat;
    use RefreshDatabase;

    private function setState(User $agent, string $state)
    {
        return $this->asMember($agent)->patchJson('/api/admin/agents/me/state', ['state' => $state]);
    }

    private function widgetOnline(): bool
    {
        return $this->getJson('/api/support/chat/config')->assertOk()->json('online');
    }

    // --- PATCH /admin/agents/me/state ---

    public function test_an_agent_can_set_each_of_the_four_states(): void
    {
        $agent = $this->agent('offline', ['support_state_at' => null]);

        foreach (['online', 'in_call', 'break', 'offline'] as $state) {
            $this->setState($agent, $state)
                ->assertOk()
                ->assertExactJson(['id' => $agent->id, 'state' => $state]);

            $agent->refresh();
            $this->assertSame($state, $agent->support_state);
            $this->assertNotNull($agent->support_state_at);
        }
    }

    public function test_admins_can_use_it_too_and_only_their_own_state_changes(): void
    {
        $admin = $this->member(['role' => 'admin']);
        $other = $this->agent('break');

        $this->setState($admin, 'online')->assertOk()->assertJsonPath('id', $admin->id);

        $this->assertSame('online', $admin->fresh()->support_state);
        $this->assertSame('break', $other->fresh()->support_state);
    }

    public function test_invalid_or_missing_state_is_a_validation_error(): void
    {
        $agent = $this->agent('break');

        $this->setState($agent, 'sleeping')->assertStatus(422)->assertJsonValidationErrors('state');
        $this->asMember($agent)->patchJson('/api/admin/agents/me/state', [])->assertStatus(422)->assertJsonValidationErrors('state');
        $this->asMember($agent)->patchJson('/api/admin/agents/me/state', ['state' => ['online']])->assertStatus(422);

        $this->assertSame('break', $agent->fresh()->support_state);
    }

    public function test_non_staff_are_forbidden_and_anonymous_requests_are_unauthorized(): void
    {
        $patient = $this->member(['support_state' => 'offline']);

        $this->setState($patient, 'online')->assertForbidden();
        $this->assertSame('offline', $patient->fresh()->support_state);
        $this->assertNull($patient->fresh()->support_state_at);

        $this->flushHeaders();
        $this->patchJson('/api/admin/agents/me/state', ['state' => 'online'])->assertStatus(401);
    }

    public function test_repeating_the_same_state_is_a_heartbeat_that_refreshes_the_timestamp(): void
    {
        $this->duringWorkingHours();
        $agent = $this->agent('offline');
        $this->setState($agent, 'online')->assertOk();
        $first = $agent->fresh()->support_state_at;

        Carbon::setTestNow(now()->addSeconds(60));
        $this->setState($agent, 'online')->assertOk()->assertJsonPath('state', 'online');

        $this->assertTrue($agent->fresh()->support_state_at->gt($first));
    }

    public function test_heartbeats_do_not_touch_users_updated_at(): void
    {
        $this->duringWorkingHours();
        $agent = $this->agent('online');
        $updatedAt = $agent->fresh()->updated_at;

        Carbon::setTestNow(now()->addMinutes(5));
        $this->setState($agent, 'online')->assertOk();

        $this->assertTrue($agent->fresh()->updated_at->equalTo($updatedAt));
    }

    public function test_the_endpoint_is_throttled_but_allows_a_normal_heartbeat_rate(): void
    {
        $agent = $this->agent('online');

        foreach (range(1, 30) as $i) {
            $this->setState($agent, 'online')->assertOk();
        }
        $this->setState($agent, 'online')->assertStatus(429);
    }

    // --- Presence events ---

    public function test_only_real_changes_are_broadcast_not_heartbeats(): void
    {
        $this->duringWorkingHours();
        Event::fake([AgentPresenceChanged::class]);
        $agent = $this->agent('offline');

        $this->setState($agent, 'online');
        $this->setState($agent, 'online'); // heartbeat
        $this->setState($agent, 'online'); // heartbeat
        Event::assertDispatchedTimes(AgentPresenceChanged::class, 1);

        $this->setState($agent, 'break');
        Event::assertDispatchedTimes(AgentPresenceChanged::class, 2);

        Event::assertDispatched(AgentPresenceChanged::class, fn (AgentPresenceChanged $e) => $e->broadcastWith() === ['agentId' => $agent->id, 'state' => 'break']
            && $e->broadcastAs() === 'AgentPresenceChanged'
            && collect($e->broadcastOn())->map->name->contains('private-support.agents'));
    }

    public function test_coming_back_after_the_heartbeat_lapsed_counts_as_a_change(): void
    {
        $this->duringWorkingHours();
        $agent = $this->agent('online');
        Carbon::setTestNow(now()->addMinutes(10)); // long expired: effectively offline
        Event::fake([AgentPresenceChanged::class]);

        $this->setState($agent, 'online');

        Event::assertDispatched(AgentPresenceChanged::class, fn ($e) => $e->state === 'online');
    }

    // --- Presence expires ---

    public function test_online_without_a_heartbeat_past_the_ttl_makes_the_widget_offline(): void
    {
        $this->duringWorkingHours();
        $agent = $this->agent('offline');
        $this->setState($agent, 'online');
        $start = now();

        $this->assertTrue($this->widgetOnline());

        Carbon::setTestNow($start->copy()->addSeconds(119));
        $this->assertTrue($this->widgetOnline(), 'still within the 120 s TTL');

        Carbon::setTestNow($start->copy()->addSeconds(121));
        $this->assertFalse($this->widgetOnline(), 'no heartbeat for longer than the TTL');
    }

    public function test_a_heartbeat_keeps_the_widget_online_and_revives_an_expired_agent(): void
    {
        $this->duringWorkingHours();
        $agent = $this->agent('offline');
        $this->setState($agent, 'online');
        $start = now();

        Carbon::setTestNow($start->copy()->addSeconds(100));
        $this->setState($agent, 'online');                // heartbeat
        Carbon::setTestNow($start->copy()->addSeconds(200)); // 100 s after it
        $this->assertTrue($this->widgetOnline());

        Carbon::setTestNow($start->copy()->addSeconds(400)); // long silence
        $this->assertFalse($this->widgetOnline());
        $this->setState($agent, 'online');
        $this->assertTrue($this->widgetOnline());
    }

    public function test_the_ttl_is_configurable(): void
    {
        $this->duringWorkingHours();
        config(['support.chat.presence_ttl_seconds' => 10]);
        $agent = $this->agent('offline');
        $this->setState($agent, 'online');

        Carbon::setTestNow(now()->addSeconds(11));
        $this->assertFalse($this->widgetOnline());
    }

    public function test_a_legacy_online_row_without_a_timestamp_does_not_count(): void
    {
        $this->duringWorkingHours();
        $this->agent('online', ['support_state_at' => null]);

        $this->assertFalse($this->widgetOnline());
    }

    public function test_only_the_online_state_makes_the_widget_online(): void
    {
        $this->duringWorkingHours();
        $agent = $this->agent('offline');

        foreach (['in_call', 'break', 'offline'] as $state) {
            $this->setState($agent, $state);
            $this->assertFalse($this->widgetOnline(), $state);
        }

        $this->setState($agent, 'online');
        $this->assertTrue($this->widgetOnline());
    }

    public function test_online_outside_working_hours_is_still_offline_for_the_widget(): void
    {
        $this->outsideWorkingHours();
        $this->setState($this->agent('offline'), 'online');

        $this->assertFalse($this->widgetOnline());
    }

    // --- GET /admin/agents ---

    public function test_the_agent_list_reports_effective_state_and_when_it_was_set(): void
    {
        $this->duringWorkingHours();
        $viewer = $this->agent('online');
        $live = $this->agent('online');
        $lapsed = $this->agent('online', ['support_state_at' => now()->subMinutes(5)]);
        $onBreak = $this->agent('break', ['support_state_at' => now()->subHours(2)]);
        $never = $this->agent('offline', ['support_state_at' => null]);

        $rows = collect($this->asMember($viewer)->getJson('/api/admin/agents')->assertOk()->json())->keyBy('id');

        $this->assertSame('online', $rows[$live->id]['state']);
        $this->assertSame(now()->toIso8601String(), $rows[$live->id]['stateAt']);

        // A stored `online` whose heartbeat lapsed reads as offline — but we still say when we last heard.
        $this->assertSame('offline', $rows[$lapsed->id]['state']);
        $this->assertSame(now()->subMinutes(5)->toIso8601String(), $rows[$lapsed->id]['stateAt']);

        // Other states have no heartbeat, so they are reported as set.
        $this->assertSame('break', $rows[$onBreak->id]['state']);
        $this->assertSame('offline', $rows[$never->id]['state']);
        $this->assertNull($rows[$never->id]['stateAt']);
    }

    // --- Logout ---

    public function test_logging_out_takes_a_staff_agent_offline(): void
    {
        $this->duringWorkingHours();
        Event::fake([AgentPresenceChanged::class]);
        $agent = $this->agent('online');
        $this->assertTrue($this->widgetOnline());

        $this->asMember($agent)->postJson('/api/auth/logout')->assertOk();

        $agent->refresh();
        $this->assertSame('offline', $agent->support_state);
        $this->assertFalse($this->widgetOnline());
        Event::assertDispatched(AgentPresenceChanged::class, fn ($e) => $e->agentId === $agent->id && $e->state === 'offline');
    }

    public function test_logging_out_of_all_devices_takes_a_staff_agent_offline(): void
    {
        $this->duringWorkingHours();
        $agent = $this->agent('online');
        $this->assertTrue($this->widgetOnline());

        $this->asMember($agent)->postJson('/api/auth/logout-all')->assertOk();

        $this->assertSame('offline', $agent->fresh()->support_state);
        $this->assertFalse($this->widgetOnline());
    }

    public function test_logging_out_leaves_other_agents_and_patients_alone(): void
    {
        $this->duringWorkingHours();
        $stays = $this->agent('online');
        $leaves = $this->agent('online');
        $patient = $this->member();

        $this->asMember($leaves)->postJson('/api/auth/logout')->assertOk();
        $this->asMember($patient)->postJson('/api/auth/logout')->assertOk();

        $this->assertSame('online', $stays->fresh()->support_state);
        $this->assertSame('offline', $leaves->fresh()->support_state);
        $this->assertTrue($this->widgetOnline(), 'one agent is still online');
    }
}
