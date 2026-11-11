<?php

namespace Tests\Feature;

use App\Models\LabTest;
use App\Models\LabTestFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class LabTestPhotoSaveTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $u): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u)];
    }

    private function storedFile(User $u): LabTestFile
    {
        return LabTestFile::create([
            'user_id' => $u->id, 'uploaded_by' => $u->id, 'disk' => 'local', 'storage_key' => 'lab/'.uniqid().'.jpg',
            'original_filename' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size' => 100,
        ]);
    }

    public function test_photo_flow_saves_the_test_then_links_the_stored_pictures(): void
    {
        $u = User::factory()->create(['phone_number' => '09123456789', 'wallet_balance' => 50_000_000]);
        $files = [$this->storedFile($u), $this->storedFile($u)];

        // Exactly what the app sends after OCR: calcium / phosphorous may be NaN → null.
        $res = $this->postJson('/api/lab-tests', [
            'age' => 54, 'gender' => 'm', 'albumin' => 4.1, 'calcium' => null, 'creatinine' => 1.4,
            'urine_creatinine' => 1.1, 'urine_albumin' => 40, 'phosphorous' => null,
        ], $this->headers($u));
        $res->assertStatus(201)->assertJsonPath('data.id', fn ($id) => $id > 0);
        $id = $res->json('data.id');

        $this->patchJson("/api/lab-tests/{$id}/files", ['fileIds' => collect($files)->pluck('id')->all()], $this->headers($u))
            ->assertOk()->assertJsonCount(2);

        $this->assertSame(2, LabTest::find($id)->files()->count());
        $this->getJson("/api/lab-tests/{$id}/files", $this->headers($u))->assertOk()->assertJsonCount(2);
    }
}
