<?php

namespace Tests\Feature;

use App\Models\Insurance;
use App\Models\LabTest;
use App\Models\ScheduledSMS;
use App\Models\User;
use App\Services\ReminderScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsuranceRemindersStopAfterTestTest extends TestCase
{
    use RefreshDatabase;

    private function userWithInsuranceReminders(): User
    {
        $user = User::factory()->create(['role' => 'user', 'phone_number' => '09'.fake()->unique()->numerify('#########')]);
        $insurance = Insurance::create([
            'user_id' => $user->id, 'national_code' => '0012345678', 'insurance_type' => 'tamin', 'status' => 'created',
        ]);
        (new ReminderScheduler)->scheduleInsuranceReminders($user, $insurance);

        return $user;
    }

    private function labTest(User $user): LabTest
    {
        return LabTest::create([
            'user_id' => $user->id, 'age' => 40, 'gender' => 'm', 'creatinine' => 1.1, 'urine_creatinine' => 90,
            'albumin' => 4.0, 'urine_albumin' => 20, 'gfr' => 80, 'stage' => 2, 'risk_2_years' => 5, 'risk_5_years' => 10,
            'albumin_creatinine_ratio' => 22.2,
        ]);
    }

    private function pendingInsuranceReminders(User $user): int
    {
        return ScheduledSMS::where('user_id', $user->id)->where('template', 'like', 'cron-insurance%')
            ->where('status', 'pending')->count();
    }

    public function test_entering_a_test_drops_pending_insurance_reminders(): void
    {
        $user = $this->userWithInsuranceReminders();
        $this->assertSame(3, $this->pendingInsuranceReminders($user));

        (new ReminderScheduler)->scheduleAssessmentReminders($user, $this->labTest($user));

        $this->assertSame(0, $this->pendingInsuranceReminders($user));
    }

    public function test_sent_insurance_reminders_are_kept(): void
    {
        $user = $this->userWithInsuranceReminders();
        ScheduledSMS::where('user_id', $user->id)->where('template', 'cron-insurance-reminder-7d')->update(['status' => 'sent']);

        (new ReminderScheduler)->scheduleAssessmentReminders($user, $this->labTest($user));

        $this->assertSame(1, ScheduledSMS::where('user_id', $user->id)->where('template', 'like', 'cron-insurance%')->count());
    }

    public function test_cleanup_command_drops_reminders_of_users_who_already_entered_a_test(): void
    {
        $tested = $this->userWithInsuranceReminders();
        $this->labTest($tested);
        $untested = $this->userWithInsuranceReminders();

        $this->artisan('gordeh:cancel-insurance-reminders-after-test', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, $this->pendingInsuranceReminders($tested));

        $this->artisan('gordeh:cancel-insurance-reminders-after-test')->assertSuccessful();
        $this->assertSame(0, $this->pendingInsuranceReminders($tested));
        $this->assertSame(3, $this->pendingInsuranceReminders($untested));
    }
}
