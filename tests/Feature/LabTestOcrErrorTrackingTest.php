<?php

namespace Tests\Feature;

use App\Models\LabTestOcrError;
use App\Models\User;
use App\Services\LabTestFileService;
use App\Services\LabTestOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class LabTestOcrErrorTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const METIS_URL = 'https://api.metisai.ir/v1beta/models/*';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        config([
            'services.metis.api_key' => 'test-key',
            'services.metis.gemini_model' => 'gemini-2.5-pro',
            'services.metis.base_url' => 'https://api.metisai.ir/v1beta/models',
        ]);
    }

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'Accept' => 'application/json'];
    }

    private function user(string $role = 'user', array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ], $attrs));
    }

    private function upload(User $user)
    {
        return $this->withHeaders($this->authHeaders($user))
            ->post('/api/lab-tests/ocr', ['images' => [UploadedFile::fake()->image('lab.jpg', 50, 50)->size(100)]]);
    }

    private function assertReference(?string $reference): LabTestOcrError
    {
        $this->assertMatchesRegularExpression('/^OCR-[2-9A-HJKMNP-Z]{6}$/', (string) $reference);

        return LabTestOcrError::where('reference', $reference)->firstOrFail();
    }

    public function test_ocr_service_failure_is_recorded_with_its_internal_reason(): void
    {
        Http::fake([self::METIS_URL => Http::response(['error' => 'boom'], 500)]);
        $patient = $this->user();

        $response = $this->upload($patient)->assertStatus(503)->assertJsonPath('errorType', 'service_unavailable');

        $error = $this->assertReference($response->json('reference'));
        $this->assertSame($patient->id, $error->user_id);
        $this->assertSame('server', $error->source);
        $this->assertSame('ocr', $error->stage);
        $this->assertSame('service_unavailable', $error->error_type);
        $this->assertSame(503, $error->http_status);
        $this->assertStringContainsString('HTTP 500', $error->detail);
        $this->assertCount(1, $error->context['fileIds']);
    }

    public function test_storage_failure_is_recorded_with_the_exception_message(): void
    {
        $this->mock(LabTestFileService::class)
            ->shouldReceive('store')
            ->andThrow(new \RuntimeException("Unknown column 'ocr_batch_id'"));
        Http::fake();

        $response = $this->upload($this->user())->assertStatus(500)->assertJsonPath('errorType', 'storage_failed');

        $error = $this->assertReference($response->json('reference'));
        $this->assertSame('storage', $error->stage);
        $this->assertStringContainsString("lab.jpg: Unknown column 'ocr_batch_id'", $error->detail);
        $this->assertSame('lab.jpg', $error->context['uploads'][0]['name']);
        Http::assertNothingSent();
    }

    public function test_validation_failure_is_recorded_and_keeps_the_validation_shape(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->user()))
            ->post('/api/lab-tests/ocr', ['images' => [UploadedFile::fake()->create('x.txt', 10, 'text/plain')]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');

        $error = $this->assertReference($response->json('reference'));
        $this->assertSame('validation', $error->stage);
        $this->assertSame('x.txt', $error->context['uploads'][0]['name']);
    }

    public function test_unexpected_exception_returns_internal_error_with_reference(): void
    {
        $this->mock(LabTestOcrService::class)
            ->shouldReceive('process')
            ->andThrow(new \LogicException('kaboom'));

        $response = $this->upload($this->user())
            ->assertStatus(500)
            ->assertJsonPath('errorType', 'internal_error')
            ->assertJsonPath('retryable', true);

        $error = $this->assertReference($response->json('reference'));
        $this->assertSame('exception', $error->stage);
        $this->assertStringContainsString('LogicException: kaboom', $error->detail);
    }

    public function test_retry_with_unknown_files_is_recorded(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->user()))
            ->post('/api/lab-tests/ocr', ['fileIds' => [999]])
            ->assertStatus(404);

        $this->assertSame('lookup', $this->assertReference($response->json('reference'))->stage);
    }

    public function test_successful_ocr_records_nothing(): void
    {
        Http::fake([self::METIS_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"error": false, "creatinine":{"value":1.1,"unit":"mg/dL"}}']]]]]])]);

        $this->upload($this->user())->assertOk()->assertJsonMissingPath('reference');

        $this->assertDatabaseCount('lab_test_ocr_errors', 0);
    }

    public function test_client_can_report_an_error_it_saw(): void
    {
        $patient = $this->user();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/lab-tests/ocr/errors', [
                'errorType' => 'timeout',
                'httpStatus' => 504,
                'detail' => 'Gateway Timeout',
                'context' => ['files' => [['name' => 'capture_0.png', 'size' => 123]]],
            ])
            ->assertCreated();

        $error = $this->assertReference($response->json('reference'));
        $this->assertSame($patient->id, $error->user_id);
        $this->assertSame('client', $error->source);
        $this->assertSame('client', $error->stage);
        $this->assertSame('timeout', $error->error_type);
        $this->assertSame('capture_0.png', $error->context['files'][0]['name']);
    }

    public function test_client_report_requires_authentication_and_an_error_type(): void
    {
        $this->postJson('/api/lab-tests/ocr/errors', ['errorType' => 'timeout'])->assertStatus(401);

        $this->withHeaders($this->authHeaders($this->user()))
            ->postJson('/api/lab-tests/ocr/errors', [])
            ->assertStatus(422);
    }

    public function test_admin_can_search_by_reference_and_filter_by_stage(): void
    {
        $admin = $this->user('admin');
        $sara = $this->user('user', ['first_name' => 'Sara', 'phone_number' => '09121112233']);

        $make = fn (string $reference, string $stage, ?User $user = null) => LabTestOcrError::create([
            'reference' => $reference,
            'user_id' => $user?->id,
            'source' => $stage === 'client' ? 'client' : 'server',
            'stage' => $stage,
            'error_type' => 'internal_error',
        ]);
        $make('OCR-AAAAAA', 'storage', $sara);
        $make('OCR-BBBBBB', 'ocr', $sara);
        $make('OCR-CCCCCC', 'client');

        $headers = $this->authHeaders($admin);

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-ocr-errors')
            ->assertOk()->assertJsonPath('count', 3);

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-ocr-errors?search=bbbbbb')
            ->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.reference', 'OCR-BBBBBB')
            ->assertJsonPath('data.0.user.first_name', 'Sara');

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-ocr-errors?search=09121112233')
            ->assertOk()->assertJsonPath('count', 2);

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-ocr-errors?stage=client')
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.user', null);

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-ocr-errors?stage=bogus')
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_list_errors(): void
    {
        $this->withHeaders($this->authHeaders($this->user()))
            ->getJson('/api/admin/lab-test-ocr-errors')
            ->assertStatus(403);
    }
}
