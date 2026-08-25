<?php

namespace Tests\Feature;

use App\Models\CallbackRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminCallbackTest extends TestCase
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

    private function agent(): User
    {
        return User::factory()->create([
            'role' => 'support_agent',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    public function test_patient_callback_is_visible_to_admin(): void
    {
        $patient = $this->patient();
        $agent = $this->agent();

        $this->withHeaders($this->authHeaders($patient))->postJson('/api/support/callback-requests', [
            'phone' => '09121234567',
            'slot' => 'morning',
        ]);

        $response = $this->withHeaders($this->authHeaders($agent))->getJson('/api/admin/callback-requests');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.window', 'morning');
        $response->assertJsonPath('data.0.state', 'now');
    }

    public function test_admin_can_change_callback_state(): void
    {
        $agent = $this->agent();
        $callback = CallbackRequest::create(['user_id' => $this->patient()->id, 'phone' => '09121234567', 'slot' => 'noon', 'status' => 'now']);

        $response = $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/callback-requests/{$callback->id}", ['state' => 'done']);

        $response->assertStatus(200);
        $response->assertJsonPath('state', 'done');
        $this->assertSame('done', $callback->fresh()->status);
    }

    public function test_rejects_invalid_state(): void
    {
        $agent = $this->agent();
        $callback = CallbackRequest::create(['user_id' => $this->patient()->id, 'phone' => '09121234567', 'slot' => 'noon', 'status' => 'now']);

        $response = $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/callback-requests/{$callback->id}", ['state' => 'bogus']);

        $response->assertStatus(422);
    }

    public function test_normal_user_cannot_access_admin_callbacks(): void
    {
        $user = $this->patient();

        $response = $this->withHeaders($this->authHeaders($user))->getJson('/api/admin/callback-requests');

        $response->assertStatus(403);
    }
}
