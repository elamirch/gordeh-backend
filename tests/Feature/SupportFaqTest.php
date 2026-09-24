<?php

namespace Tests\Feature;

use App\Models\FaqItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SupportFaqTest extends TestCase
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

    public function test_returns_faq_items_from_database(): void
    {
        FaqItem::create(['question' => 'سوال ۱؟', 'answer' => 'پاسخ ۱']);
        FaqItem::create(['question' => 'سوال ۲؟', 'answer' => 'پاسخ ۲']);

        $user = User::factory()->create([
            'role' => 'user',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/support/faq');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonPath('0.question', 'سوال ۱؟');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/support/faq');

        $response->assertStatus(401);
    }
}
