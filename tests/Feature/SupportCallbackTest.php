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

    public function test_guest_can_request_callback_from_the_chat_widget(): void
    {
        $response = $this->postJson('/api/support/callback-requests', [
            'phone' => '09121234567',
            'slot' => 'evening',
            'name' => 'سارا',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('slot', 'evening');

        $this->assertDatabaseHas('callback_requests', [
            'user_id' => null,
            'name' => 'سارا',
            'phone' => '09121234567',
            'source' => 'chat_widget',
        ]);
    }

    public function test_guest_request_still_validates_the_phone(): void
    {
        $this->postJson('/api/support/callback-requests', ['phone' => '12345', 'slot' => 'morning'])
            ->assertStatus(422);

        $this->assertDatabaseCount('callback_requests', 0);
    }

    public function test_member_request_defaults_to_support_page_source_and_can_label_the_widget(): void
    {
        $user = $this->user();

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/callback-requests', ['phone' => '09121234567', 'slot' => 'morning'])
            ->assertStatus(201);
        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/callback-requests', ['phone' => '09121234567', 'slot' => 'noon', 'source' => 'chat_widget'])
            ->assertStatus(201);

        $this->assertSame(
            ['support_page', 'chat_widget'],
            \App\Models\CallbackRequest::orderBy('id')->pluck('source')->all()
        );
    }

    public function test_invalid_token_is_rejected(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->postJson('/api/support/callback-requests', [
                'phone' => '09121234567',
                'slot' => 'morning',
            ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('callback_requests', 0);
    }

    public function test_a_guest_request_shows_up_for_agents_under_the_name_they_gave(): void
    {
        $this->postJson('/api/support/callback-requests', ['phone' => '09121234567', 'slot' => 'noon', 'name' => 'سارا'])
            ->assertStatus(201);
        $member = $this->user();
        $this->withHeaders($this->authHeaders($member))
            ->postJson('/api/support/callback-requests', ['phone' => '09127654321', 'slot' => 'morning'])
            ->assertStatus(201);

        $agent = User::factory()->create(['role' => 'support_agent', 'phone_number' => '09'.fake()->unique()->numerify('#########')]);
        $rows = collect($this->withHeaders($this->authHeaders($agent))->getJson('/api/admin/callback-requests')
            ->assertOk()->json('data'))->keyBy('phone');

        $this->assertNull($rows['09121234567']['patientId']);
        $this->assertSame('سارا', $rows['09121234567']['patientName']);
        $this->assertSame('chat_widget', $rows['09121234567']['source']);
        $this->assertSame($member->first_name, $rows['09127654321']['patientName']);
        $this->assertSame('support_page', $rows['09127654321']['source']);
    }

    public function test_the_separate_offline_message_endpoint_is_gone(): void
    {
        $this->postJson('/api/support/chat/offline-messages', ['phone' => '09121234567', 'message' => 'سلام'])
            ->assertStatus(404);

        $this->assertDatabaseCount('callback_requests', 0);
    }
}
