<?php

namespace Tests\Feature;

use App\Models\FaqItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminFaqTest extends TestCase
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

    public function test_published_faq_is_visible_to_patient(): void
    {
        FaqItem::create(['question' => 'Q1', 'answer' => 'A1', 'status' => 'published']);

        $response = $this->withHeaders($this->authHeaders($this->patient()))->getJson('/api/support/faq');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    }

    public function test_draft_faq_is_invisible_to_patient(): void
    {
        FaqItem::create(['question' => 'Q1', 'answer' => 'A1', 'status' => 'draft']);

        $response = $this->withHeaders($this->authHeaders($this->patient()))->getJson('/api/support/faq');

        $response->assertStatus(200);
        $response->assertJsonCount(0);
    }

    public function test_admin_sees_both_published_and_draft(): void
    {
        FaqItem::create(['question' => 'Q1', 'answer' => 'A1', 'status' => 'published']);
        FaqItem::create(['question' => 'Q2', 'answer' => 'A2', 'status' => 'draft']);

        $response = $this->withHeaders($this->authHeaders($this->agent()))->getJson('/api/admin/faq');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
    }

    public function test_admin_can_create_faq(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->agent()))
            ->postJson('/api/admin/faq', [
                'question' => 'سوال جدید؟',
                'answer' => 'پاسخ جدید',
                'category' => 'حساب کاربری',
                'status' => 'draft',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'draft');
        $this->assertDatabaseCount('faq_items', 1);
    }

    public function test_admin_can_publish_a_draft(): void
    {
        $item = FaqItem::create(['question' => 'Q1', 'answer' => 'A1', 'status' => 'draft']);

        $response = $this->withHeaders($this->authHeaders($this->agent()))
            ->patchJson("/api/admin/faq/{$item->id}", ['status' => 'published']);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'published');
    }

    public function test_normal_user_cannot_create_faq(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->patient()))
            ->postJson('/api/admin/faq', ['question' => 'x', 'answer' => 'y', 'status' => 'draft']);

        $response->assertStatus(403);
    }
}
