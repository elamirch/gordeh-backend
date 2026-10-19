<?php

namespace Tests\Feature;

use App\Models\Insurance;
use App\Models\LabTest;
use App\Models\ScheduledSMS;
use App\Models\User;
use App\Services\ReminderScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RestoreMissedRemindersTest extends TestCase
{
    use RefreshDatabase;

    /** What production had after the first gordeh:sms run: every missed row's send_at overwritten. */
    private const OVERWRITTEN = '2026-10-19 15:32:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-19 16:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function patient(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    private function missed(User $user, string $template, array $attributes = []): ScheduledSMS
    {
        return ScheduledSMS::create(array_merge([
            'user_id' => $user->id,
            'phone_number' => $user->phone_number,
            'template' => $template,
            'token' => 'Amir',
            'send_at' => self::OVERWRITTEN,
            'status' => 'missed',
        ], $attributes));
    }

    private function insuranceCreatedDaysAgo(User $user, int $days): Insurance
    {
        $insurance = Insurance::create([
            'user_id' => $user->id,
            'national_code' => '0012345678',
            'insurance_type' => 'tamin',
            'status' => 'created',
        ]);
        $insurance->created_at = now()->subDays($days);
        $insurance->save();

        return $insurance;
    }

    private function labTest(User $user, int $stage, int $daysAgo): LabTest
    {
        $test = LabTest::create([
            'user_id' => $user->id, 'age' => 40, 'gender' => 'm', 'creatinine' => 1.1,
            'urine_creatinine' => 90, 'albumin' => 4.0, 'urine_albumin' => 20, 'gfr' => 80,
            'stage' => $stage, 'risk_2_years' => 5, 'risk_5_years' => 10, 'albumin_creatinine_ratio' => 22.2,
        ]);
        $test->created_at = now()->subDays($daysAgo);
        $test->save();

        return $test;
    }

    public function test_insurance_reminders_still_ahead_go_back_in_the_queue(): void
    {
        $user = $this->patient();
        $insurance = $this->insuranceCreatedDaysAgo($user, 10);
        $sevenDay = $this->missed($user, 'cron-insurance-reminder-7d', ['insurance_id' => $insurance->id]);
        $fourteenDay = $this->missed($user, 'cron-insurance-reminder-14d', ['insurance_id' => $insurance->id]);
        $twentyFiveDay = $this->missed($user, 'cron-insurance-reminder-25d', ['insurance_id' => $insurance->id]);

        $this->artisan('gordeh:restore-missed-reminders', ['--apply' => true])->assertSuccessful();

        $scheduler = new ReminderScheduler;

        // Day 7 was three days ago: really missed, only its real time is put back.
        $this->assertSame('missed', $sevenDay->fresh()->status);
        $this->assertTrue($sevenDay->fresh()->send_at->eq($scheduler->sendAt($insurance->created_at, 7)));

        foreach ([[$fourteenDay, 14], [$twentyFiveDay, 25]] as [$sms, $days]) {
            $this->assertSame('pending', $sms->fresh()->status);
            $this->assertTrue($sms->fresh()->send_at->eq($scheduler->sendAt($insurance->created_at, $days)));
        }
    }

    public function test_assessment_reminders_of_the_latest_test_are_restored(): void
    {
        $user = $this->patient();
        $old = $this->labTest($user, 2, 400);
        $latest = $this->labTest($user, 4, 20); // due 60 days after the test: 40 days from now
        $oldRow = $this->missed($user, 'cron-assess-reminder', ['lab_test_id' => $old->id, 'token2' => '2']);
        $due = $this->missed($user, 'cron-assess-reminder', ['lab_test_id' => $latest->id, 'token2' => '4']);
        $followUp = $this->missed($user, 'cron-assess-reminder-after-14d-onlyhigh', ['lab_test_id' => $latest->id, 'token2' => '4']);

        $this->artisan('gordeh:restore-missed-reminders', ['--apply' => true])->assertSuccessful();

        $dueAt = (new ReminderScheduler)->assessmentDueAt($latest);
        $this->assertSame('pending', $due->fresh()->status);
        $this->assertTrue($due->fresh()->send_at->eq($dueAt));
        $this->assertTrue($followUp->fresh()->send_at->eq($dueAt->copy()->addDays(14)));

        // Reminders of a superseded test are left as they are.
        $this->assertSame('missed', $oldRow->fresh()->status);
        $this->assertSame(self::OVERWRITTEN, $oldRow->fresh()->send_at->utc()->toDateTimeString());
    }

    public function test_without_apply_nothing_is_written(): void
    {
        $user = $this->patient();
        $insurance = $this->insuranceCreatedDaysAgo($user, 2);
        $sms = $this->missed($user, 'cron-insurance-reminder-7d', ['insurance_id' => $insurance->id]);

        $this->artisan('gordeh:restore-missed-reminders')
            ->expectsOutputToContain('Run again with --apply')
            ->assertSuccessful();

        $this->assertSame('missed', $sms->fresh()->status);
    }

    public function test_replacements_that_are_no_longer_needed_are_listed_not_touched(): void
    {
        $user = $this->patient();
        $insurance = $this->insuranceCreatedDaysAgo($user, 2);
        $this->missed($user, 'cron-insurance-reminder-7d', ['insurance_id' => $insurance->id]);
        $replacement = $this->missed($user, 'cron-insurance-reminder-missed', ['status' => 'failed', 'error' => 'Kavenegar 424']);

        $this->artisan('gordeh:restore-missed-reminders', ['--apply' => true])
            ->expectsOutputToContain('Do not retry them')
            ->assertSuccessful();

        $this->assertSame('failed', $replacement->fresh()->status);
    }
}
