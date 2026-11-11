<?php

namespace Tests\Feature;

use App\Models\LabTestFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class LabTestOcrTest extends TestCase
{
    use RefreshDatabase;

    private const METIS_URL = 'https://api.metisai.ir/v1beta/models/*';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // Don't actually wait between Metis retries.
        Sleep::fake();
        config([
            'services.metis.api_key' => 'test-key',
            'services.metis.gemini_model' => 'gemini-2.5-pro',
            'services.metis.base_url' => 'https://api.metisai.ir/v1beta/models',
        ]);
    }

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function user(string $role = 'user', array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ], $attrs));
    }

    private function geminiReply(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    private function upload(User $user, int $count = 2)
    {
        $images = [];
        for ($i = 0; $i < $count; $i++) {
            $images[] = UploadedFile::fake()->image("lab{$i}.jpg", 50, 50)->size(100);
        }

        return $this->withHeaders($this->authHeaders($user))
            ->post('/api/lab-tests/ocr', ['images' => $images]);
    }

    public function test_successful_ocr_stores_files_and_returns_result(): void
    {
        $values = '{"error": false, "name":"Ali","age":40,"gender":"m","creatinine":{"value":1.1,"unit":"mg/dL"}}';
        Http::fake([self::METIS_URL => Http::response($this->geminiReply("```json\n".$values."\n```"))]);
        $patient = $this->user();

        $response = $this->upload($patient);

        $response->assertOk()
            ->assertJsonPath('result.error', false)
            ->assertJsonPath('result.creatinine.value', 1.1)
            ->assertJsonCount(2, 'files')
            ->assertJsonPath('files.0.ocrStatus', 'success')
            ->assertJsonPath('files.0.patientId', $patient->id)
            ->assertJsonStructure(['files' => [['id', 'patientId', 'testId', 'url', 'filename', 'mimeType', 'size', 'createdAt', 'ocrStatus', 'batchId']]])
            ->assertJsonMissingPath('files.0.ocrResult');

        $batchId = $response->json('files.0.batchId');
        $this->assertNotNull($batchId);
        $this->assertSame($batchId, $response->json('files.1.batchId'));

        $files = LabTestFile::all();
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            Storage::disk('local')->assertExists($file->storage_key);
            $this->assertSame('success', $file->ocr_status);
            $this->assertSame('Ali', $file->ocr_result['name']);
            $this->assertNotNull($file->ocr_processed_at);
        }

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            $parts = $request['contents'][0]['parts'];

            return $request->url() === 'https://api.metisai.ir/v1beta/models/gemini-2.5-pro:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && str_starts_with($parts[0]['text'], 'You are extracting lab test values')
                // Values and units as printed; conversion and UACR are deterministic code.
                && str_contains($parts[0]['text'], 'Do NOT convert units')
                && $request['generationConfig']['responseMimeType'] === 'application/json'
                && count($parts) === 3
                && $parts[1]['inline_data']['mime_type'] === 'image/jpeg'
                && base64_decode($parts[1]['inline_data']['data'], true) !== false;
        });
    }

    public function test_missing_fields_result_is_returned_as_is(): void
    {
        $text = '{"error": true, "errorType": "missing_fields", "message": "این آیتم‌ها در آزمایش شما یافت نشد", "missingItems": ["کراتینین ادرار"]}';
        Http::fake([self::METIS_URL => Http::response($this->geminiReply($text))]);

        $response = $this->upload($this->user(), 1);

        $response->assertOk()
            ->assertJsonPath('result.error', true)
            ->assertJsonPath('result.errorType', 'missing_fields')
            ->assertJsonPath('result.missingItems.0', 'کراتینین ادرار')
            ->assertJsonPath('files.0.ocrStatus', 'missing_fields');

        $this->assertSame('missing_fields', LabTestFile::first()->ocr_status);
    }

    public function test_metis_server_error_is_retried_then_returns_503_and_keeps_files(): void
    {
        Http::fake([self::METIS_URL => Http::response(['error' => 'boom'], 500)]);

        $response = $this->upload($this->user(), 2);

        $response->assertStatus(503)
            ->assertJsonPath('error', true)
            ->assertJsonPath('errorType', 'service_unavailable')
            ->assertJsonPath('retryable', true)
            ->assertJsonCount(2, 'files')
            ->assertJsonPath('files.0.ocrStatus', 'failed');

        Http::assertSentCount(3); // first attempt + 2 retries
        $this->assertSame(2, LabTestFile::where('ocr_status', 'failed')->count());
        $this->assertSame('service_unavailable', LabTestFile::first()->ocr_result['errorType']);
        LabTestFile::all()->each(fn ($f) => Storage::disk('local')->assertExists($f->storage_key));
    }

    public function test_transient_metis_error_recovers_on_retry(): void
    {
        $values = json_encode(['error' => false, 'creatinine' => ['value' => 1.1, 'unit' => 'mg/dL']]);
        Http::fake([self::METIS_URL => Http::sequence()
            ->push(['error' => 'busy'], 503)
            ->push($this->geminiReply($values))]);

        $this->upload($this->user(), 1)
            ->assertOk()
            ->assertJsonPath('result.error', false);

        Http::assertSentCount(2);
        $this->assertSame('success', LabTestFile::first()->ocr_status);
    }

    public function test_connection_failure_returns_503(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Connection timed out after 10001 milliseconds');
        });

        $this->upload($this->user(), 1)
            ->assertStatus(503)
            ->assertJsonPath('errorType', 'service_unavailable')
            ->assertJsonPath('retryable', true)
            ->assertJsonCount(1, 'files');

        $this->assertSame(3, $calls); // connect failures are retried

        $this->assertSame('failed', LabTestFile::first()->ocr_status);
    }

    public function test_read_timeout_is_not_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out after 120000 milliseconds with 0 bytes received');
        });

        $this->upload($this->user(), 1)
            ->assertStatus(503)
            ->assertJsonPath('errorType', 'service_unavailable');

        $this->assertSame(1, $calls);
    }

    public function test_metis_auth_error_is_not_retried_or_retryable(): void
    {
        Http::fake([self::METIS_URL => Http::response(['error' => 'bad key'], 401)]);

        $this->upload($this->user(), 1)
            ->assertStatus(500)
            ->assertJsonPath('errorType', 'internal_error')
            ->assertJsonPath('retryable', false);

        Http::assertSentCount(1);
    }

    public function test_missing_api_key_returns_internal_error_without_calling_metis(): void
    {
        config(['services.metis.api_key' => null]);
        Http::fake();

        $this->upload($this->user(), 1)
            ->assertStatus(500)
            ->assertJsonPath('errorType', 'internal_error');

        Http::assertNothingSent();
    }

    public function test_unparseable_model_output_returns_502(): void
    {
        Http::fake([self::METIS_URL => Http::response($this->geminiReply('sorry, I cannot help'))]);

        $this->upload($this->user(), 1)
            ->assertStatus(502)
            ->assertJsonPath('errorType', 'invalid_response')
            ->assertJsonPath('retryable', true);

        $this->assertSame('failed', LabTestFile::first()->ocr_status);
    }

    public function test_retry_with_file_ids_reuses_stored_files(): void
    {
        $user = $this->user();
        Http::fake([self::METIS_URL => Http::sequence()
            ->push(['error' => 'down'], 500)->push(['error' => 'down'], 500)->push(['error' => 'down'], 500)
            ->push($this->geminiReply(json_encode(['error' => false])))]);

        $ids = collect($this->upload($user, 2)->assertStatus(503)->json('files'))->pluck('id')->all();

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/lab-tests/ocr', ['fileIds' => $ids])
            ->assertOk()
            ->assertJsonPath('result.error', false)
            ->assertJsonCount(2, 'files');

        $this->assertDatabaseCount('lab_test_files', 2);
        $this->assertSame(2, LabTestFile::where('ocr_status', 'success')->count());
    }

    public function test_retry_rejects_files_of_another_user(): void
    {
        Http::fake();
        $owner = $this->user();
        $file = LabTestFile::create([
            'user_id' => $owner->id,
            'uploaded_by' => $owner->id,
            'disk' => 'local',
            'storage_key' => 'lab-test-files/'.$owner->id.'/x.jpg',
            'original_filename' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 10,
        ]);

        $this->withHeaders($this->authHeaders($this->user()))
            ->postJson('/api/lab-tests/ocr', ['fileIds' => [$file->id]])
            ->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_ocr_requires_authentication(): void
    {
        Http::fake();

        $this->postJson('/api/lab-tests/ocr', [])->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_ocr_validates_images(): void
    {
        Http::fake();
        $user = $this->user();

        $this->withHeaders($this->authHeaders($user) + ['Accept' => 'application/json'])
            ->post('/api/lab-tests/ocr', [])
            ->assertStatus(422)->assertJsonValidationErrors('images');

        $this->withHeaders($this->authHeaders($user) + ['Accept' => 'application/json'])
            ->post('/api/lab-tests/ocr', ['images' => [UploadedFile::fake()->create('x.txt', 10, 'text/plain')]])
            ->assertStatus(422)->assertJsonValidationErrors('images.0');

        $tooMany = array_map(fn ($i) => UploadedFile::fake()->image("l{$i}.jpg"), range(1, 6));
        $this->withHeaders($this->authHeaders($user) + ['Accept' => 'application/json'])
            ->post('/api/lab-tests/ocr', ['images' => $tooMany])
            ->assertStatus(422)->assertJsonValidationErrors('images');

        Http::assertNothingSent();
        $this->assertDatabaseCount('lab_test_files', 0);
    }

    public function test_non_admin_cannot_list_uploaded_files(): void
    {
        $this->withHeaders($this->authHeaders($this->user()))
            ->getJson('/api/admin/lab-test-files')
            ->assertStatus(403);
    }

    public function test_admin_can_list_filter_and_search_uploaded_files(): void
    {
        $admin = $this->user('admin');
        $sara = $this->user('user', ['first_name' => 'Sara', 'last_name' => 'Ahmadi', 'phone_number' => '09121112233']);
        $reza = $this->user('user', ['first_name' => 'Reza', 'last_name' => 'Karimi', 'phone_number' => '09354445566']);

        $make = fn (User $u, ?string $status, ?array $result = null) => LabTestFile::create([
            'user_id' => $u->id,
            'uploaded_by' => $u->id,
            'disk' => 'local',
            'storage_key' => 'lab-test-files/'.$u->id.'/'.uniqid().'.jpg',
            'original_filename' => 'lab.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'ocr_batch_id' => $status ? (string) \Illuminate\Support\Str::uuid() : null,
            'ocr_status' => $status,
            'ocr_result' => $result,
            'ocr_processed_at' => $status ? now() : null,
        ]);

        $make($sara, 'success', ['error' => false, 'name' => 'Sara']);
        $make($sara, null);
        $make($reza, 'failed');

        $headers = $this->authHeaders($admin);

        $all = $this->withHeaders($headers)->getJson('/api/admin/lab-test-files')->assertOk();
        $all->assertJsonPath('count', 3)->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'patientId', 'testId', 'url', 'filename', 'mimeType', 'size', 'createdAt',
                'ocrStatus', 'batchId', 'ocrResult', 'ocrProcessedAt', 'uploadedBy',
                'user' => ['id', 'first_name', 'last_name', 'phone_number']]], 'count']);
        $this->assertStringContainsString('signature=', $all->json('data.0.url'));

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-files?status=success')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.ocrResult.name', 'Sara')
            ->assertJsonPath('data.0.user.first_name', 'Sara');

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-files?status=none')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.ocrStatus', null);

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-files?search=Karimi')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.user.id', $reza->id);

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-files?search=0912111')
            ->assertJsonPath('count', 2);

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-files?limit=1&page=2')
            ->assertJsonPath('count', 3)
            ->assertJsonCount(1, 'data');

        $this->withHeaders($headers)->getJson('/api/admin/lab-test-files?status=bogus')
            ->assertStatus(422);
    }
}
