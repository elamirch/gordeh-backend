<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminCreateSpecialistTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/nutrition-monitoring/specialists';

    private function headers(User $user): array
    {
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function userWithRole(string $role, string $phone): User
    {
        return User::factory()->create(['role' => $role, 'phone_number' => $phone]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'phoneNumber' => '+989123456789',
            'firstName' => 'سارا',
            'lastName' => 'مرادی',
            'specialty' => 'کارشناس ارشد تغذیه',
            'email' => 'sara@example.com',
            'credentials' => 'نظام پزشکی 12345',
        ], $over);
    }

    public function test_creates_new_user_as_provider(): void
    {
        $admin = $this->userWithRole('admin', '09100000001');

        $this->postJson(self::URL, $this->payload(), $this->headers($admin))
            ->assertCreated()
            ->assertJson([
                'name' => 'سارا مرادی',
                'phoneNumber' => '+989123456789',
                'role' => 'provider',
                'capacity' => 20,
                'active' => true,
            ]);

        $user = User::where('phone_number', '09123456789')->first();
        $this->assertSame('provider', $user->role);
        $this->assertDatabaseHas('provider_profiles', ['user_id' => $user->id, 'is_active' => true]);
        $this->assertTrue(AuditLog::where('action', 'specialist.created')->where('actor_id', $admin->id)->exists());

        $this->getJson(self::URL, $this->headers($admin))
            ->assertOk()
            ->assertJsonFragment(['id' => $user->id, 'active' => true, 'capacity' => 20]);
    }

    public function test_promotes_existing_user(): void
    {
        $admin = $this->userWithRole('admin', '09100000001');
        $user = $this->userWithRole('user', '09123456789');

        $this->postJson(self::URL, $this->payload(['phoneNumber' => '09123456789', 'capacity' => 5]), $this->headers($admin))
            ->assertCreated()
            ->assertJson(['id' => $user->id, 'role' => 'provider', 'capacity' => 5]);

        $this->assertSame('provider', $user->fresh()->role);
        $this->assertSame(1, ProviderProfile::where('user_id', $user->id)->count());
    }

    public function test_rejects_admin_target(): void
    {
        $admin = $this->userWithRole('admin', '09100000001');
        $this->userWithRole('admin', '09123456789');

        $this->postJson(self::URL, $this->payload(), $this->headers($admin))->assertStatus(422);
        $this->assertSame('admin', User::where('phone_number', '09123456789')->value('role'));
    }

    public function test_duplicate_provider_returns_409(): void
    {
        $admin = $this->userWithRole('admin', '09100000001');
        $this->userWithRole('provider', '09123456789');

        $this->postJson(self::URL, $this->payload(), $this->headers($admin))->assertStatus(409);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = $this->userWithRole('user', '09100000002');

        $this->postJson(self::URL, $this->payload(), $this->headers($user))->assertForbidden();
    }
}
