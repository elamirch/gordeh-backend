<?php

namespace Tests\Feature;

use App\Models\ConsultationBooking;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PrivateFilesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    private function as(User $user): array
    {
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function upload(User $user): array
    {
        return $this->withHeaders($this->as($user))
            ->post('/api/stored-files', ['file' => UploadedFile::fake()->image('a.jpg')])
            ->assertCreated()
            ->json();
    }

    public function test_upload_goes_to_private_disk_and_is_not_publicly_reachable(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $file = $this->upload($this->user());

        Storage::disk('local')->assertExists($file['url']);
        Storage::disk('public')->assertMissing($file['url']);
    }

    public function test_signed_url_serves_file_and_unsigned_or_tampered_is_rejected(): void
    {
        Storage::fake('local');
        $file = $this->upload($this->user());

        $this->get($file['signed_url'])->assertOk();
        $this->get('/api/files?path='.$file['url'])->assertForbidden();
        $this->get($file['signed_url'].'x')->assertForbidden();
    }

    public function test_unknown_path_is_not_served_even_with_valid_signature(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('secret.txt', 'x');

        $this->get(\App\Support\PrivateFiles::signedUrl('secret.txt'))->assertNotFound();
    }

    public function test_legacy_public_disk_files_are_still_served(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $owner = $this->user();
        Storage::disk('public')->put('uploads/old.jpg', 'x');
        StoredFile::create(['url' => 'uploads/old.jpg', 'user_id' => $owner->id]);

        $this->get(\App\Support\PrivateFiles::signedUrl('uploads/old.jpg'))->assertOk();
    }

    public function test_stored_file_endpoints_enforce_ownership(): void
    {
        Storage::fake('local');
        $owner = $this->user();
        $other = $this->user();
        $provider = $this->user('provider');
        $admin = $this->user('admin');
        $file = $this->upload($owner);

        // A provider only reaches files of patients in their scope (here: a consultation request
        // they own); a provider with no link to the patient is refused.
        ConsultationBooking::create([
            'user_id' => $owner->id, 'provider_id' => $provider->id, 'type' => 'initial',
            'date' => now()->addDay()->toDateString(), 'time' => '10:00',
            'full_name' => 'Owner', 'phone_number' => $owner->phone_number, 'status' => 'called',
        ]);
        $unrelatedProvider = $this->user('provider');

        $this->withHeaders($this->as($other))->getJson('/api/stored-files/'.$file['id'])->assertForbidden();
        $this->withHeaders($this->as($other))->deleteJson('/api/stored-files/'.$file['id'])->assertForbidden();
        $this->withHeaders($this->as($other))->getJson('/api/stored-files')->assertOk()->assertJsonCount(0);

        $this->withHeaders($this->as($owner))->getJson('/api/stored-files/'.$file['id'])->assertOk();
        $this->withHeaders($this->as($provider))->getJson('/api/stored-files/'.$file['id'])->assertOk();
        $this->withHeaders($this->as($unrelatedProvider))->getJson('/api/stored-files/'.$file['id'])->assertForbidden();
        $this->withHeaders($this->as($provider))->deleteJson('/api/stored-files/'.$file['id'])->assertForbidden();
        $this->withHeaders($this->as($admin))->getJson('/api/stored-files')->assertOk()->assertJsonCount(1);

        $this->withHeaders($this->as($owner))->deleteJson('/api/stored-files/'.$file['id'])->assertNoContent();
        Storage::disk('local')->assertMissing($file['url']);
    }

    public function test_user_exposes_signed_avatar_url_and_keeps_raw_value(): void
    {
        Storage::fake('local');
        $user = $this->user();
        $file = $this->upload($user);
        $user->update(['profile_img_url' => $file['url']]);

        $json = $this->withHeaders($this->as($user))->getJson('/api/auth/profile')->assertOk()->json();

        $this->assertSame($file['url'], $json['profile_img_url']);
        $this->get($json['profile_img_signed_url'])->assertOk();
    }
}
