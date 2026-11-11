<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class MobilePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORITY = 'A00000000000000000000000000000099';

    private function user(): User
    {
        return User::factory()->create(['phone_number' => '09'.fake()->unique()->numerify('#########'), 'wallet_balance' => 0]);
    }

    private function as(User $user): array
    {
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function fakeZarinpal(int $verifyCode = 100): void
    {
        Http::fake([
            '*request.json' => Http::response(['data' => ['authority' => self::AUTHORITY, 'code' => 100]]),
            '*verify.json' => Http::response(['data' => ['code' => $verifyCode, 'ref_id' => 777001]]),
        ]);
    }

    /** @return array{0: User, 1: string, 2: int} user, token, payment id */
    private function init(string $purpose = 'wallet', ?int $amount = 5_000_000): array
    {
        $user = $this->user();
        $res = $this->postJson('/api/payments/mobile/init', array_filter(['purpose' => $purpose, 'amount' => $amount]), $this->as($user))->assertOk();

        return [$user, basename($res->json('url')), $res->json('payment_id')];
    }

    public function test_init_creates_a_pending_payment_and_returns_the_page_url(): void
    {
        [$user, $token, $id] = $this->init();

        $this->assertSame(64, strlen($token));
        $payment = Payment::findOrFail($id);
        $this->assertSame('pending', $payment->status);
        $this->assertSame($user->id, $payment->user_id);
        $this->assertSame(hash('sha256', $token), $payment->mobile_token_hash);
        $this->assertNotSame($token, $payment->mobile_token_hash);
    }

    public function test_init_requires_authentication(): void
    {
        $this->postJson('/api/payments/mobile/init', ['purpose' => 'wallet', 'amount' => 5_000_000])->assertUnauthorized();
    }

    public function test_a_service_is_charged_at_the_server_price_whatever_the_client_sends(): void
    {
        $user = $this->user();
        $res = $this->postJson('/api/payments/mobile/init', ['purpose' => 'consultation', 'amount' => 1000], $this->as($user))->assertOk();

        $this->assertSame((int) config('payments.prices.consultation'), $res->json('amount'));
    }

    public function test_wallet_amount_is_range_checked(): void
    {
        $user = $this->user();
        $this->postJson('/api/payments/mobile/init', ['purpose' => 'wallet', 'amount' => 1000], $this->as($user))->assertStatus(422);
        $this->postJson('/api/payments/mobile/init', ['purpose' => 'wallet'], $this->as($user))->assertStatus(422);
    }

    public function test_page_summary_shows_amount_but_nothing_sensitive(): void
    {
        [, $token, $id] = $this->init();

        $this->getJson("/api/payments/mobile/{$token}")
            ->assertOk()
            ->assertJson(['state' => 'ready', 'amount' => 5_000_000])
            ->assertJsonMissingPath('authority')
            ->assertJsonMissingPath('user_id');
    }

    public function test_invalid_token_is_not_found(): void
    {
        $this->getJson('/api/payments/mobile/'.str_repeat('a', 64))->assertNotFound()->assertJson(['state' => 'invalid']);
        $this->postJson('/api/payments/mobile/'.str_repeat('a', 64).'/continue')->assertNotFound();
    }

    public function test_expired_token_cannot_start_a_payment(): void
    {
        $this->fakeZarinpal();
        [, $token, $id] = $this->init();
        Payment::whereKey($id)->update(['mobile_token_expires_at' => now()->subMinute()]);

        $this->getJson("/api/payments/mobile/{$token}")->assertJson(['state' => 'expired']);
        $this->postJson("/api/payments/mobile/{$token}/continue")->assertOk()->assertJson(['state' => 'expired'])->assertJsonMissingPath('url');
        Http::assertNothingSent();
    }

    public function test_continue_starts_zarinpal_with_the_registered_domain_callback_and_stored_amount(): void
    {
        $this->fakeZarinpal();
        [, $token, $id] = $this->init();

        $this->postJson("/api/payments/mobile/{$token}/continue")
            ->assertOk()
            ->assertJson(['state' => 'ready', 'url' => 'https://payment.zarinpal.com/pg/StartPay/'.self::AUTHORITY]);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'request.json')
            && $r['amount'] === 5_000_000
            && $r['callback_url'] === config('payments.mobile.callback_url')
            && str_starts_with($r['callback_url'], 'https://gordeh.com/'));
        $this->assertSame(self::AUTHORITY, Payment::find($id)->authority);
    }

    public function test_duplicate_continue_does_not_create_a_second_zarinpal_payment(): void
    {
        $this->fakeZarinpal();
        [, $token] = $this->init();

        $first = $this->postJson("/api/payments/mobile/{$token}/continue")->json('url');
        $second = $this->postJson("/api/payments/mobile/{$token}/continue")->json('url');

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_successful_callback_verifies_with_zarinpal_and_credits_the_wallet_once(): void
    {
        $this->fakeZarinpal();
        [$user, $token, $id] = $this->init();
        $this->postJson("/api/payments/mobile/{$token}/continue")->assertOk();

        $this->postJson('/api/payments/mobile/callback', ['authority' => self::AUTHORITY])
            ->assertOk()->assertJson(['payment_id' => $id, 'status' => 'success']);

        $this->assertSame(5_000_000, (int) $user->fresh()->wallet_balance);
        $this->assertSame(777001, (int) Payment::find($id)->ref_id);

        // The same callback again (reload, retry) changes nothing and verifies nothing more.
        $this->postJson('/api/payments/mobile/callback', ['authority' => self::AUTHORITY])
            ->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame(5_000_000, (int) $user->fresh()->wallet_balance);
        Http::assertSentCount(2); // one request + one verify
    }

    public function test_failed_verification_marks_the_payment_failed_and_credits_nothing(): void
    {
        $this->fakeZarinpal(verifyCode: -50);
        [$user, $token, $id] = $this->init();
        $this->postJson("/api/payments/mobile/{$token}/continue")->assertOk();

        $this->postJson('/api/payments/mobile/callback', ['authority' => self::AUTHORITY])
            ->assertOk()->assertJson(['status' => 'failed']);

        $this->assertSame('failed', Payment::find($id)->status);
        $this->assertSame(0, (int) $user->fresh()->wallet_balance);
        $this->getJson("/api/payments/mobile/{$token}")->assertJson(['state' => 'failed']);
    }

    public function test_callback_for_an_unknown_or_website_authority_is_rejected(): void
    {
        $this->fakeZarinpal();
        $this->postJson('/api/payments/mobile/callback', ['authority' => 'nope'])->assertNotFound();

        // A website payment (no mobile token) is never settled through the mobile callback.
        $web = Payment::create(['user_id' => $this->user()->id, 'amount' => 5_000_000, 'authority' => 'WEB1', 'status' => 'pending', 'purpose' => 'wallet']);
        $this->postJson('/api/payments/mobile/callback', ['authority' => 'WEB1'])->assertNotFound();
        $this->assertSame('pending', $web->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_cancelled_payment_stays_unpaid(): void
    {
        $this->fakeZarinpal(verifyCode: -51); // the user abandoned the gateway
        [$user, $token, $id] = $this->init();
        $this->postJson("/api/payments/mobile/{$token}/continue")->assertOk();

        $this->postJson('/api/payments/mobile/callback', ['authority' => self::AUTHORITY])->assertJson(['status' => 'failed']);
        $this->assertSame(0, (int) $user->fresh()->wallet_balance);
    }

    public function test_status_is_authoritative_and_only_for_the_owner(): void
    {
        $this->fakeZarinpal();
        [$user, $token, $id] = $this->init();
        $this->postJson("/api/payments/mobile/{$token}/continue");

        $this->getJson("/api/payments/{$id}/status", $this->as($user))->assertOk()->assertJson(['id' => $id, 'status' => 'pending']);

        $this->postJson('/api/payments/mobile/callback', ['authority' => self::AUTHORITY]);
        $this->getJson("/api/payments/{$id}/status", $this->as($user))->assertJson(['status' => 'success', 'ref_id' => 777001]);

        $this->getJson("/api/payments/{$id}/status", $this->as($this->user()))->assertNotFound();
        $this->getJson("/api/payments/{$id}/status")->assertUnauthorized();
    }

    public function test_the_token_hash_is_never_serialised(): void
    {
        [$user, , $id] = $this->init();

        $this->assertArrayNotHasKey('mobile_token_hash', Payment::find($id)->toArray());
    }

    public function test_website_payment_request_still_works_unchanged(): void
    {
        $this->fakeZarinpal();
        $user = $this->user();

        $this->postJson('/api/payments/request', ['amount' => 5_000_000, 'purpose' => 'wallet', 'callback_url' => 'https://gordeh.com/payments/callback'], $this->as($user))
            ->assertOk()->assertJson(['authority' => self::AUTHORITY, 'url' => 'https://payment.zarinpal.com/pg/StartPay/'.self::AUTHORITY]);

        $this->postJson('/api/payments/verify', ['authority' => self::AUTHORITY], $this->as($user))->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame(5_000_000, (int) $user->fresh()->wallet_balance);
    }
}
