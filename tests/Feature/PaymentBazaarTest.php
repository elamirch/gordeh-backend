<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PaymentBazaarTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
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

    private function fakeBazaar(array $body, int $status = 200): void
    {
        config(['payments.bazaar.api_secret' => 'test-secret']);
        Http::fake(['pardakht.cafebazaar.ir/*' => Http::response($body, $status)]);
    }

    private function verify(User $user, string $product = 'gordeh_analysis', string $token = 'tok123')
    {
        $this->resetJwtState();

        return $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/payments/bazaar/verify', ['product_id' => $product, 'purchase_token' => $token]);
    }

    public function test_valid_analysis_purchase_creates_unused_credit(): void
    {
        $patient = $this->patient();
        $this->fakeBazaar(['purchaseState' => 0, 'consumptionState' => 1, 'developerPayload' => 'u'.$patient->id]);

        $this->verify($patient)->assertOk()->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('payments', [
            'user_id' => $patient->id,
            'authority' => 'bazaar-tok123',
            'status' => 'success',
            'amount' => 3_990_000,
            'is_used_lab_test' => false,
            'is_used_insurance' => false,
        ]);
        Http::assertSent(fn ($request) => $request->hasHeader('CAFEBAZAAR-PISHKHAN-API-SECRET', 'test-secret')
            && str_ends_with($request->url(), '/validate/com.gordeh.app/inapp/gordeh_analysis/purchases/tok123/'));
    }

    public function test_consultation_purchase_does_not_grant_lab_test_credit(): void
    {
        $patient = $this->patient();
        $this->fakeBazaar(['purchaseState' => 0, 'developerPayload' => 'u'.$patient->id]);

        $this->verify($patient, 'gordeh_consultation')->assertOk()->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('payments', [
            'user_id' => $patient->id,
            'is_used_lab_test' => true,
            'is_used_insurance' => true,
        ]);
    }

    public function test_same_token_twice_is_one_payment(): void
    {
        $patient = $this->patient();
        $this->fakeBazaar(['purchaseState' => 0, 'developerPayload' => 'u'.$patient->id]);

        $this->verify($patient)->assertJson(['status' => 'success']);
        $this->verify($patient)->assertJson(['status' => 'success']);

        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
    }

    public function test_token_of_another_user_is_rejected(): void
    {
        $owner = $this->patient();
        $other = $this->patient();
        $this->fakeBazaar(['purchaseState' => 0, 'developerPayload' => 'u'.$owner->id]);

        $this->verify($other)->assertOk()->assertJson(['status' => 'failed']);
        $this->verify($owner)->assertJson(['status' => 'success']);
        $this->verify($other)->assertJson(['status' => 'failed']);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_refunded_purchase_is_rejected(): void
    {
        $patient = $this->patient();
        $this->fakeBazaar(['purchaseState' => 1, 'developerPayload' => 'u'.$patient->id]);

        $this->verify($patient)->assertJson(['status' => 'failed']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unknown_token_or_product_is_rejected(): void
    {
        $patient = $this->patient();
        $this->fakeBazaar(['error' => 'not_found'], 404);

        $this->verify($patient)->assertJson(['status' => 'failed']);
        $this->verify($patient, 'not_a_product')->assertJson(['status' => 'failed']);
        $this->assertDatabaseCount('payments', 0);
    }
}
