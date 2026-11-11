<?php

namespace Tests\Feature;

use App\Models\ConsultationBooking;
use App\Models\ProviderProfile;
use App\Models\ProviderWeeklyHours;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ConsultationSpecialistChoiceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
            // A booking is paid from the wallet.
            'wallet_balance' => 50_000_000,
        ]);
    }

    private function as(User $user): array
    {
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    /** A specialist working 10:00–11:00 tomorrow. */
    private function specialist(bool $active = true): User
    {
        $provider = $this->user('provider');
        ProviderProfile::create(['user_id' => $provider->id, 'specialty' => 'تغذیه', 'is_active' => $active]);
        ProviderWeeklyHours::create([
            'provider_id' => $provider->id,
            'd' => now()->addDay()->dayOfWeek,
            'on' => true,
            'slots' => [['10:00', '11:00']],
        ]);

        return $provider;
    }

    private function bookingPayload(User $provider, array $over = []): array
    {
        return array_merge([
            'provider_id' => $provider->id,
            'type' => 'initial',
            'date' => now()->addDay()->toDateString(),
            'time' => '10:00',
            'full_name' => 'Test Patient',
            'phone_number' => '09120000000',
            'kidney_stage' => '3',
        ], $over);
    }

    public function test_specialist_list_hides_inactive_specialists(): void
    {
        $active = $this->specialist();
        $inactive = $this->specialist(active: false);

        $ids = collect($this->getJson('/api/nutrition-consultation/specialists', $this->as($this->user('user')))
            ->assertOk()->json())->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_booking_is_assigned_to_the_chosen_specialist(): void
    {
        $provider = $this->specialist();

        $this->postJson('/api/nutrition-consultation/bookings', $this->bookingPayload($provider), $this->as($this->user('user')))
            ->assertCreated();

        $this->assertSame($provider->id, ConsultationBooking::first()->provider_id);
    }

    public function test_inactive_specialist_cannot_be_booked(): void
    {
        $provider = $this->specialist(active: false);

        $this->postJson('/api/nutrition-consultation/bookings', $this->bookingPayload($provider), $this->as($this->user('user')))
            ->assertUnprocessable()->assertJsonValidationErrors('provider_id');
    }

    public function test_booked_slot_is_taken_for_that_specialist_only(): void
    {
        $provider = $this->specialist();
        $other = $this->specialist();

        $this->postJson('/api/nutrition-consultation/bookings', $this->bookingPayload($provider), $this->as($this->user('user')))
            ->assertCreated();

        $this->postJson('/api/nutrition-consultation/bookings', $this->bookingPayload($provider), $this->as($this->user('user')))
            ->assertUnprocessable()->assertJsonValidationErrors('time');

        $this->postJson('/api/nutrition-consultation/bookings', $this->bookingPayload($other), $this->as($this->user('user')))
            ->assertCreated();
    }

    public function test_inactive_specialist_has_no_free_slots(): void
    {
        $this->specialist(active: false);
        $date = now()->addDay()->toDateString();

        $slots = $this->getJson("/api/nutrition-consultation/availability?date={$date}", $this->as($this->user('user')))
            ->assertOk()->json('slots');

        $this->assertSame([], $slots);
    }

    public function test_admin_can_deactivate_a_specialist(): void
    {
        $provider = $this->specialist();

        $this->patchJson("/api/admin/nutrition-monitoring/specialists/{$provider->id}", ['active' => false], $this->as($this->user('admin')))
            ->assertOk()->assertJson(['active' => false]);

        $this->assertFalse($provider->providerProfile()->first()->is_active);
    }

    public function test_non_admin_cannot_toggle_a_specialist(): void
    {
        $provider = $this->specialist();

        $this->patchJson("/api/admin/nutrition-monitoring/specialists/{$provider->id}", ['active' => false], $this->as($provider))
            ->assertForbidden();
    }
}
