<?php

namespace Tests\Feature;

use App\Events\ChatAgentTyping;
use App\Events\ChatMessageSent;
use App\Events\ChatMessagesRead;
use App\Events\ChatSessionStatusChanged;
use App\Events\ChatSessionUpdated;
use App\Events\NewChatSessionStarted;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithSupportChat;
use Tests\TestCase;

class SupportChatWidgetTest extends TestCase
{
    use InteractsWithSupportChat;
    use RefreshDatabase;

    // --- GET /support/chat/config ---

    public function test_config_is_public_and_online_during_hours_with_an_online_agent(): void
    {
        $this->goOnline();

        $this->getJson('/api/support/chat/config')
            ->assertOk()
            ->assertExactJson([
                'online' => true,
                'workingHours' => ['from' => '09:00', 'to' => '21:00'],
                'estimatedWaitMinutes' => 2, // nobody waiting: position 1 / 1 agent * 2 min
            ]);
    }

    public function test_config_is_offline_outside_working_hours_even_with_an_online_agent(): void
    {
        $this->outsideWorkingHours();
        $this->agent('online');

        $this->getJson('/api/support/chat/config')->assertOk()->assertJsonPath('online', false);
    }

    public function test_config_is_offline_when_no_agent_is_online(): void
    {
        $this->duringWorkingHours();
        $this->agent('break');
        $this->agent('offline');
        $this->member(['support_state' => 'online']); // a patient is never an agent

        $this->getJson('/api/support/chat/config')->assertOk()->assertJsonPath('online', false);
    }

    public function test_config_working_hours_use_the_tehran_clock_and_include_the_opening_minute(): void
    {
        $this->agent('online');

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-13 09:00:00', 'Asia/Tehran')->utc());
        $this->getJson('/api/support/chat/config')->assertJsonPath('online', true);

        // 21:00 Tehran is the (exclusive) end of the window. 17:30 UTC == 21:00 Tehran (+03:30).
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-13 17:30:00', 'UTC'));
        $this->getJson('/api/support/chat/config')->assertJsonPath('online', false);
    }

    public function test_config_wait_estimate_scales_with_queue_and_online_agents(): void
    {
        $this->goOnline();
        foreach (range(1, 4) as $i) {
            $this->waitingSession();
        }

        // 5th in line / 1 agent * 2 min
        $this->getJson('/api/support/chat/config')->assertJsonPath('estimatedWaitMinutes', 10);

        $this->agent('online');
        // ceil(5 / 2) * 2
        $this->getJson('/api/support/chat/config')->assertJsonPath('estimatedWaitMinutes', 6);
    }

    public function test_config_wait_estimate_is_never_below_one_minute(): void
    {
        $this->goOnline();
        config(['support.chat.avg_handle_minutes' => 0]);

        $this->getJson('/api/support/chat/config')->assertJsonPath('estimatedWaitMinutes', 1);
    }

    public function test_config_allows_the_chat_token_header_in_cors_preflight(): void
    {
        $response = $this->call('OPTIONS', '/api/support/chat/sessions', [], [], [], [
            'HTTP_ORIGIN' => 'https://gordeh.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-chat-token',
        ]);

        $this->assertTrue($response->isSuccessful());
        $allowed = strtolower($response->headers->get('Access-Control-Allow-Headers'));
        $this->assertTrue(str_contains($allowed, 'x-chat-token') || $allowed === '*', $allowed);
    }

    // --- POST /support/chat/sessions ---

    public function test_guest_starts_a_session_and_gets_the_token_exactly_once(): void
    {
        Event::fake([NewChatSessionStarted::class]);
        $this->goOnline();

        $response = $this->postJson('/api/support/chat/sessions', [
            'phone' => '09121234567',
            'name' => 'سارا',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('queuePosition', 1)
            ->assertJsonPath('agent', null)
            ->assertJsonPath('rated', false)
            ->assertJsonStructure(['id', 'status', 'queuePosition', 'estimatedWaitMinutes', 'agent', 'agentTyping',
                'lastReadMessageId', 'lastMessageId', 'guestToken']);

        $token = $response->json('guestToken');
        $this->assertSame(64, strlen($token));

        $session = ChatSession::findOrFail($response->json('id'));
        $this->assertNull($session->user_id);
        $this->assertSame('09121234567', $session->guest_phone);
        $this->assertSame('سارا', $session->guest_name);
        // Only the hash is stored, never the raw token.
        $this->assertSame(hash('sha256', $token), $session->guest_token_hash);
        $this->assertDatabaseMissing('chat_sessions', ['guest_token_hash' => $token]);

        Event::assertDispatched(NewChatSessionStarted::class, function (NewChatSessionStarted $event) {
            $payload = $event->broadcastWith();

            return $payload['isGuest'] === true
                && $payload['patientPhone'] === '09121234567'
                && $payload['patientName'] === 'سارا'
                && $payload['patientId'] === null;
        });

        // ...and a later read of the same session never repeats it.
        $this->asGuest($token)
            ->getJson("/api/support/chat/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonMissingPath('guestToken');
    }

    public function test_guest_without_a_name_shows_up_to_agents_as_mehman(): void
    {
        Event::fake([NewChatSessionStarted::class]);
        $this->goOnline();

        $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567'])->assertCreated();

        Event::assertDispatched(NewChatSessionStarted::class,
            fn (NewChatSessionStarted $e) => $e->broadcastWith()['patientName'] === 'مهمان');
    }

    public function test_every_guest_start_creates_a_new_session(): void
    {
        $this->goOnline();

        $a = $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567'])->assertCreated();
        $b = $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567'])->assertCreated();

        $this->assertNotSame($a->json('id'), $b->json('id'));
        $this->assertNotSame($a->json('guestToken'), $b->json('guestToken'));
        $this->assertDatabaseCount('chat_sessions', 2);
    }

    public function test_guest_phone_is_required_and_validated(): void
    {
        $this->goOnline();

        $this->postJson('/api/support/chat/sessions', [])->assertStatus(422)->assertJsonValidationErrors('phone');
        foreach (['12345', '0912123456', '091212345678', '9121234567', '09a21234567', '09 121234567'] as $bad) {
            $this->postJson('/api/support/chat/sessions', ['phone' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('phone');
        }
        $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567', 'name' => str_repeat('a', 61)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('chat_sessions', 0);
    }

    public function test_member_session_is_idempotent_while_one_is_open(): void
    {
        $this->goOnline();
        $member = $this->member();

        $first = $this->asMember($member)
            ->postJson('/api/support/chat/sessions', [])
            ->assertCreated()
            ->assertJsonMissingPath('guestToken');
        $second = $this->asMember($member)
            ->postJson('/api/support/chat/sessions', [])
            ->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('chat_sessions', 1);
        $this->assertSame($member->id, ChatSession::first()->user_id);
        $this->assertNull(ChatSession::first()->guest_phone);
    }

    public function test_member_gets_a_new_session_once_the_previous_one_is_closed(): void
    {
        $this->goOnline();
        $member = $this->member();
        ChatSession::create(['user_id' => $member->id, 'status' => 'closed']);

        $this->asMember($member)
            ->postJson('/api/support/chat/sessions', [])
            ->assertCreated();

        $this->assertDatabaseCount('chat_sessions', 2);
    }

    public function test_member_session_start_ignores_phone_and_name(): void
    {
        $this->goOnline();
        $member = $this->member();

        $this->asMember($member)
            ->postJson('/api/support/chat/sessions', ['phone' => 'garbage', 'name' => 'x'])
            ->assertCreated();

        $session = ChatSession::first();
        $this->assertSame($member->id, $session->user_id);
        $this->assertNull($session->guest_phone);
        $this->assertNull($session->guest_name);
    }

    public function test_being_offline_does_not_block_starting_a_chat(): void
    {
        $this->outsideWorkingHours();
        $this->agent('online');

        $this->getJson('/api/support/chat/config')->assertJsonPath('online', false);
        $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567', 'message' => 'سلام'])->assertCreated();
        $this->asMember($this->member())->postJson('/api/support/chat/sessions', ['message' => 'سلام'])->assertCreated();

        $this->assertDatabaseCount('chat_sessions', 2);
        $this->assertDatabaseCount('chat_messages', 2);
    }

    public function test_session_start_with_an_invalid_token_is_unauthorized(): void
    {
        $this->goOnline();

        $this->withHeaders(['Authorization' => 'Bearer nonsense'])
            ->postJson('/api/support/chat/sessions', ['phone' => '09121234567'])
            ->assertStatus(401);
    }

    public function test_session_start_is_throttled(): void
    {
        $this->goOnline();

        foreach (range(1, 10) as $i) {
            $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567'])->assertCreated();
        }
        $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567'])->assertStatus(429);
        // Own bucket: the other public endpoints are unaffected.
        $this->getJson('/api/support/chat/config')->assertOk();
    }

    public function test_html_in_the_guest_name_is_stripped(): void
    {
        $this->goOnline();

        $this->postJson('/api/support/chat/sessions', [
            'phone' => '09121234567',
            'name' => '<script>alert(1)</script>سارا',
        ])->assertCreated();

        $this->assertSame('alert(1)سارا', ChatSession::first()->guest_name);
    }

    // --- Starting a chat with a first message (how a visitor reaches us offline) ---

    public function test_guest_can_start_a_chat_with_a_message_outside_working_hours(): void
    {
        Event::fake([NewChatSessionStarted::class, ChatMessageSent::class]);
        $this->outsideWorkingHours();
        $this->agent('online'); // an online agent does not matter outside the window

        $response = $this->postJson('/api/support/chat/sessions', [
            'phone' => '09121234567',
            'name' => 'سارا',
            'message' => 'سلام، شبانه می‌نویسم',
        ])->assertCreated()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('queuePosition', 1)
            ->assertJsonPath('estimatedWaitMinutes', null)
            ->assertJsonPath('rated', false);

        $this->assertSame(64, strlen($response->json('guestToken')));

        $session = ChatSession::findOrFail($response->json('id'));
        $message = ChatMessage::where('chat_session_id', $session->id)->sole();
        $this->assertSame('user', $message->sender);
        $this->assertSame('سلام، شبانه می‌نویسم', $message->text);
        $response->assertJsonPath('lastMessageId', $message->id);

        Event::assertDispatched(NewChatSessionStarted::class, fn ($e) => $e->session->id === $session->id);
        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $e) => $e->message->id === $message->id
            && collect($e->broadcastOn())->map->name->contains("private-chat.{$session->id}"));
    }

    public function test_member_can_start_a_chat_with_a_message_outside_working_hours(): void
    {
        $this->outsideWorkingHours();
        $member = $this->member();

        $this->asMember($member)
            ->postJson('/api/support/chat/sessions', ['message' => 'پیام شبانه'])
            ->assertCreated()
            ->assertJsonPath('estimatedWaitMinutes', null)
            ->assertJsonMissingPath('guestToken');

        $session = ChatSession::sole();
        $this->assertSame($member->id, $session->user_id);
        $this->assertSame('پیام شبانه', ChatMessage::sole()->text);
    }

    public function test_a_members_message_joins_their_open_chat_instead_of_starting_a_second_one(): void
    {
        $this->outsideWorkingHours();
        $member = $this->member();
        $open = ChatSession::create(['user_id' => $member->id, 'status' => 'active']);

        $this->asMember($member)
            ->postJson('/api/support/chat/sessions', ['message' => 'یک چیز دیگر'])
            ->assertOk()
            ->assertJsonPath('id', $open->id);

        $this->assertDatabaseCount('chat_sessions', 1);
        $this->assertSame($open->id, ChatMessage::sole()->chat_session_id);
    }

    public function test_starting_offline_requires_a_message_as_an_ordinary_validation_error(): void
    {
        $this->outsideWorkingHours();

        foreach ([[], ['message' => ''], ['message' => '   '], ['message' => null], ['message' => '<b></b>']] as $extra) {
            $guest = $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567'] + $extra)
                ->assertStatus(422)
                ->assertJsonValidationErrors('message');
            $member = $this->asMember($this->member())->postJson('/api/support/chat/sessions', $extra)
                ->assertStatus(422)
                ->assertJsonValidationErrors('message');

            // No special "offline" signal any more: just the usual validation envelope.
            $this->assertArrayNotHasKey('code', $guest->json());
            $this->assertArrayNotHasKey('code', $member->json());
        }

        $this->assertDatabaseCount('chat_sessions', 0);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_no_message_is_needed_while_online_and_it_is_optional_then(): void
    {
        $this->goOnline();

        $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567'])->assertCreated();
        $this->asMember($this->member())->postJson('/api/support/chat/sessions', [])->assertCreated();

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_the_first_message_and_its_session_are_created_atomically(): void
    {
        $this->outsideWorkingHours();
        ChatMessage::creating(fn () => throw new \RuntimeException('message insert failed'));

        try {
            $this->withoutExceptionHandling();
            $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567', 'message' => 'سلام'])
                ->assertStatus(500);
            $this->fail('the failure should have propagated');
        } catch (\RuntimeException $e) {
            $this->assertSame('message insert failed', $e->getMessage());
        } finally {
            ChatMessage::flushEventListeners();
        }

        // No half-started chat is left behind for agents to see.
        $this->assertDatabaseCount('chat_sessions', 0);
    }

    public function test_offline_start_with_a_message_still_validates_phone_and_message(): void
    {
        $this->outsideWorkingHours();

        $this->postJson('/api/support/chat/sessions', ['message' => 'سلام'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/support/chat/sessions', ['phone' => '12345', 'message' => 'سلام'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567', 'message' => 'سل'])
            ->assertStatus(422)->assertJsonValidationErrors('message');  // 3 characters minimum
        $this->postJson('/api/support/chat/sessions', ['phone' => '09121234567', 'message' => str_repeat('a', 5001)])
            ->assertStatus(422)->assertJsonValidationErrors('message');

        $this->assertDatabaseCount('chat_sessions', 0);
    }

    public function test_a_first_message_is_stored_when_online_too_and_html_is_stripped(): void
    {
        $this->goOnline();

        $response = $this->postJson('/api/support/chat/sessions', [
            'phone' => '09121234567',
            'message' => '<i>سلام</i> وقت بخیر',
        ])->assertCreated()
            ->assertJsonPath('estimatedWaitMinutes', 2);

        $this->assertSame('سلام وقت بخیر', ChatMessage::sole()->text);
        $this->assertSame('waiting', ChatSession::findOrFail($response->json('id'))->status);
    }

    public function test_wait_estimate_is_hidden_from_snapshots_while_nobody_is_answering(): void
    {
        [$session, $token] = $this->guestSession();
        $url = "/api/support/chat/sessions/{$session->id}";

        // Inside hours but nobody online, and outside hours with someone online: no promise either way.
        $this->duringWorkingHours();
        $this->asGuest($token)->getJson($url)->assertJsonPath('queuePosition', 1)->assertJsonPath('estimatedWaitMinutes', null);

        $this->outsideWorkingHours();
        $this->agent('online');
        $this->asGuest($token)->getJson($url)->assertJsonPath('estimatedWaitMinutes', null);

        $this->duringWorkingHours();
        $this->asGuest($token)->getJson($url)->assertJsonPath('estimatedWaitMinutes', 2);
    }

    // --- Guest identity / access control ---

    public function test_guest_reads_snapshot_and_messages_and_sends_a_message_with_their_token(): void
    {
        [$session, $token] = $this->guestSession();
        $headers = $this->guestHeaders($token);

        $sent = $this->withHeaders($headers)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'سلام'])
            ->assertCreated()
            ->assertJsonPath('sender', 'user')
            ->assertJsonStructure(['id', 'sessionId', 'sender', 'text', 'fileUrl', 'createdAt']);

        $this->withHeaders($headers)
            ->getJson("/api/support/chat/messages?sessionId={$session->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.text', 'سلام');

        $this->withHeaders($headers)
            ->getJson("/api/support/chat/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('id', $session->id)
            ->assertJsonPath('lastMessageId', (int) $sent->json('id'));
    }

    public function test_snapshot_and_broadcast_never_contain_the_guest_phone_or_token_hash(): void
    {
        [$session, $token] = $this->guestSession(['agent_id' => $this->agent('online')->id, 'agent_joined_at' => now()]);

        $http = $this->asGuest($token)->getJson("/api/support/chat/sessions/{$session->id}")->assertOk()->getContent();
        $pushed = json_encode((new ChatSessionUpdated($session))->broadcastWith());

        foreach ([$http, $pushed] as $payload) {
            $this->assertStringNotContainsString('09121234567', $payload);
            $this->assertStringNotContainsString($session->guest_token_hash, $payload);
            $this->assertStringNotContainsString($token, $payload);
        }
        $this->assertSame(json_decode($http, true), json_decode($pushed, true));
        $this->assertArrayNotHasKey('guest_phone', $session->toArray());
        $this->assertArrayNotHasKey('guest_token_hash', $session->toArray());
    }

    public function test_guest_token_of_another_session_is_forbidden_everywhere(): void
    {
        [$mine, $myToken] = $this->guestSession();
        [$theirs] = $this->guestSession();
        $headers = $this->guestHeaders($myToken);

        $this->withHeaders($headers)->getJson("/api/support/chat/sessions/{$theirs->id}")->assertForbidden();
        $this->withHeaders($headers)->getJson("/api/support/chat/messages?sessionId={$theirs->id}")->assertForbidden();
        $this->withHeaders($headers)
            ->postJson('/api/support/chat/messages', ['sessionId' => $theirs->id, 'text' => 'x'])
            ->assertForbidden();
        $this->withHeaders($headers)->postJson("/api/support/chat/sessions/{$theirs->id}/end")->assertForbidden();
        $this->withHeaders($headers)
            ->postJson("/api/support/chat/sessions/{$theirs->id}/rating", ['rating' => 'good'])
            ->assertForbidden();

        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertSame('waiting', $theirs->fresh()->status);
    }

    public function test_requests_without_any_identity_are_forbidden_for_a_given_session(): void
    {
        [$session] = $this->guestSession();

        $this->getJson("/api/support/chat/sessions/{$session->id}")->assertForbidden();
        $this->getJson("/api/support/chat/messages?sessionId={$session->id}")->assertForbidden();
        $this->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'x'])->assertForbidden();
        // An unknown token is the same as no token.
        $this->asGuest('definitely-not-a-token')
            ->getJson("/api/support/chat/sessions/{$session->id}")
            ->assertForbidden();
    }

    public function test_message_without_session_and_without_identity_is_unauthenticated(): void
    {
        $this->postJson('/api/support/chat/messages', ['text' => 'hi'])->assertStatus(401);
    }

    public function test_guest_must_name_their_session_when_sending(): void
    {
        [$session, $token] = $this->guestSession();

        $this->asGuest($token)
            ->postJson('/api/support/chat/messages', ['text' => 'hi'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sessionId');

        $this->assertDatabaseCount('chat_sessions', 1);
    }

    public function test_guest_token_expires_with_the_session_ttl(): void
    {
        [$session, $token] = $this->guestSession();
        $session->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->asGuest($token)
            ->getJson("/api/support/chat/sessions/{$session->id}")
            ->assertForbidden();
    }

    public function test_member_cannot_use_a_guest_session_and_staff_cannot_use_member_endpoints(): void
    {
        [$guestSession] = $this->guestSession();
        $member = $this->member();
        $agent = $this->agent();
        $memberSession = $this->waitingSession($member);

        $this->asMember($member)
            ->getJson("/api/support/chat/sessions/{$guestSession->id}")->assertForbidden();
        // Support staff use /admin/chat/* — not someone else's widget endpoints.
        $this->asMember($agent)
            ->getJson("/api/support/chat/sessions/{$memberSession->id}")->assertForbidden();
        $this->asMember($agent)
            ->postJson('/api/support/chat/messages', ['sessionId' => $memberSession->id, 'text' => 'x'])
            ->assertForbidden();
    }

    public function test_member_endpoints_for_own_session_work_with_the_session_id_on_the_url(): void
    {
        $member = $this->member();
        $session = $this->waitingSession($member);

        $this->asMember($member)
            ->getJson("/api/support/chat/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('id', $session->id);
    }

    public function test_unknown_session_returns_404(): void
    {
        $this->asMember($this->member())
            ->getJson('/api/support/chat/sessions/999999')
            ->assertNotFound();
    }

    public function test_snapshot_supports_conditional_requests(): void
    {
        [$session, $token] = $this->guestSession();
        $url = "/api/support/chat/sessions/{$session->id}";

        $first = $this->asGuest($token)->getJson($url)->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeaders($this->guestHeaders($token) + ['If-None-Match' => $etag])->getJson($url)->assertStatus(304);

        ChatMessage::create(['chat_session_id' => $session->id, 'sender' => 'support', 'text' => 'hi']);
        $this->withHeaders($this->guestHeaders($token) + ['If-None-Match' => $etag])->getJson($url)->assertOk();
    }

    // --- Messages ---

    public function test_message_html_is_stripped_and_tag_only_messages_are_rejected(): void
    {
        [$session, $token] = $this->guestSession();
        $headers = $this->guestHeaders($token);

        $this->withHeaders($headers)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => '<b>سلام</b> <img src=x onerror=alert(1)> دوست من 2 < 3'])
            ->assertCreated()
            ->assertJsonPath('text', 'سلام  دوست من 2 < 3');

        $this->withHeaders($headers)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => '<script></script>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('text');
    }

    public function test_hostile_markup_floods_are_cheap_and_still_rejected_by_length(): void
    {
        [$session, $token] = $this->guestSession();

        $started = microtime(true);
        $this->asGuest($token)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => str_repeat('<a', 200000)])
            ->assertStatus(422)->assertJsonValidationErrors('text');
        $this->asGuest($token)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => str_repeat('<a', 2000)])
            ->assertCreated(); // no tag ever closes, so it is kept as 4000 chars of plain text
        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function test_message_text_is_still_required(): void
    {
        [$session, $token] = $this->guestSession();

        $this->asGuest($token)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('text');
    }

    public function test_guest_message_on_a_closed_session_reopens_it_like_before(): void
    {
        [$session, $token] = $this->guestSession(['status' => 'closed']);

        $this->asGuest($token)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'هنوز اینجایید؟'])
            ->assertCreated();

        $session->refresh();
        $this->assertSame('waiting', $session->status);
        $this->assertNull($session->closed_at);
        $this->assertNull($session->closed_by);
    }

    public function test_messages_come_back_in_send_order(): void
    {
        [$session, $token] = $this->guestSession();
        foreach (['اول', 'دوم', 'سوم'] as $text) {
            // Same created_at second for all three.
            ChatMessage::create(['chat_session_id' => $session->id, 'sender' => 'user', 'text' => $text]);
        }

        $this->asGuest($token)
            ->getJson("/api/support/chat/messages?sessionId={$session->id}")
            ->assertJsonPath('0.text', 'اول')
            ->assertJsonPath('1.text', 'دوم')
            ->assertJsonPath('2.text', 'سوم');
    }

    // --- Queue ---

    public function test_queue_position_and_wait_estimate_follow_the_order_of_waiting_sessions(): void
    {
        $this->goOnline();
        $first = $this->waitingSession();
        $second = $this->waitingSession();
        $third = $this->waitingSession();
        $active = ChatSession::create(['user_id' => $this->member()->id, 'status' => 'active']);

        $snapshot = fn (ChatSession $s) => $this->asMember($s->user)
            ->getJson("/api/support/chat/sessions/{$s->id}")->assertOk();

        $snapshot($first)->assertJsonPath('queuePosition', 1)->assertJsonPath('estimatedWaitMinutes', 2);
        $snapshot($second)->assertJsonPath('queuePosition', 2)->assertJsonPath('estimatedWaitMinutes', 4);
        $snapshot($third)->assertJsonPath('queuePosition', 3)->assertJsonPath('estimatedWaitMinutes', 6);
        // Not waiting: no queue fields at all.
        $snapshot($active)->assertJsonPath('queuePosition', null)->assertJsonPath('estimatedWaitMinutes', null);
    }

    public function test_claiming_a_session_moves_everyone_behind_it_up_and_rebroadcasts_their_snapshot(): void
    {
        $agent = $this->goOnline();
        $first = $this->waitingSession();
        $second = $this->waitingSession();
        [$guestBehind] = $this->guestSession();           // polls instead; never broadcast to
        $third = $this->waitingSession();

        Event::fake([ChatSessionUpdated::class, ChatSessionStatusChanged::class]);

        $this->asMember($agent)
            ->postJson('/api/admin/chat/messages', ['sessionId' => $first->id, 'text' => 'سلام'])
            ->assertCreated();

        $this->asMember($second->user)
            ->getJson("/api/support/chat/sessions/{$second->id}")
            ->assertJsonPath('queuePosition', 1);
        // second, then the guest queued between them, then third
        $this->asMember($third->user)
            ->getJson("/api/support/chat/sessions/{$third->id}")
            ->assertJsonPath('queuePosition', 3);

        $updated = fn (ChatSession $s) => Event::dispatched(ChatSessionUpdated::class,
            fn (ChatSessionUpdated $e) => $e->session->id === $s->id)->count();

        $this->assertSame(1, $updated($first), 'the claimed session broadcasts exactly one snapshot');
        $this->assertSame(1, $updated($second));
        $this->assertSame(1, $updated($third));
        $this->assertSame(0, $updated($guestBehind));
        Event::assertDispatched(ChatSessionStatusChanged::class, 1);
    }

    public function test_closing_a_waiting_session_rebroadcasts_the_queue_behind_it(): void
    {
        $agent = $this->goOnline();
        $ahead = $this->waitingSession();
        $closing = $this->waitingSession();
        $behind = $this->waitingSession();

        Event::fake([ChatSessionUpdated::class]);

        $this->asMember($agent)
            ->patchJson("/api/admin/chat/sessions/{$closing->id}", ['status' => 'closed'])
            ->assertOk();

        $ids = Event::dispatched(ChatSessionUpdated::class)->map(fn ($args) => $args[0]->session->id)->sort()->values()->all();
        $this->assertSame([$closing->id, $behind->id], $ids); // not $ahead: its position did not change
    }

    public function test_a_reopened_session_re_enters_the_queue_at_its_old_place(): void
    {
        $this->goOnline();
        $old = ChatSession::create(['user_id' => $this->member()->id, 'status' => 'closed']);
        $waiting = $this->waitingSession();

        Event::fake([ChatSessionUpdated::class]);

        $this->asMember($old->user)
            ->postJson('/api/support/chat/messages', ['sessionId' => $old->id, 'text' => 'ادامه'])
            ->assertCreated();

        Event::assertDispatched(ChatSessionUpdated::class, fn ($e) => $e->session->id === $waiting->id);
        $this->asMember($waiting->user)
            ->getJson("/api/support/chat/sessions/{$waiting->id}")
            ->assertJsonPath('queuePosition', 2);
    }

    // --- Agent side: claim, typing, read ---

    public function test_first_agent_reply_claims_the_session_and_the_snapshot_shows_only_a_display_name(): void
    {
        $agent = $this->agent('online');
        $other = $this->agent('online', ['first_name' => 'زهرا', 'last_name' => 'کریمی']);
        $member = $this->member();
        $session = $this->waitingSession($member);

        $this->asMember($agent)
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'سلام، بفرمایید'])
            ->assertCreated();

        $session->refresh();
        $this->assertSame($agent->id, $session->agent_id);
        $this->assertNotNull($session->agent_joined_at);
        $this->assertSame('active', $session->status);

        $snapshot = $this->asMember($member)
            ->getJson("/api/support/chat/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('queuePosition', null)
            ->assertJsonPath('agent.name', 'مریم رضایی')
            ->assertJsonPath('agent.joinedAt', $session->agent_joined_at->toIso8601String());

        // Nothing but name + joinedAt may leak about the agent.
        $this->assertSame(['joinedAt', 'name'], collect($snapshot->json('agent'))->keys()->sort()->values()->all());
        $this->assertStringNotContainsString($agent->phone_number, $snapshot->getContent());

        // A second agent replying does not take the session over.
        $this->asMember($other)
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'منم هستم'])
            ->assertCreated();
        $this->assertSame($agent->id, $session->fresh()->agent_id);
    }

    public function test_agent_marking_the_session_active_also_claims_it(): void
    {
        Event::fake([ChatSessionUpdated::class]);
        $agent = $this->agent('online');
        $session = $this->waitingSession();

        $this->asMember($agent)
            ->patchJson("/api/admin/chat/sessions/{$session->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('agent.id', $agent->id)
            ->assertJsonPath('agent.name', 'مریم رضایی');

        $this->assertSame($agent->id, $session->fresh()->agent_id);
    }

    public function test_claiming_an_already_active_unclaimed_session_still_broadcasts_a_snapshot(): void
    {
        $agent = $this->agent('online');
        $session = ChatSession::create(['user_id' => $this->member()->id, 'status' => 'active']);
        Event::fake([ChatSessionUpdated::class, ChatSessionStatusChanged::class]);

        $this->asMember($agent)
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'سلام'])
            ->assertCreated();

        Event::assertNotDispatched(ChatSessionStatusChanged::class);
        Event::assertDispatched(ChatSessionUpdated::class, fn ($e) => $e->session->agent_id === $agent->id);
    }

    public function test_the_claim_survives_closing_and_agent_is_kept_in_the_snapshot(): void
    {
        $agent = $this->agent('online');
        $member = $this->member();
        $session = $this->waitingSession($member);

        $this->asMember($agent)
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'سلام']);
        $this->asMember($agent)
            ->patchJson("/api/admin/chat/sessions/{$session->id}", ['status' => 'closed'])
            ->assertOk();

        $this->asMember($member)
            ->getJson("/api/support/chat/sessions/{$session->id}")
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('agent.name', 'مریم رضایی');
    }

    public function test_agent_typing_is_flagged_for_five_seconds_and_broadcast(): void
    {
        Event::fake([ChatAgentTyping::class]);
        $agent = $this->agent('online');
        $member = $this->member();
        $session = ChatSession::create(['user_id' => $member->id, 'status' => 'active']);
        $snapshot = fn () => $this->asMember($member)
            ->getJson("/api/support/chat/sessions/{$session->id}");

        $snapshot()->assertJsonPath('agentTyping', false);

        $this->asMember($agent)
            ->postJson("/api/admin/chat/sessions/{$session->id}/typing")
            ->assertNoContent();

        Event::assertDispatched(ChatAgentTyping::class, fn (ChatAgentTyping $e) => $e->broadcastWith() === ['sessionId' => $session->id]
            && collect($e->broadcastOn())->map->name->contains("private-chat.{$session->id}"));
        $snapshot()->assertJsonPath('agentTyping', true);

        \Carbon\Carbon::setTestNow(now()->addSeconds(6));
        $snapshot()->assertJsonPath('agentTyping', false);
    }

    public function test_an_agent_message_ends_the_typing_indicator(): void
    {
        $agent = $this->agent('online');
        $member = $this->member();
        $session = ChatSession::create([
            'user_id' => $member->id, 'status' => 'active', 'agent_typing_until' => now()->addSeconds(4),
        ]);

        $this->asMember($agent)
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'ok']);

        $this->asMember($member)
            ->getJson("/api/support/chat/sessions/{$session->id}")
            ->assertJsonPath('agentTyping', false);
    }

    public function test_only_staff_can_signal_typing(): void
    {
        $member = $this->member();
        $session = ChatSession::create(['user_id' => $member->id, 'status' => 'active']);

        $this->asMember($member)
            ->postJson("/api/admin/chat/sessions/{$session->id}/typing")
            ->assertForbidden();
        $this->flushHeaders();
        $this->postJson("/api/admin/chat/sessions/{$session->id}/typing")->assertStatus(401);
    }

    public function test_opening_the_conversation_marks_the_users_messages_read_and_broadcasts_once(): void
    {
        $agent = $this->agent('online');
        $member = $this->member();
        $session = ChatSession::create(['user_id' => $member->id, 'status' => 'waiting']);
        $first = ChatMessage::create(['chat_session_id' => $session->id, 'sender' => 'user', 'text' => 'a']);
        $last = ChatMessage::create(['chat_session_id' => $session->id, 'sender' => 'user', 'text' => 'b']);
        ChatMessage::create(['chat_session_id' => $session->id, 'sender' => 'support', 'text' => 'c']); // not a user message
        $updatedAt = $session->fresh()->updated_at;

        Event::fake([ChatMessagesRead::class]);

        $this->asMember($agent)
            ->getJson("/api/admin/chat/messages?sessionId={$session->id}")
            ->assertOk()
            ->assertJsonCount(3);

        $this->assertSame($last->id, $session->fresh()->last_read_message_id);
        $this->assertTrue($session->fresh()->updated_at->equalTo($updatedAt), 'reading must not reorder the admin list');
        Event::assertDispatched(ChatMessagesRead::class, fn (ChatMessagesRead $e) => $e->broadcastWith()
            === ['sessionId' => $session->id, 'lastReadMessageId' => $last->id]
            && collect($e->broadcastOn())->map->name->contains("private-chat.{$session->id}"));

        $this->asMember($member)
            ->getJson("/api/support/chat/sessions/{$session->id}")
            ->assertJsonPath('lastReadMessageId', $last->id)
            ->assertJsonPath('lastMessageId', $last->id + 1);

        // Re-opening with nothing new is silent.
        $this->asMember($agent)->getJson("/api/admin/chat/messages?sessionId={$session->id}");
        Event::assertDispatchedTimes(ChatMessagesRead::class, 1);

        // A new user message moves the pointer on the next open.
        $newer = ChatMessage::create(['chat_session_id' => $session->id, 'sender' => 'user', 'text' => 'd']);
        $this->asMember($agent)->getJson("/api/admin/chat/messages?sessionId={$session->id}");
        $this->assertSame($newer->id, $session->fresh()->last_read_message_id);
        Event::assertDispatchedTimes(ChatMessagesRead::class, 2);
    }

    public function test_a_session_without_user_messages_is_not_marked_read(): void
    {
        $agent = $this->agent('online');
        $session = ChatSession::create(['user_id' => $this->member()->id, 'status' => 'active']);
        Event::fake([ChatMessagesRead::class]);

        $this->asMember($agent)->getJson("/api/admin/chat/messages?sessionId={$session->id}");

        $this->assertNull($session->fresh()->last_read_message_id);
        Event::assertNotDispatched(ChatMessagesRead::class);
    }

    public function test_agent_closing_records_who_and_when_and_reopening_clears_it(): void
    {
        Event::fake([ChatSessionUpdated::class, ChatSessionStatusChanged::class]);
        $agent = $this->agent('online');
        $session = ChatSession::create(['user_id' => $this->member()->id, 'status' => 'active']);

        $this->asMember($agent)
            ->patchJson("/api/admin/chat/sessions/{$session->id}", ['status' => 'closed'])
            ->assertOk();

        $session->refresh();
        $this->assertSame('agent', $session->closed_by);
        $this->assertNotNull($session->closed_at);
        Event::assertDispatched(ChatSessionUpdated::class, fn ($e) => $e->session->id === $session->id
            && $e->broadcastWith()['status'] === 'closed');

        $this->asMember($agent)
            ->patchJson("/api/admin/chat/sessions/{$session->id}", ['status' => 'waiting'])
            ->assertOk();
        $this->assertNull($session->fresh()->closed_by);
        $this->assertNull($session->fresh()->closed_at);
    }

    public function test_admin_session_list_exposes_guest_agent_and_rating_fields(): void
    {
        $agent = $this->agent('online');
        [$guest] = $this->guestSession(['guest_name' => null, 'agent_id' => $agent->id, 'agent_joined_at' => now(),
            'status' => 'closed', 'rating' => 'good', 'rating_note' => 'ممنون', 'rated_at' => now()]);
        $member = $this->member();
        $memberSession = $this->waitingSession($member);

        $rows = collect($this->asMember($agent)
            ->getJson('/api/admin/chat/sessions')
            ->assertOk()
            ->json('data'))->keyBy('sessionId');

        $g = $rows[$guest->id];
        $this->assertTrue($g['isGuest']);
        $this->assertNull($g['patientId']);
        $this->assertSame('مهمان', $g['patientName']);
        $this->assertSame('09121234567', $g['patientPhone']);
        $this->assertSame('good', $g['rating']);
        $this->assertSame('ممنون', $g['ratingNote']);
        $this->assertSame($agent->id, $g['agent']['id']);
        $this->assertSame('مریم رضایی', $g['agent']['name']);

        $m = $rows[$memberSession->id];
        $this->assertFalse($m['isGuest']);
        $this->assertSame('علی احمدی', $m['patientName']);
        $this->assertSame($member->phone_number, $m['patientPhone']);
        $this->assertNull($m['agent']);
        $this->assertNull($m['rating']);
    }

    // --- End / rating ---

    public function test_user_ends_the_chat_and_ending_again_is_a_silent_no_op(): void
    {
        $member = $this->member();
        $session = ChatSession::create(['user_id' => $member->id, 'status' => 'active']);
        Event::fake([ChatSessionUpdated::class, ChatSessionStatusChanged::class]);

        $this->asMember($member)
            ->postJson("/api/support/chat/sessions/{$session->id}/end")
            ->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('rated', false);

        $session->refresh();
        $this->assertSame('user', $session->closed_by);
        $this->assertNotNull($session->closed_at);
        Event::assertDispatched(ChatSessionStatusChanged::class, fn ($e) => $e->session->id === $session->id);
        Event::assertDispatched(ChatSessionUpdated::class, fn ($e) => $e->session->id === $session->id);

        Event::assertDispatchedTimes(ChatSessionStatusChanged::class, 1);
        $closedAt = $session->closed_at;

        $this->asMember($member)
            ->postJson("/api/support/chat/sessions/{$session->id}/end")
            ->assertOk()
            ->assertJsonPath('status', 'closed');
        Event::assertDispatchedTimes(ChatSessionStatusChanged::class, 1);
        $this->assertTrue($session->fresh()->closed_at->equalTo($closedAt));
    }

    public function test_guest_can_end_their_chat(): void
    {
        [$session, $token] = $this->guestSession();

        $this->asGuest($token)
            ->postJson("/api/support/chat/sessions/{$session->id}/end")
            ->assertOk()
            ->assertJsonPath('status', 'closed');

        $this->assertSame('user', $session->fresh()->closed_by);
    }

    public function test_ending_a_chat_someone_else_closed_keeps_the_original_closer(): void
    {
        $member = $this->member();
        $session = ChatSession::create([
            'user_id' => $member->id, 'status' => 'closed', 'closed_by' => 'system', 'closed_at' => now()->subHour(),
        ]);

        $this->asMember($member)->postJson("/api/support/chat/sessions/{$session->id}/end")->assertOk();

        $this->assertSame('system', $session->fresh()->closed_by);
    }

    public function test_rating_needs_a_closed_session_and_is_accepted_once(): void
    {
        $member = $this->member();
        $session = ChatSession::create(['user_id' => $member->id, 'status' => 'active']);
        $url = "/api/support/chat/sessions/{$session->id}/rating";
        $as = fn () => $this->asMember($member);

        // Too early.
        $as()->postJson($url, ['rating' => 'great'])->assertStatus(409);
        $this->assertNull($session->fresh()->rated_at);

        $as()->postJson("/api/support/chat/sessions/{$session->id}/end")->assertOk();

        Event::fake([ChatSessionUpdated::class]);
        $as()->postJson($url, ['rating' => 'good', 'note' => 'ممنون از راهنمایی'])
            ->assertOk()
            ->assertJsonPath('rated', true)
            ->assertJsonPath('status', 'closed');

        $session->refresh();
        $this->assertSame('good', $session->rating);
        $this->assertSame('ممنون از راهنمایی', $session->rating_note);
        $this->assertNotNull($session->rated_at);
        Event::assertDispatched(ChatSessionUpdated::class,
            fn ($e) => $e->broadcastWith()['rated'] === true);

        // Only once.
        $as()->postJson($url, ['rating' => 'bad'])->assertStatus(409);
        $this->assertSame('good', $session->fresh()->rating);
    }

    public function test_rating_validates_its_input_before_checking_state(): void
    {
        $member = $this->member();
        $session = ChatSession::create(['user_id' => $member->id, 'status' => 'closed']);
        $url = "/api/support/chat/sessions/{$session->id}/rating";
        $as = fn () => $this->asMember($member);

        $as()->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('rating');
        $as()->postJson($url, ['rating' => 'terrible'])->assertStatus(422)->assertJsonValidationErrors('rating');
        $as()->postJson($url, ['rating' => 'ok', 'note' => str_repeat('x', 1001)])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertNull($session->fresh()->rated_at);

        foreach (['great', 'good', 'ok', 'bad'] as $rating) {
            $fresh = ChatSession::create(['user_id' => $member->id, 'status' => 'closed']);
            $as()->postJson("/api/support/chat/sessions/{$fresh->id}/rating", ['rating' => $rating])->assertOk();
            $this->assertSame($rating, $fresh->fresh()->rating);
            $this->assertNull($fresh->fresh()->rating_note);
        }
    }

    public function test_guest_can_rate_their_closed_chat_but_not_someone_elses(): void
    {
        [$mine, $token] = $this->guestSession(['status' => 'closed']);
        [$theirs] = $this->guestSession(['status' => 'closed']);
        $headers = $this->guestHeaders($token);

        $this->withHeaders($headers)->postJson("/api/support/chat/sessions/{$mine->id}/rating", ['rating' => 'great'])
            ->assertOk()->assertJsonPath('rated', true);
        $this->withHeaders($headers)->postJson("/api/support/chat/sessions/{$theirs->id}/rating", ['rating' => 'great'])
            ->assertForbidden();
    }

    public function test_support_report_includes_the_chat_ratings(): void
    {
        $agent = $this->agent('online');
        $rate = fn (string $rating) => ChatSession::create([
            'user_id' => $this->member()->id, 'status' => 'closed', 'rating' => $rating, 'rated_at' => now(),
        ]);
        ChatSession::create(['user_id' => $this->member()->id, 'status' => 'closed']); // unrated: ignored
        foreach (['great', 'great', 'good', 'bad'] as $rating) {
            $rate($rating);
        }

        $this->asMember($agent)
            ->getJson('/api/admin/support/reports')
            ->assertOk()
            ->assertJsonPath('chatRatings.total', 4)
            ->assertJsonPath('chatRatings.average', 3)   // (4+4+3+1)/4 = 3.0, which JSON renders as 3
            ->assertJsonPath('chatRatings.distribution', ['great' => 2, 'good' => 1, 'ok' => 0, 'bad' => 1]);
    }

    public function test_support_report_chat_ratings_are_empty_without_any_rating(): void
    {
        $this->asMember($this->agent('online'))
            ->getJson('/api/admin/support/reports')
            ->assertJsonPath('chatRatings.total', 0)
            ->assertJsonPath('chatRatings.average', null);
    }
}
