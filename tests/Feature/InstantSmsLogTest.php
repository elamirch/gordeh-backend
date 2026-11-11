<?php

namespace Tests\Feature;

use App\Models\ScheduledSMS;
use App\Services\Curl;
use App\Services\SendSMS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class InstantSmsLogTest extends TestCase
{
    use RefreshDatabase;

    private function kavenegarReturns(int $status, string $message = ''): void
    {
        $curl = Mockery::mock(Curl::class);
        $curl->shouldReceive('curl')->andReturn(json_encode(['return' => ['status' => $status, 'message' => $message]]));
        $this->app->instance(Curl::class, $curl);
    }

    public function test_sent_message_is_logged_as_sent(): void
    {
        $this->kavenegarReturns(200);

        (new SendSMS)->supportReply('09120000000', 'علی', 42);

        $log = ScheduledSMS::sole();
        $this->assertSame('support-reply', $log->template);
        $this->assertSame('sent', $log->status);
        $this->assertSame('42', $log->token2);
        $this->assertNotNull($log->sent_at);
    }

    public function test_kavenegar_error_is_logged_as_failed(): void
    {
        $this->kavenegarReturns(424, 'template not found');

        (new SendSMS)->supportReply('09120000000', 'علی', 42);

        $log = ScheduledSMS::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('424', $log->error);
    }

    public function test_otp_code_is_not_stored(): void
    {
        $this->kavenegarReturns(200);

        (new SendSMS)->otp('09120000000', '123456');

        $log = ScheduledSMS::sole();
        $this->assertSame('otp-kcp', $log->template);
        $this->assertNull($log->token);
    }
}
