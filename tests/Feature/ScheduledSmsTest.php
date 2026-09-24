<?php

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Models\LabTest;
use App\Models\Payment;
use App\Models\ScheduledSMS;
use App\Models\User;
use App\Services\ReminderScheduler;
use App\Services\SendSMS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ScheduledSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 20:30 UTC is already 00:00 the next day in Tehran, which is where reminder dates are computed.
        Carbon::setTestNow(Carbon::parse('2026-09-24 20:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function patient(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'user',
            'first_name' => 'Amir',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ], $attributes));
    }

    private function paidFor(User $user): void
    {
        Payment::create([
            'user_id' => $user->id,
            'amount' => 1000,
            'authority' => Str::random(20),
            'status' => 'success',
            'is_used_lab_test' => false,
            'is_used_insurance' => false,
        ]);
    }

    private function labTestFor(User $user, int $stage): LabTest
    {
        return LabTest::create([
            'user_id' => $user->id,
            'age' => 40,
            'gender' => 'm',
            'creatinine' => 1.1,
            'urine_creatinine' => 90,
            'albumin' => 4.0,
            'urine_albumin' => 20,
            'gfr' => 80,
            'calcium' => 9.3,
            'phosphorous' => 3.9,
            'b_carbonate' => 25.5,
            'stage' => $stage,
            'risk_2_years' => 5,
            'risk_5_years' => 10,
            'albumin_creatinine_ratio' => 22.2,
        ]);
    }

    private function sendAtByTemplate(User $user): array
    {
        return ScheduledSMS::where('user_id', $user->id)
            ->where('status', 'pending')
            ->get()
            ->mapWithKeys(fn ($sms) => [$sms->template => $sms->send_at->utc()->toDateTimeString()])
            ->all();
    }

    public function test_submitting_a_lab_test_schedules_reminders_on_their_due_dates(): void
    {
        $user = $this->patient();
        $this->paidFor($user);

        $response = $this->postJson('/api/lab-tests', [
            'age' => 40,
            'gender' => 'm',
            'urine_creatinine' => 90,
            'urine_albumin' => 20,
            'creatinine' => 1.0,
            'albumin' => 4.0,
            'calcium' => 9.3,
            'phosphorous' => 3.9,
            'bCarbonate' => 25.5,
        ], ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)]);

        $response->assertCreated();
        $stage = $response->json('data.stage');
        $plan = ReminderScheduler::ASSESSMENT_PLAN[$stage];

        // Due date = Tehran-local 2026-09-25 + due days, at 10:00 Tehran (06:30 UTC).
        $due = Carbon::parse('2026-09-25 06:30:00', 'UTC')->addDays($plan['due']);

        $this->assertSame([
            'cron-assess-reminder-7d' => $due->copy()->subDays(7)->toDateTimeString(),
            'cron-assess-reminder' => $due->toDateTimeString(),
            $plan['follow_up_template'] => $due->copy()->addDays($plan['follow_up'])->toDateTimeString(),
        ], $this->sendAtByTemplate($user));
    }

    public function test_reminder_times_and_tokens_for_a_stage_3_test(): void
    {
        $user = $this->patient(['first_name' => 'Amir  Mahdi']);
        $test = $this->labTestFor($user, 3);

        $this->assertSame(3, (new ReminderScheduler)->scheduleAssessmentReminders($user, $test));

        $this->assertSame([
            'cron-assess-reminder-7d' => '2027-01-16 06:30:00',
            'cron-assess-reminder' => '2027-01-23 06:30:00',
            'cron-assess-reminder-after-14d-onlyhigh' => '2027-02-06 06:30:00',
        ], $this->sendAtByTemplate($user));

        $sevenDay = ScheduledSMS::where('template', 'cron-assess-reminder-7d')->first();
        $this->assertSame("Amir\u{200C}Mahdi", $sevenDay->token);
        $this->assertSame('3', (string) $sevenDay->token2);
        // The appointment date in the text is the due date (2027-01-23 = 1405/11/3), not the send date.
        $this->assertSame('1405/11/3', $sevenDay->token3);
    }

    public function test_a_new_lab_test_replaces_pending_reminders_of_the_previous_one(): void
    {
        $user = $this->patient();
        $scheduler = new ReminderScheduler;

        $first = $this->labTestFor($user, 1);
        $scheduler->scheduleAssessmentReminders($user, $first);
        $second = $this->labTestFor($user, 4);
        $scheduler->scheduleAssessmentReminders($user, $second);

        $this->assertSame(3, ScheduledSMS::where('user_id', $user->id)->count());
        $this->assertSame(0, ScheduledSMS::where('lab_test_id', $first->id)->count());
    }

    public function test_name_token_is_safe_for_kavenegar(): void
    {
        $this->assertSame('کاربر', ReminderScheduler::nameToken(null));
        $this->assertSame('کاربر', ReminderScheduler::nameToken('  '));
        $this->assertSame("محمد\u{200C}علی", ReminderScheduler::nameToken(' محمد علی '));
        $this->assertSame(50, mb_strlen(ReminderScheduler::nameToken(str_repeat('a', 80))));
    }

    public function test_dispatcher_only_claims_due_pending_reminders(): void
    {
        Queue::fake();
        $user = $this->patient();

        $due = ScheduledSMS::create(['user_id' => $user->id, 'phone_number' => $user->phone_number, 'template' => 'cron-insurance-reminder-7d', 'send_at' => now()->subMinute()]);
        $future = ScheduledSMS::create(['user_id' => $user->id, 'phone_number' => $user->phone_number, 'template' => 'cron-insurance-reminder-14d', 'send_at' => now()->addDay()]);

        $this->artisan('gordeh:sms')->assertSuccessful();

        Queue::assertPushed(SendSmsJob::class, 1);
        Queue::assertPushed(SendSmsJob::class, fn ($job) => $job->smsId === $due->id);
        $this->assertSame('processing', $due->fresh()->status);
        $this->assertSame('pending', $future->fresh()->status);
    }

    public function test_job_marks_reminder_sent_when_kavenegar_accepts_it(): void
    {
        $user = $this->patient();
        $sms = ScheduledSMS::create(['user_id' => $user->id, 'phone_number' => $user->phone_number, 'template' => 'cron-insurance-reminder-7d', 'token' => null, 'send_at' => now(), 'status' => 'processing']);

        $this->mock(SendSMS::class)
            ->shouldReceive('insuranceReminder')
            ->once()
            ->with($user->phone_number, 'کاربر', 'cron-insurance-reminder-7d')
            ->andReturn(json_decode('{"return":{"status":200,"message":"ok"}}'));

        SendSmsJob::dispatchSync($sms->id);

        $sms->refresh();
        $this->assertSame('sent', $sms->status);
        $this->assertNotNull($sms->sent_at);
    }

    public function test_job_marks_reminder_failed_when_kavenegar_rejects_it(): void
    {
        $user = $this->patient();
        $sms = ScheduledSMS::create(['user_id' => $user->id, 'phone_number' => $user->phone_number, 'template' => 'cron-assess-reminder', 'token' => 'Amir', 'token2' => '2', 'send_at' => now(), 'status' => 'processing']);

        $this->mock(SendSMS::class)
            ->shouldReceive('assessmentReminder')
            ->once()
            ->andReturn(json_decode('{"return":{"status":424,"message":"template not found"}}'));

        try {
            SendSmsJob::dispatchSync($sms->id);
            $this->fail('Expected the job to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('424', $e->getMessage());
        }

        $sms->refresh();
        $this->assertSame('failed', $sms->status);
        $this->assertStringContainsString('template not found', $sms->error);
    }

    public function test_repair_command_reschedules_reminders_that_went_out_immediately(): void
    {
        $user = $this->patient();

        // Buggy data: a stage 2 test submitted 10 days ago, with every reminder set to fire right then.
        Carbon::setTestNow(now()->subDays(10));
        $test = $this->labTestFor($user, 2);
        foreach (['cron-assess-reminder-7d', 'cron-assess-reminder', 'cron-assess-reminder-after'] as $template) {
            ScheduledSMS::create(['user_id' => $user->id, 'phone_number' => $user->phone_number, 'template' => $template, 'token' => 'Amir', 'token2' => '2', 'send_at' => now(), 'status' => 'sent', 'lab_test_id' => $test->id]);
        }
        Carbon::setTestNow(Carbon::parse('2026-09-24 20:30:00', 'UTC'));

        $this->artisan('gordeh:repair-assessment-reminders', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, ScheduledSMS::where('status', 'pending')->count());

        $this->artisan('gordeh:repair-assessment-reminders')->assertSuccessful();

        // Test was created 2026-09-14 20:30 UTC = 2026-09-15 in Tehran; stage 2 is due 182 days later.
        $this->assertSame([
            'cron-assess-reminder-7d' => '2027-03-09 06:30:00',
            'cron-assess-reminder' => '2027-03-16 06:30:00',
            'cron-assess-reminder-after' => '2027-04-15 06:30:00',
        ], $this->sendAtByTemplate($user));

        // Running it again changes nothing.
        $this->artisan('gordeh:repair-assessment-reminders')->assertSuccessful();
        $this->assertSame(3, ScheduledSMS::where('status', 'pending')->count());
    }
}
