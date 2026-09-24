<?php

namespace Tests\Feature;

use App\Events\ChatSessionStatusChanged;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminChatSessionTest extends TestCase
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

    // --- Creation default ---

    public function test_new_chat_session_defaults_to_waiting(): void
    {
        $patient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/support/chat/messages', ['text' => 'سلام']);

        $response->assertStatus(201);
        $session = ChatSession::find((int) $response->json('sessionId'));
        $this->assertSame('waiting', $session->status);
    }

    // --- Automatic transitions ---

    public function test_agent_reply_on_waiting_session_transitions_to_active(): void
    {
        $patient = $this->patient();
        $agent = $this->agent();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'waiting']);

        $this->withHeaders($this->authHeaders($agent))
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'سلام، بفرمایید']);

        $this->assertSame('active', $session->fresh()->status);
    }

    public function test_agent_reply_on_closed_session_transitions_to_active(): void
    {
        $patient = $this->patient();
        $agent = $this->agent();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'closed']);

        $this->withHeaders($this->authHeaders($agent))
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'ادامه می‌دهیم']);

        $this->assertSame('active', $session->fresh()->status);
    }

    public function test_agent_reply_on_already_active_session_does_not_redispatch_status_event(): void
    {
        Event::fake([ChatSessionStatusChanged::class]);
        $patient = $this->patient();
        $agent = $this->agent();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'active']);

        $this->withHeaders($this->authHeaders($agent))
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'ادامه']);

        $this->assertSame('active', $session->fresh()->status);
        Event::assertNotDispatched(ChatSessionStatusChanged::class);
    }

    public function test_patient_message_on_closed_session_transitions_to_waiting(): void
    {
        $patient = $this->patient();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'closed']);

        $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'کسی هست؟']);

        $this->assertSame('waiting', $session->fresh()->status);
    }

    public function test_patient_message_on_waiting_session_does_not_change_status(): void
    {
        $patient = $this->patient();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'waiting']);

        $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'یادت نره']);

        $this->assertSame('waiting', $session->fresh()->status);
    }

    public function test_patient_message_on_active_session_does_not_change_status(): void
    {
        $patient = $this->patient();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'active']);

        $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'ممنون']);

        $this->assertSame('active', $session->fresh()->status);
    }

    // --- Explicit PATCH endpoint ---

    public function test_agent_can_close_a_session(): void
    {
        $agent = $this->agent();
        $session = ChatSession::create(['user_id' => $this->patient()->id, 'status' => 'active']);

        $response = $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/chat/sessions/{$session->id}", ['status' => 'closed']);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'closed');
        $response->assertJsonPath('sessionId', $session->id);
        $this->assertSame('closed', $session->fresh()->status);
    }

    public function test_agent_can_reopen_a_closed_session_to_waiting(): void
    {
        $agent = $this->agent();
        $session = ChatSession::create(['user_id' => $this->patient()->id, 'status' => 'closed']);

        $response = $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/chat/sessions/{$session->id}", ['status' => 'waiting']);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'waiting');
    }

    public function test_patch_rejects_invalid_status(): void
    {
        $agent = $this->agent();
        $session = ChatSession::create(['user_id' => $this->patient()->id, 'status' => 'active']);

        $response = $this->withHeaders($this->authHeaders($agent))
            ->patchJson("/api/admin/chat/sessions/{$session->id}", ['status' => 'archived']);

        $response->assertStatus(422);
    }

    public function test_patch_returns_404_for_unknown_session(): void
    {
        $agent = $this->agent();

        $response = $this->withHeaders($this->authHeaders($agent))
            ->patchJson('/api/admin/chat/sessions/999999', ['status' => 'closed']);

        $response->assertStatus(404);
    }

    public function test_normal_user_cannot_patch_session_status(): void
    {
        $patient = $this->patient();
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'active']);

        $response = $this->withHeaders($this->authHeaders($patient))
            ->patchJson("/api/admin/chat/sessions/{$session->id}", ['status' => 'closed']);

        $response->assertStatus(403);
    }

    // --- GET /admin/chat/sessions ---

    public function test_sessions_list_includes_last_message_and_is_sorted_by_updated_at(): void
    {
        $agent = $this->agent();
        $patient1 = $this->patient();
        $patient2 = $this->patient();

        $older = ChatSession::create(['user_id' => $patient1->id, 'status' => 'waiting']);
        ChatMessage::create(['chat_session_id' => $older->id, 'sender' => 'user', 'text' => 'اول']);
        $older->forceFill(['updated_at' => now()->subMinute()])->save();

        $newer = ChatSession::create(['user_id' => $patient2->id, 'status' => 'active']);
        ChatMessage::create(['chat_session_id' => $newer->id, 'sender' => 'support', 'text' => 'دوم']);
        $newer->forceFill(['updated_at' => now()])->save();

        $response = $this->withHeaders($this->authHeaders($agent))->getJson('/api/admin/chat/sessions');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.sessionId', $newer->id);
        $response->assertJsonPath('data.0.lastMessage.text', 'دوم');
        $response->assertJsonPath('data.0.lastMessage.sender', 'support');
        $response->assertJsonPath('data.1.sessionId', $older->id);
    }

    public function test_sessions_list_handles_null_last_message_without_crashing(): void
    {
        $agent = $this->agent();
        ChatSession::create(['user_id' => $this->patient()->id, 'status' => 'waiting']);

        $response = $this->withHeaders($this->authHeaders($agent))->getJson('/api/admin/chat/sessions');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.lastMessage', null);
    }

    public function test_sessions_list_filters_by_status(): void
    {
        $agent = $this->agent();
        ChatSession::create(['user_id' => $this->patient()->id, 'status' => 'waiting']);
        ChatSession::create(['user_id' => $this->patient()->id, 'status' => 'closed']);

        $response = $this->withHeaders($this->authHeaders($agent))
            ->getJson('/api/admin/chat/sessions?status=closed');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.status', 'closed');
    }

    public function test_normal_user_cannot_list_sessions(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->patient()))
            ->getJson('/api/admin/chat/sessions');

        $response->assertStatus(403);
    }
}
