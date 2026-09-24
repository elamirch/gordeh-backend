<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SupportCallbackTest extends TestCase
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

    public function test_user_can_request_callback(): void
    {
        $user = $this->user();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/callback-requests', [
                'phone' => '09121234567',
                'slot' => 'morning',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('phone', '09121234567');
        $response->assertJsonPath('slot', 'morning');
        $response->assertJsonPath('status', 'now');

        $this->assertDatabaseCount('callback_requests', 1);
        $this->assertDatabaseHas('callback_requests', ['user_id' => $user->id, 'phone' => '09121234567']);
    }

    public function test_rejects_invalid_phone(): void
    {
        $user = $this->user();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/callback-requests', [
                'phone' => '12345',
                'slot' => 'morning',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('callback_requests', 0);
    }

    public function test_rejects_invalid_slot(): void
    {
        $user = $this->user();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/callback-requests', [
                'phone' => '09121234567',
                'slot' => 'midnight',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('callback_requests', 0);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/support/callback-requests', [
            'phone' => '09121234567',
            'slot' => 'morning',
        ]);

        $response->assertStatus(401);
    }
}
