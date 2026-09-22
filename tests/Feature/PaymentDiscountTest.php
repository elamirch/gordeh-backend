<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PaymentDiscountTest extends TestCase
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

    public function test_valid_discount_code_creates_unused_success_payment(): void
    {
        $patient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/payments/redeem-discount', ['code' => 'kmu']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('payments', [
            'user_id' => $patient->id,
            'status' => 'success',
            'is_used_lab_test' => false,
            'is_used_insurance' => false,
        ]);
    }

    public function test_discount_code_is_case_insensitive_and_trimmed(): void
    {
        $patient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/payments/redeem-discount', ['code' => '  KmU  ']);

        $response->assertStatus(200);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_invalid_discount_code_is_rejected(): void
    {
        $patient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/payments/redeem-discount', ['code' => 'not-a-real-code']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_redeemed_discount_unlocks_insurance_submission(): void
    {
        $patient = $this->patient();

        $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/payments/redeem-discount', ['code' => 'kmu'])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/insurances', [
                'national_code' => '1234567890',
                'insurance_type' => 'basic',
            ]);

        $response->assertStatus(201);
    }

    public function test_redeemed_discount_unlocks_lab_test_submission(): void
    {
        $patient = $this->patient();

        $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/payments/redeem-discount', ['code' => 'kmu'])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/lab-tests', [
                'age' => 40,
                'gender' => 'm',
                'urine_creatinine' => 90,
                'urine_albumin' => 20,
                'creatinine' => 1.1,
                'albumin' => 4.0,
                'calcium' => 9.3,
                'phosphorous' => 3.9,
                'bCarbonate' => 25.5,
            ]);

        $response->assertStatus(201);
    }

    public function test_lab_test_submission_still_requires_payment_without_discount(): void
    {
        $patient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/lab-tests', [
                'age' => 40,
                'gender' => 'm',
                'urine_creatinine' => 90,
                'urine_albumin' => 20,
                'creatinine' => 1.1,
                'albumin' => 4.0,
                'calcium' => 9.3,
                'phosphorous' => 3.9,
                'bCarbonate' => 25.5,
            ]);

        $response->assertStatus(403);
    }

    public function test_redeem_discount_requires_authentication(): void
    {
        $response = $this->postJson('/api/payments/redeem-discount', ['code' => 'kmu']);

        $response->assertStatus(401);
    }
}
