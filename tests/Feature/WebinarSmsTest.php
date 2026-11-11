<?php

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Models\ScheduledSMS;
use App\Models\User;
use App\Models\WebinarRegistration;
use App\Services\ReminderScheduler;
use App\Services\SendSMS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WebinarSmsTest extends TestCase
{
    use RefreshDatabase;

    private const WEBINAR = 'test-webinar';

    protected function setUp(): void
    {
        parent::setUp();

        // Sunday 2026-10-18 13:30 Tehran; the webinar is Wednesday 2026-10-21 21:00 Tehran (17:30 UTC).
        Carbon::setTestNow(Carbon::parse('2026-10-18 10:00:00', 'UTC'));
        config(['webinars.'.self::WEBINAR => [
            'starts_at' => '2026-10-21 21:00',
        ]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function register(string $phone = '09121234567', string $webinar = self::WEBINAR)
    {
        return $this->postJson('/api/webinar-registrations', [
            'webinar' => $webinar,
            'name' => 'Amir Mahdi',
            'phone' => $phone,
        ]);
    }

    private function scheduled(): array
    {
        return ScheduledSMS::orderBy('id')->get()
            ->mapWithKeys(fn ($sms) => [$sms->template => [
                $sms->send_at->utc()->toDateTimeString(),
                $sms->token,
                $sms->token2,
                $sms->token3,
            ]])
            ->all();
    }

    public function test_registering_schedules_four_reminders_and_no_confirmation(): void
    {
        $this->register()->assertCreated();

        $this->assertSame([
            'webinar-reminder-1d' => ['2026-10-20 06:30:00', 'AmirMahdi', '21:00', null],
            'webinar-reminder-today' => ['2026-10-21 06:30:00', 'AmirMahdi', '21:00', null],
            'webinar-reminder-1h' => ['2026-10-21 16:30:00', 'AmirMahdi', null, null],
            'webinar-reminder-start' => ['2026-10-21 17:30:00', 'AmirMahdi', null, null],
        ], $this->scheduled());

        $registration = WebinarRegistration::first();
        $this->assertSame(4, ScheduledSMS::where('webinar_registration_id', $registration->id)->count());
        $this->assertSame('09121234567', ScheduledSMS::first()->phone_number);
    }

    public function test_reminders_whose_time_has_passed_are_skipped(): void
    {
        // The day of the webinar, 15:00 Tehran: the day-before and morning reminders are gone.
        Carbon::setTestNow(Carbon::parse('2026-10-21 11:30:00', 'UTC'));

        $this->register()->assertCreated();

        $this->assertSame(['webinar-reminder-1h', 'webinar-reminder-start'], array_keys($this->scheduled()));
    }

    public function test_registering_again_does_not_send_anything_twice(): void
    {
        $this->register()->assertCreated();
        $this->register()->assertOk()->assertJsonPath('alreadyRegistered', true);

        $this->assertSame(4, ScheduledSMS::count());
    }

    public function test_no_sms_for_a_webinar_without_a_configured_date_or_that_already_started(): void
    {
        $this->register('09121234567', 'unknown-webinar')->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-10-21 18:00:00', 'UTC'));
        $this->register('09121234568')->assertCreated();

        $this->assertSame(0, ScheduledSMS::count());
    }

    public function test_admin_added_registrations_get_reminders_and_removing_one_drops_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'phone_number' => '09120000000']);
        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($admin)];

        $id = $this->withHeaders($headers)->postJson('/api/admin/webinar-registrations', [
            'webinar' => self::WEBINAR,
            'name' => 'Sara',
            'phone' => '09351234567',
        ])->assertCreated()->json('id');

        $this->assertSame(4, ScheduledSMS::where('webinar_registration_id', $id)->count());

        $this->withHeaders($headers)->deleteJson("/api/admin/webinar-registrations/{$id}")->assertOk();

        $this->assertSame(0, ScheduledSMS::count());
    }

    public function test_one_hour_reminder_is_dropped_once_it_is_more_than_30_minutes_late(): void
    {
        $this->register()->assertCreated();
        $sms = ScheduledSMS::where('template', 'webinar-reminder-1h')->first();

        // Worker was down: it's 17:05 UTC (20:35 Tehran), 35 minutes after the reminder was due.
        Carbon::setTestNow(Carbon::parse('2026-10-21 17:05:00', 'UTC'));
        $sms->update(['status' => 'processing']);

        $this->mock(SendSMS::class)->shouldReceive('reminder')->never();

        SendSmsJob::dispatchSync($sms->id);

        $this->assertSame('missed', $sms->fresh()->status);
        // There is no "you missed it" replacement for webinar reminders.
        $this->assertSame(4, ScheduledSMS::count());
    }

    public function test_start_message_is_dropped_once_it_is_more_than_15_minutes_late(): void
    {
        $this->register()->assertCreated();
        $sms = ScheduledSMS::where('template', 'webinar-reminder-start')->first();

        Carbon::setTestNow(Carbon::parse('2026-10-21 17:50:00', 'UTC'));
        $sms->update(['status' => 'processing']);

        $this->mock(SendSMS::class)->shouldReceive('reminder')->never();

        SendSmsJob::dispatchSync($sms->id);

        $this->assertSame('missed', $sms->fresh()->status);
    }

    public function test_day_before_reminder_sends_the_start_time_in_token10(): void
    {
        $this->register()->assertCreated();
        $sms = ScheduledSMS::where('template', 'webinar-reminder-1d')->first();

        Carbon::setTestNow(Carbon::parse('2026-10-20 06:31:00', 'UTC'));
        $sms->update(['status' => 'processing']);

        $this->mock(SendSMS::class)
            ->shouldReceive('reminder')
            ->once()
            ->with('09121234567', 'webinar-reminder-1d', 'AmirMahdi', null, null, 'ساعت 21')
            ->andReturn(json_decode('{"return":{"status":200,"message":"ok"}}'));

        SendSmsJob::dispatchSync($sms->id);

        $this->assertSame('sent', $sms->fresh()->status);
    }

    public function test_start_times_with_minutes_are_spelled_out(): void
    {
        $sms = new ScheduledSMS(['template' => 'webinar-reminder-today', 'token' => 'Sara', 'token2' => '21:30']);

        $this->assertSame(['Sara', null, null, 'ساعت 21 و 30 دقیقه'], ReminderScheduler::lookupTokens($sms));
    }

    public function test_one_hour_reminder_is_sent_with_only_the_name(): void
    {
        $this->register()->assertCreated();
        $sms = ScheduledSMS::where('template', 'webinar-reminder-1h')->first();

        Carbon::setTestNow(Carbon::parse('2026-10-21 16:31:00', 'UTC'));
        $sms->update(['status' => 'processing']);

        $this->mock(SendSMS::class)
            ->shouldReceive('reminder')
            ->once()
            ->with('09121234567', 'webinar-reminder-1h', 'AmirMahdi', null, null, null)
            ->andReturn(json_decode('{"return":{"status":200,"message":"ok"}}'));

        SendSmsJob::dispatchSync($sms->id);

        $this->assertSame('sent', $sms->fresh()->status);
    }

    public function test_command_moves_pending_reminders_to_a_new_date(): void
    {
        $this->register()->assertCreated();

        config(['webinars.'.self::WEBINAR.'.starts_at' => '2026-10-22 20:00']);
        $this->artisan('gordeh:webinar-reminders', ['webinar' => self::WEBINAR])->assertSuccessful();

        $times = collect($this->scheduled())->map(fn ($row) => $row[0])->all();
        $this->assertSame([
            'webinar-reminder-1d' => '2026-10-21 06:30:00',
            'webinar-reminder-today' => '2026-10-22 06:30:00',
            'webinar-reminder-1h' => '2026-10-22 15:30:00',
            'webinar-reminder-start' => '2026-10-22 16:30:00',
        ], $times);
        $this->assertSame(4, ScheduledSMS::count());
    }

    public function test_command_schedules_registrations_made_before_sms_existed(): void
    {
        // Registered before this feature: no SMS rows at all.
        WebinarRegistration::withoutEvents(fn () => WebinarRegistration::create([
            'webinar' => self::WEBINAR, 'name' => 'Old', 'phone' => '09121111111', 'source' => 'website',
        ]));
        $this->register()->assertCreated();

        $this->artisan('gordeh:webinar-reminders', ['webinar' => self::WEBINAR])->assertSuccessful();

        $this->assertSame(4, ScheduledSMS::where('phone_number', '09121111111')->count());
        $this->assertSame(8, ScheduledSMS::count());
        $this->assertSame(0, ScheduledSMS::where('template', 'webinar-registered')->count());
    }

    public function test_command_refuses_an_unknown_webinar(): void
    {
        $this->artisan('gordeh:webinar-reminders', ['webinar' => 'nope'])->assertFailed();
    }

    public function test_notify_texts_every_registrant_the_new_date_once(): void
    {
        $this->register()->assertCreated();
        $this->register('09351234567')->assertCreated();

        config(['webinars.'.self::WEBINAR.'.starts_at' => '2026-10-24 21:00']);
        $this->artisan('gordeh:webinar-reminders', ['webinar' => self::WEBINAR, '--notify' => true])->assertSuccessful();
        // Running it again doesn't text anyone twice.
        $this->artisan('gordeh:webinar-reminders', ['webinar' => self::WEBINAR, '--notify' => true])->assertSuccessful();

        $notices = ScheduledSMS::where('template', 'webinar-date-changed')->get();
        $this->assertCount(2, $notices);
        $this->assertSame('2026-10-24 21:00', $notices[0]->token2);
        // 13:30 Tehran is in the daytime window, so it goes out right away.
        $this->assertSame('2026-10-18 10:00:00', $notices[0]->send_at->utc()->toDateTimeString());
        $this->assertSame(8, ScheduledSMS::whereIn('template', ReminderScheduler::WEBINAR_REMINDERS)->count());
        $this->assertSame(['AmirMahdi', null, null, 'شنبه 2 آبان ساعت 21'], ReminderScheduler::lookupTokens($notices[0]));
    }

    public function test_without_notify_nobody_is_told(): void
    {
        $this->register()->assertCreated();

        config(['webinars.'.self::WEBINAR.'.starts_at' => '2026-10-24 21:00']);
        $this->artisan('gordeh:webinar-reminders', ['webinar' => self::WEBINAR])->assertSuccessful();

        $this->assertSame(0, ScheduledSMS::where('template', 'webinar-date-changed')->count());
    }

    public function test_new_start_with_minutes_drops_the_weekday_to_fit_token10(): void
    {
        $this->assertSame('سه شنبه 5 آبان ساعت 21', ReminderScheduler::spokenStart('2026-10-27 21:00'));
        $this->assertSame('5 آبان ساعت 21 و 30', ReminderScheduler::spokenStart('2026-10-27 21:30'));
    }
}
