<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ProviderWeeklyHours;
use App\Models\User;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): User
    {
        return User::factory()->create(['role' => 'provider', 'phone_number' => '09120000002']);
    }

    public function test_slot_length_is_fifteen_minutes(): void
    {
        $this->assertSame(15, ScheduleService::SLOT_MINUTES);
    }

    public function test_slots_are_generated_every_fifteen_minutes(): void
    {
        $provider = $this->provider();
        $date = Carbon::parse('2026-10-10');
        ProviderWeeklyHours::create([
            'provider_id' => $provider->id,
            'd' => $date->dayOfWeek,
            'on' => true,
            'slots' => [['09:00', '10:00']],
        ]);

        $times = array_column((new ScheduleService)->slotsForDate($date, $provider->id), 'time');

        $this->assertSame(['09:00', '09:15', '09:30', '09:45'], $times);
    }

    public function test_booked_slot_is_unavailable_only_for_that_time(): void
    {
        $provider = $this->provider();
        $patient = User::factory()->create(['role' => 'patient', 'phone_number' => '09120000001']);
        $date = Carbon::parse('2026-10-10');
        ProviderWeeklyHours::create([
            'provider_id' => $provider->id,
            'd' => $date->dayOfWeek,
            'on' => true,
            'slots' => [['09:00', '10:00']],
        ]);
        Appointment::create([
            'user_id' => $patient->id,
            'provider_id' => $provider->id,
            'date' => $date->toDateString(),
            'time' => '09:15',
            'status' => 'confirmed',
        ]);

        $slots = collect((new ScheduleService)->slotsForDate($date, $provider->id))->pluck('available', 'time');

        $this->assertFalse($slots['09:15']);
        $this->assertTrue($slots['09:00']);
        $this->assertTrue($slots['09:30']);
    }

    public function test_legacy_thirty_minute_appointment_blocks_both_slots(): void
    {
        $provider = $this->provider();
        $patient = User::factory()->create(['role' => 'patient', 'phone_number' => '09120000001']);
        $date = Carbon::parse('2026-10-10');
        ProviderWeeklyHours::create([
            'provider_id' => $provider->id,
            'd' => $date->dayOfWeek,
            'on' => true,
            'slots' => [['09:00', '10:00']],
        ]);
        Appointment::create([
            'user_id' => $patient->id,
            'provider_id' => $provider->id,
            'date' => $date->toDateString(),
            'time' => '09:00',
            'duration_minutes' => 30,
            'status' => 'confirmed',
        ]);

        $slots = collect((new ScheduleService)->slotsForDate($date, $provider->id))->pluck('available', 'time');

        $this->assertFalse($slots['09:00']);
        $this->assertFalse($slots['09:15']);
        $this->assertTrue($slots['09:30']);
    }
}
