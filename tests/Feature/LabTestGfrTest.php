<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ScheduledSMS;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class LabTestGfrTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONAL_LABS = ['calcium' => 9.35, 'phosphorous' => 3.93, 'bCarbonate' => 25.54];

    private function submit(string $gender, array $optionalLabs = self::OPTIONAL_LABS): array
    {
        $user = User::factory()->create([
            'role' => 'user',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
        Payment::create([
            'user_id' => $user->id,
            'amount' => 1000,
            'authority' => Str::random(20),
            'status' => 'success',
            'is_used_lab_test' => false,
            'is_used_insurance' => false,
        ]);

        $this->resetJwtState();

        $data = $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user)])
            ->postJson('/api/lab-tests', [
                'age' => 50,
                'gender' => $gender,
                'creatinine' => 1.0,
                'urine_creatinine' => 100,
                'urine_albumin' => 30,
                'albumin' => 4.0,
            ] + $optionalLabs)
            ->assertCreated()
            ->json('data');

        return [$user, $data];
    }

    /**
     * Reference values from the CKD-EPI 2021 creatinine equation for a 50-year-old with creatinine
     * 1.0 mg/dL: 68.6 for a woman (stage 2), 91.7 for a man (stage 1).
     */
    public function test_women_are_scored_with_the_female_equation(): void
    {
        [$user, $test] = $this->submit('f');

        $this->assertEqualsWithDelta(68.63, $test['gfr'], 0.01);
        $this->assertSame(2, $test['stage']);

        // The stage decides which reminder segment the patient lands in.
        $this->assertSame(
            ['2'],
            ScheduledSMS::where('user_id', $user->id)->pluck('token2')->unique()->values()->all()
        );
    }

    public function test_men_are_scored_with_the_male_equation(): void
    {
        [, $test] = $this->submit('m');

        $this->assertEqualsWithDelta(91.69, $test['gfr'], 0.01);
        $this->assertSame(1, $test['stage']);
    }

    public function test_a_lab_test_without_bicarbonate_is_saved(): void
    {
        [$user, $test] = $this->submit('m', ['calcium' => 9.35, 'phosphorous' => 3.93]);

        $this->assertNull($test['b_carbonate']);
        $this->assertEqualsWithDelta(91.69, $test['gfr'], 0.01);
        $this->assertDatabaseHas('lab_tests', ['id' => $test['id'], 'b_carbonate' => null]);
        $this->assertDatabaseHas('payments', ['user_id' => $user->id, 'is_used_lab_test' => true]);
        $this->assertSame(3, ScheduledSMS::where('lab_test_id', $test['id'])->count());
    }

    public function test_a_lab_test_without_any_optional_value_is_saved(): void
    {
        [, $test] = $this->submit('f', []);

        $this->assertNull($test['calcium']);
        $this->assertNull($test['phosphorous']);
        $this->assertNull($test['b_carbonate']);
        $this->assertSame(2, $test['stage']);
    }
}
