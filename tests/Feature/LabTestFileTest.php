<?php

namespace Tests\Feature;

use App\Models\LabTest;
use App\Models\LabTestFile;
use App\Models\User;
use App\Services\LabTestFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class LabTestFileTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        $token = JWTAuth::fromUser($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function patient(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    private function labTestFor(User $user): LabTest
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
            'stage' => 2,
            'risk_2_years' => 5,
            'risk_5_years' => 10,
            'albumin_creatinine_ratio' => 22.2,
        ]);
    }

    public function test_patient_can_upload_lab_test_files(): void
    {
        Storage::fake('local');
        $patient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->post('/api/lab-test-files', [
                'files' => [UploadedFile::fake()->image('lab.jpg', 100, 100)->size(500)],
            ]);

        $response->assertStatus(201);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.patientId', $patient->id);
        $response->assertJsonPath('0.testId', null);
        $response->assertJsonPath('0.filename', 'lab.jpg');
        $response->assertJsonStructure([['id', 'patientId', 'testId', 'url', 'filename', 'mimeType', 'size', 'createdAt']]);

        $this->assertDatabaseCount('lab_test_files', 1);
        $file = LabTestFile::first();
        Storage::disk('local')->assertExists($file->storage_key);
    }

    public function test_upload_rejects_invalid_mime_type(): void
    {
        Storage::fake('local');
        $patient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/lab-test-files', [
                'files' => [UploadedFile::fake()->create('doc.txt', 100, 'text/plain')],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('lab_test_files', 0);
    }

    public function test_upload_rejects_oversized_file(): void
    {
        Storage::fake('local');
        $patient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->postJson('/api/lab-test-files', [
                'files' => [UploadedFile::fake()->create('big.jpg', 11000, 'image/jpeg')],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('lab_test_files', 0);
    }

    public function test_upload_ignores_client_supplied_patient_id_and_self_associates(): void
    {
        Storage::fake('local');
        $patient = $this->patient();
        $otherPatient = $this->patient();

        $response = $this->withHeaders($this->authHeaders($patient))
            ->post('/api/lab-test-files', [
                'patientId' => $otherPatient->id,
                'files' => [UploadedFile::fake()->image('lab.jpg')],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('0.patientId', $patient->id);
    }

    public function test_patient_can_attach_own_files_to_own_test(): void
    {
        Storage::fake('local');
        $patient = $this->patient();
        $test = $this->labTestFor($patient);
        $file = app(LabTestFileService::class)->store(
            UploadedFile::fake()->image('lab.jpg'), $patient, $patient
        );

        $response = $this->withHeaders($this->authHeaders($patient))
            ->patchJson("/api/lab-tests/{$test->id}/files", ['fileIds' => [$file->id]]);

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.testId', $test->id);
        $this->assertSame($test->id, $file->fresh()->lab_test_id);
    }

    public function test_cannot_attach_another_patients_file_to_test(): void
    {
        Storage::fake('local');
        $patient = $this->patient();
        $otherPatient = $this->patient();
        $test = $this->labTestFor($patient);
        $foreignFile = app(LabTestFileService::class)->store(
            UploadedFile::fake()->image('lab.jpg'), $otherPatient, $otherPatient
        );

        $response = $this->withHeaders($this->authHeaders($patient))
            ->patchJson("/api/lab-tests/{$test->id}/files", ['fileIds' => [$foreignFile->id]]);

        $response->assertStatus(403);
        $this->assertNull($foreignFile->fresh()->lab_test_id);
    }

    public function test_cannot_attach_files_to_another_patients_test(): void
    {
        Storage::fake('local');
        $owner = $this->patient();
        $attacker = $this->patient();
        $test = $this->labTestFor($owner);
        $file = app(LabTestFileService::class)->store(
            UploadedFile::fake()->image('lab.jpg'), $attacker, $attacker
        );

        $response = $this->withHeaders($this->authHeaders($attacker))
            ->patchJson("/api/lab-tests/{$test->id}/files", ['fileIds' => [$file->id]]);

        $response->assertStatus(403);
        $this->assertNull($file->fresh()->lab_test_id);
    }

    public function test_owner_can_get_files_for_their_test(): void
    {
        Storage::fake('local');
        $patient = $this->patient();
        $test = $this->labTestFor($patient);
        $file = app(LabTestFileService::class)->store(
            UploadedFile::fake()->image('lab.jpg'), $patient, $patient
        );
        $file->update(['lab_test_id' => $test->id]);

        $response = $this->withHeaders($this->authHeaders($patient))
            ->get("/api/lab-tests/{$test->id}/files");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.id', $file->id);
    }

    public function test_unauthorized_patient_cannot_view_others_test_files(): void
    {
        Storage::fake('local');
        $owner = $this->patient();
        $stranger = $this->patient();
        $test = $this->labTestFor($owner);

        $response = $this->withHeaders($this->authHeaders($stranger))
            ->get("/api/lab-tests/{$test->id}/files");

        $response->assertStatus(403);
    }

    public function test_admin_can_view_any_patients_test_files(): void
    {
        Storage::fake('local');
        $patient = $this->patient();
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
        $test = $this->labTestFor($patient);
        $file = app(LabTestFileService::class)->store(
            UploadedFile::fake()->image('lab.jpg'), $patient, $patient
        );
        $file->update(['lab_test_id' => $test->id]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->get("/api/lab-tests/{$test->id}/files");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    }

    public function test_storage_write_failure_is_reported_and_creates_no_orphan_record(): void
    {
        Storage::shouldReceive('disk')->with('local')->andReturnSelf();
        Storage::shouldReceive('putFileAs')->andReturn(false);

        $patient = $this->patient();
        $file = UploadedFile::fake()->image('lab.jpg');

        $this->expectException(RuntimeException::class);

        try {
            app(LabTestFileService::class)->store($file, $patient, $patient);
        } finally {
            $this->assertDatabaseCount('lab_test_files', 0);
        }
    }

    public function test_db_failure_after_storage_success_cleans_up_orphaned_file(): void
    {
        Storage::fake('local');

        $ghostUser = new User;
        $ghostUser->id = 999999; // not persisted -> FK violation on insert

        $file = UploadedFile::fake()->image('lab.jpg');

        try {
            app(LabTestFileService::class)->store($file, $ghostUser, $ghostUser);
            $this->fail('Expected DB insert to fail for a non-existent user_id.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertDatabaseCount('lab_test_files', 0);
        Storage::disk('local')->assertDirectoryEmpty('lab-test-files/999999');
    }
}
