<?php

namespace Tests\Concerns;

use App\Models\ChatSession;
use App\Models\User;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;

/** Shared fixtures for the support chat widget tests. */
trait InteractsWithSupportChat
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function authHeaders(User $user): array
    {
        // Guards are resolved once per test-app container; without this, switching
        // actors mid-test can leak the previous request's resolved user into auth().
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    /**
     * Requests as exactly this member. withHeaders() accumulates for the rest of the test, so a
     * "guest" request after a member one would silently still carry the Bearer token.
     */
    protected function asMember(User $user): static
    {
        $this->flushHeaders();

        return $this->withHeaders($this->authHeaders($user));
    }

    protected function asGuest(string $token): static
    {
        $this->flushHeaders();

        return $this->withHeaders($this->guestHeaders($token));
    }

    protected function member(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role' => 'user',
            'first_name' => 'علی',
            'last_name' => 'احمدی',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    protected function agent(string $state = 'offline', array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role' => 'support_agent',
            'first_name' => 'مریم',
            'last_name' => 'رضایی',
            'support_state' => $state,
            // Presence expires without a heartbeat, so a test agent starts with a fresh one.
            'support_state_at' => now(),
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    // The mocked clock is pinned to UTC (the app timezone) on purpose: with a Tehran-zoned mock,
    // Carbon reads DB datetimes back in +03:30 although they were written as UTC.

    /** Inside the 09:00–21:00 Tehran window. */
    protected function duringWorkingHours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:30:00', 'Asia/Tehran')->utc());
    }

    protected function outsideWorkingHours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 23:30:00', 'Asia/Tehran')->utc());
    }

    /** Working hours + one online agent, i.e. config.online === true. */
    protected function goOnline(): User
    {
        $this->duringWorkingHours();

        return $this->agent('online');
    }

    protected function waitingSession(?User $member = null, array $attributes = []): ChatSession
    {
        return ChatSession::create($attributes + [
            'user_id' => ($member ?? $this->member())->id,
            'status' => 'waiting',
        ]);
    }

    /** @return array{0: ChatSession, 1: string} [session, rawToken] */
    protected function guestSession(array $attributes = []): array
    {
        $token = \Illuminate\Support\Str::random(64);
        $session = ChatSession::create($attributes + [
            'user_id' => null,
            'guest_phone' => '09121234567',
            'guest_name' => 'مهمان تست',
            'guest_token_hash' => hash('sha256', $token),
            'status' => 'waiting',
        ]);

        return [$session, $token];
    }

    protected function guestHeaders(string $token): array
    {
        return ['X-Chat-Token' => $token];
    }
}
