<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SupportChatTest extends TestCase
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

    public function test_first_message_creates_a_new_session(): void
    {
        $user = $this->user();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/chat/messages', [
                'text' => 'سلام، راهنمایی می‌خواستم',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('sender', 'user');
        $response->assertJsonPath('text', 'سلام، راهنمایی می‌خواستم');
        $response->assertJsonStructure(['id', 'sessionId', 'sender', 'text', 'fileUrl', 'createdAt']);

        $this->assertDatabaseCount('chat_sessions', 1);
        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertSame($user->id, ChatSession::first()->user_id);
    }

    public function test_second_message_reuses_existing_session(): void
    {
        $user = $this->user();
        $session = ChatSession::create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/chat/messages', [
                'sessionId' => $session->id,
                'text' => 'پیام دوم',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('sessionId', (string) $session->id);

        $this->assertDatabaseCount('chat_sessions', 1);
        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_cannot_send_message_to_another_users_session(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $session = ChatSession::create(['user_id' => $owner->id, 'status' => 'active']);

        $response = $this->withHeaders($this->authHeaders($stranger))
            ->postJson('/api/support/chat/messages', [
                'sessionId' => $session->id,
                'text' => 'hacked',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_unknown_session_id_returns_404(): void
    {
        $user = $this->user();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/support/chat/messages', [
                'sessionId' => 999999,
                'text' => 'hello',
            ]);

        $response->assertStatus(404);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/support/chat/messages', ['text' => 'hi']);

        $response->assertStatus(401);
    }
}
