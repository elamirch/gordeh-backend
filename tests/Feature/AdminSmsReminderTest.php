<?php

namespace Tests\Feature;

use App\Models\ScheduledSMS;
use App\Models\User;
use App\Services\SendSMS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminSmsReminderTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'phone_number' => '09120000000']);
    }

    private function sms(array $attributes): ScheduledSMS
    {
        return ScheduledSMS::create(array_merge([
            'phone_number' => '09123456789',
            'template' => 'cron-assess-reminder',
            'token' => 'Amir',
            'token2' => '3',
            'send_at' => now(),
            'status' => 'pending',
        ], $attributes));
    }

    public function test_overview_counts_by_segment_stage_and_status(): void
    {
        $this->sms(['template' => 'cron-assess-reminder-7d', 'status' => 'sent', 'sent_at' => now()]);
        $this->sms(['template' => 'cron-assess-reminder-7d', 'status' => 'failed', 'error' => 'Kavenegar 431']);
        $this->sms(['template' => 'cron-assess-reminder', 'token2' => '5', 'send_at' => now()->addDays(2)]);
        $this->sms(['template' => 'cron-insurance-reminder-14d', 'token2' => null, 'status' => 'missed']);
        $stuck = $this->sms(['status' => 'processing']);
        ScheduledSMS::where('id', $stuck->id)->update(['updated_at' => now()->subHour()]);

        $response = $this->withHeaders($this->headers($this->admin()))
            ->getJson('/api/admin/sms-reminders/overview')
            ->assertOk()
            ->assertJsonPath('totals.total', 5)
            ->assertJsonPath('totals.failed', 1)
            ->assertJsonPath('stuck', 1)
            ->assertJsonPath('overdue', 0)
            ->assertJsonPath('upcoming7d', 1)
            ->assertJsonPath('deliveryRate30d', 50);

        $segments = collect($response->json('segments'))->keyBy('key');
        $this->assertSame(1, $segments['assess_7d']['sent']);
        $this->assertSame(1, $segments['assess_7d']['failed']);
        $this->assertSame(1, $segments['insurance_14d']['missed']);
        $this->assertNotNull($segments['assess_7d']['lastSentAt']);
        $this->assertArrayNotHasKey('other', $segments->all());

        $stages = collect($response->json('stages'))->keyBy('stage');
        $this->assertSame(3, $stages[3]['total']);
        $this->assertSame(1, $stages[5]['pending']);
    }

    public function test_list_filters_by_segment_status_stage_and_search(): void
    {
        $user = User::factory()->create(['role' => 'user', 'first_name' => 'Sara', 'phone_number' => '09351112233']);
        $this->sms(['template' => 'cron-assess-reminder-7d', 'user_id' => $user->id, 'phone_number' => $user->phone_number, 'token2' => '4']);
        $this->sms(['template' => 'cron-assess-reminder-7d', 'token2' => '2', 'status' => 'failed']);
        $this->sms(['template' => 'cron-insurance-reminder-7d', 'token2' => null]);

        $headers = $this->headers($this->admin());

        $this->withHeaders($headers)->getJson('/api/admin/sms-reminders?segment=assess_7d')
            ->assertOk()->assertJsonPath('count', 2);
        $this->withHeaders($headers)->getJson('/api/admin/sms-reminders?status=failed')
            ->assertJsonPath('count', 1)->assertJsonPath('data.0.stage', 2);
        $this->withHeaders($headers)->getJson('/api/admin/sms-reminders?stage=4')
            ->assertJsonPath('count', 1)->assertJsonPath('data.0.firstName', 'Sara');
        $this->withHeaders($headers)->getJson('/api/admin/sms-reminders?search=Sara')
            ->assertJsonPath('count', 1)->assertJsonPath('data.0.segment', 'assess_7d');
        $this->withHeaders($headers)->getJson('/api/admin/sms-reminders?segment=insurance_7d')
            ->assertJsonPath('data.0.stage', null);
    }

    public function test_pending_reminders_past_their_time_are_reported_overdue(): void
    {
        $this->sms(['send_at' => now()->subHour()]);
        $this->sms(['send_at' => now()->addHour()]);
        $headers = $this->headers($this->admin());

        $this->withHeaders($headers)->getJson('/api/admin/sms-reminders/overview')
            ->assertJsonPath('overdue', 1);
        $this->withHeaders($headers)->getJson('/api/admin/sms-reminders?status=overdue')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.isOverdue', true);
    }

    public function test_retry_requeues_only_failed_reminders(): void
    {
        $failed = $this->sms(['status' => 'failed', 'error' => 'Kavenegar 431']);
        $sent = $this->sms(['status' => 'sent', 'sent_at' => now()]);
        $admin = $this->admin();

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/admin/sms-reminders/{$failed->id}/retry")
            ->assertOk()
            ->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('scheduled_sms', ['id' => $failed->id, 'status' => 'pending', 'error' => null]);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'sms_reminder.retried']);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/admin/sms-reminders/{$sent->id}/retry")
            ->assertStatus(422);
    }

    public function test_non_admins_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'user', 'phone_number' => '09121111111']);

        $this->withHeaders($this->headers($user))
            ->getJson('/api/admin/sms-reminders/overview')
            ->assertForbidden();
    }

    public function test_support_sms_names_are_sent_as_valid_kavenegar_tokens(): void
    {
        config(['services.kavenegar.lookup_url' => 'https://sms.test/lookup.json']);

        $curl = new class extends \App\Services\Curl {
            public array $sent = [];

            public function curl(string $url, ?string $data = null, ?array $header = null, ?string $request = null)
            {
                $this->sent = [$url, $data];

                return '{"return":{"status":200}}';
            }
        };

        // SendSMS news up its Curl itself, so swap it in through reflection.
        $sms = new SendSMS;
        (new \ReflectionProperty($sms, 'curl'))->setValue($sms, $curl);

        $sms->supportReply('09123456789', 'محمد علی', 12);
        parse_str($curl->sent[1], $payload);
        $this->assertSame('https://sms.test/lookup.json', $curl->sent[0]);
        $this->assertSame('محمدعلی', $payload['token']);

        $sms->supportNewTicket('09123456789', '', 12);
        parse_str($curl->sent[1], $payload);
        $this->assertSame('کاربر', $payload['token']);
    }
}
