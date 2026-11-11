<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Notifications\SupportChatPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SupportChatPushTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['webpush.vapid.public_key' => 'test-public-key']);
    }

    private function authHeaders(User $user): array
    {
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function make(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    private function subscribe(User $agent, string $endpoint = self::ENDPOINT): void
    {
        $agent->updatePushSubscription($endpoint, 'p256dh-key', 'auth-token', 'aes128gcm');
    }

    public function test_agent_can_subscribe_and_unsubscribe(): void
    {
        $agent = $this->make('support_agent');
        $payload = ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => 'k', 'auth' => 'a']];

        $this->withHeaders($this->authHeaders($agent))
            ->postJson('/api/admin/push/subscriptions', $payload)
            ->assertStatus(201);
        $this->assertSame(1, $agent->pushSubscriptions()->count());

        $this->withHeaders($this->authHeaders($agent))
            ->deleteJson('/api/admin/push/subscriptions', ['endpoint' => self::ENDPOINT])
            ->assertOk();
        $this->assertSame(0, $agent->pushSubscriptions()->count());
    }

    public function test_patients_cannot_subscribe(): void
    {
        $this->withHeaders($this->authHeaders($this->make('user')))
            ->postJson('/api/admin/push/subscriptions', [
                'endpoint' => self::ENDPOINT,
                'keys' => ['p256dh' => 'k', 'auth' => 'a'],
            ])
            ->assertStatus(403);
    }

    public function test_new_session_notifies_every_subscribed_agent_once(): void
    {
        Notification::fake();
        $agentA = $this->make('support_agent');
        $agentB = $this->make('admin');
        $unsubscribed = $this->make('support_agent');
        $this->subscribe($agentA, self::ENDPOINT.'a');
        $this->subscribe($agentB, self::ENDPOINT.'b');

        $this->withHeaders($this->authHeaders($this->make('user')))
            ->postJson('/api/support/chat/messages', ['text' => 'سلام'])
            ->assertStatus(201);

        Notification::assertSentToTimes($agentA, SupportChatPush::class, 1);
        Notification::assertSentToTimes($agentB, SupportChatPush::class, 1);
        Notification::assertNotSentTo($unsubscribed, SupportChatPush::class);
    }

    public function test_push_failure_does_not_break_sending_a_message(): void
    {
        // e.g. production before the push_subscriptions migration ran
        Schema::drop('push_subscriptions');

        $this->withHeaders($this->authHeaders($this->make('user')))
            ->postJson('/api/support/chat/messages', ['text' => 'سلام'])
            ->assertStatus(201);
    }

    public function test_message_in_claimed_session_only_notifies_its_agent(): void
    {
        Notification::fake();
        $owner = $this->make('support_agent');
        $other = $this->make('support_agent');
        $this->subscribe($owner, self::ENDPOINT.'a');
        $this->subscribe($other, self::ENDPOINT.'b');
        $patient = $this->make('user');
        $session = ChatSession::create(['user_id' => $patient->id, 'status' => 'active', 'agent_id' => $owner->id]);
        ChatMessage::create(['chat_session_id' => $session->id, 'sender' => 'user', 'text' => 'پیام اول']);

        $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'پیام دوم'])
            ->assertStatus(201);

        Notification::assertSentTo($owner, SupportChatPush::class);
        Notification::assertNotSentTo($other, SupportChatPush::class);
    }

    public function test_push_survives_a_day_offline(): void
    {
        $message = (new SupportChatPush(7, 'عنوان', 'متن'))->toWebPush(new User, new SupportChatPush(7, 'عنوان', 'متن'));

        $this->assertSame(86400, $message->getOptions()['TTL']);
        $this->assertSame('chat-7', $message->toArray()['tag']);
    }

    public function test_agent_replies_do_not_notify(): void
    {
        Notification::fake();
        $agent = $this->make('support_agent');
        $this->subscribe($agent);
        $session = ChatSession::create(['user_id' => $this->make('user')->id, 'status' => 'active']);

        $this->withHeaders($this->authHeaders($agent))
            ->postJson('/api/admin/chat/messages', ['sessionId' => $session->id, 'text' => 'در خدمتم'])
            ->assertStatus(201);

        Notification::assertNothingSent();
    }
}
