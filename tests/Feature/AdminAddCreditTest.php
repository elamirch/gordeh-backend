<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminAddCreditTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/credits';

    private function headers(User $user): array
    {
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function userWithRole(string $role, string $phone): User
    {
        return User::factory()->create(['role' => $role, 'phone_number' => $phone]);
    }

    public function test_admin_adds_credits_by_phone_number(): void
    {
        $admin = $this->userWithRole('admin', '09120000000');
        $patient = $this->userWithRole('user', '09123456789');

        $response = $this->withHeaders($this->headers($admin))
            ->postJson(self::URL, ['phoneNumber' => '+989123456789', 'count' => 3]);

        $response->assertStatus(201)
            ->assertJson(['success' => true, 'added' => 3, 'availableCredits' => 3]);

        $this->assertDatabaseCount('payments', 3);
        $this->assertDatabaseHas('payments', [
            'user_id' => $patient->id,
            'status' => 'success',
            'is_used_lab_test' => false,
            'is_used_insurance' => false,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'credit.added']);
    }

    public function test_accepts_local_phone_format(): void
    {
        $admin = $this->userWithRole('admin', '09120000000');
        $patient = $this->userWithRole('user', '09123456789');

        $this->withHeaders($this->headers($admin))
            ->postJson(self::URL, ['phoneNumber' => '09123456789', 'count' => 1])
            ->assertStatus(201);

        $this->assertDatabaseHas('payments', ['user_id' => $patient->id]);
    }

    public function test_unknown_phone_returns_404(): void
    {
        $admin = $this->userWithRole('admin', '09120000000');

        $this->withHeaders($this->headers($admin))
            ->postJson(self::URL, ['phoneNumber' => '09129999999', 'count' => 1])
            ->assertStatus(404);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_validates_count(): void
    {
        $admin = $this->userWithRole('admin', '09120000000');
        $this->userWithRole('user', '09123456789');

        $this->withHeaders($this->headers($admin))
            ->postJson(self::URL, ['phoneNumber' => '09123456789', 'count' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('count');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $patient = $this->userWithRole('user', '09123456789');

        $this->withHeaders($this->headers($patient))
            ->postJson(self::URL, ['phoneNumber' => '09123456789', 'count' => 5])
            ->assertStatus(403);

        $this->assertDatabaseCount('payments', 0);
    }
}
