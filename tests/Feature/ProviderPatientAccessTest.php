<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ConsultationBooking;
use App\Models\DietPlan;
use App\Models\LabTest;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProviderPatientAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
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

    private function bookingFor(User $patient, ?User $provider = null): ConsultationBooking
    {
        return ConsultationBooking::create([
            'user_id' => $patient->id,
            'provider_id' => $provider?->id,
            'type' => 'initial',
            'date' => now()->addDay()->toDateString(),
            'time' => '10:00',
            'full_name' => 'Test Patient',
            'phone_number' => $patient->phone_number,
            'status' => 'new',
        ]);
    }

    private function listedIds(User $viewer): array
    {
        return collect($this->getJson('/api/provider/patients', $this->as($viewer))->assertOk()->json())
            ->pluck('id')->sort()->values()->all();
    }

    public function test_provider_list_excludes_patients_with_no_relation(): void
    {
        $provider = $this->user('provider');
        $stranger = $this->user('user');
        $inInbox = $this->user('user');
        $this->bookingFor($inInbox);

        $this->assertSame([$inInbox->id], $this->listedIds($provider));
        $this->assertNotContains($stranger->id, $this->listedIds($provider));
    }

    public function test_provider_list_is_empty_when_there_are_no_requests(): void
    {
        $this->user('user');
        $this->user('user');

        $this->assertSame([], $this->listedIds($this->user('provider')));
    }

    public function test_provider_sees_own_patients_but_not_another_providers(): void
    {
        $me = $this->user('provider');
        $other = $this->user('provider');

        $mineByBooking = $this->user('user');
        $this->bookingFor($mineByBooking, $me);

        $mineByAppointment = $this->user('user');
        Appointment::create([
            'user_id' => $mineByAppointment->id, 'provider_id' => $me->id,
            'date' => now()->toDateString(), 'time' => '09:00', 'status' => 'confirmed',
        ]);

        $mineByPlan = $this->user('user');
        DietPlan::create(['user_id' => $mineByPlan->id, 'provider_id' => $me->id, 'status' => 'sent', 'file_url' => 'x']);

        $theirs = $this->user('user');
        $this->bookingFor($theirs, $other);

        $expected = collect([$mineByBooking, $mineByAppointment, $mineByPlan])->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $this->listedIds($me));
    }

    public function test_provider_cannot_open_a_patient_outside_their_scope(): void
    {
        $me = $this->user('provider');
        $other = $this->user('provider');
        $theirs = $this->user('user');
        $this->bookingFor($theirs, $other);
        $stranger = $this->user('user');

        $this->getJson("/api/provider/patients/{$theirs->id}", $this->as($me))->assertNotFound();
        $this->getJson("/api/provider/patients/{$stranger->id}", $this->as($me))->assertNotFound();
    }

    public function test_provider_can_open_a_patient_from_the_shared_inbox_and_their_own(): void
    {
        $me = $this->user('provider');
        $inbox = $this->user('user');
        $this->bookingFor($inbox);
        $own = $this->user('user');
        $this->bookingFor($own, $me);

        $this->getJson("/api/provider/patients/{$inbox->id}", $this->as($me))->assertOk();
        $this->getJson("/api/provider/patients/{$own->id}", $this->as($me))->assertOk();
    }

    public function test_admin_sees_every_patient_but_not_staff_accounts(): void
    {
        $admin = $this->user('admin');
        $a = $this->user('user');
        $b = $this->user('user');
        $this->user('provider');

        $this->assertSame(collect([$a, $b])->pluck('id')->sort()->values()->all(), $this->listedIds($admin));
    }

    public function test_regular_user_is_forbidden(): void
    {
        $this->getJson('/api/provider/patients', $this->as($this->user('user')))->assertForbidden();
    }

    public function test_provider_cannot_upload_a_plan_for_an_unrelated_patient(): void
    {
        Storage::fake('local');
        $me = $this->user('provider');
        $stranger = $this->user('user');

        $this->post("/api/provider/patients/{$stranger->id}/diet-plans", [
            'file' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'),
        ], $this->as($me) + ['Accept' => 'application/json'])->assertNotFound();

        $this->assertDatabaseCount('diet_plans', 0);
    }

    public function test_provider_can_upload_a_plan_for_a_patient_in_their_inbox(): void
    {
        Storage::fake('local');
        $me = $this->user('provider');
        $patient = $this->user('user');
        $this->bookingFor($patient);

        $this->post("/api/provider/patients/{$patient->id}/diet-plans", [
            'file' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'),
        ], $this->as($me) + ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseHas('diet_plans', ['user_id' => $patient->id, 'provider_id' => $me->id]);
    }

    public function test_stored_file_is_only_viewable_by_providers_who_can_see_the_patient(): void
    {
        $me = $this->user('provider');
        $related = $this->user('user');
        $this->bookingFor($related);
        $stranger = $this->user('user');

        $relatedFile = StoredFile::create(['url' => 'a.jpg', 'fileName' => 'a.jpg', 'user_id' => $related->id, 'category' => 'lab']);
        $strangerFile = StoredFile::create(['url' => 'b.jpg', 'fileName' => 'b.jpg', 'user_id' => $stranger->id, 'category' => 'lab']);

        $this->getJson("/api/stored-files/{$relatedFile->id}", $this->as($me))->assertOk();
        $this->getJson("/api/stored-files/{$strangerFile->id}", $this->as($me))->assertForbidden();
        $this->getJson("/api/stored-files/{$strangerFile->id}", $this->as($this->user('admin')))->assertOk();
    }

    public function test_lab_test_files_are_only_listable_by_providers_who_can_see_the_patient(): void
    {
        $me = $this->user('provider');
        $related = $this->user('user');
        $this->bookingFor($related);
        $stranger = $this->user('user');

        $relatedTest = $this->labTestFor($related);
        $strangerTest = $this->labTestFor($stranger);

        $this->getJson("/api/lab-tests/{$relatedTest->id}/files", $this->as($me))->assertOk();
        $this->getJson("/api/lab-tests/{$strangerTest->id}/files", $this->as($me))->assertForbidden();
        $this->getJson("/api/lab-tests/{$strangerTest->id}/files", $this->as($this->user('admin')))->assertOk();
    }

    public function test_provider_request_list_is_inbox_plus_own(): void
    {
        $me = $this->user('provider');
        $other = $this->user('provider');
        $inbox = $this->bookingFor($this->user('user'));
        $mine = $this->bookingFor($this->user('user'), $me);
        $this->bookingFor($this->user('user'), $other);

        $ids = collect($this->getJson('/api/provider/requests', $this->as($me))->assertOk()->json())->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$inbox->id, $mine->id])->sort()->values()->all(), $ids);

        $this->assertCount(3, $this->getJson('/api/provider/requests', $this->as($this->user('admin')))->assertOk()->json());
    }

    public function test_provider_cannot_change_or_log_calls_on_another_providers_request(): void
    {
        $me = $this->user('provider');
        $theirs = $this->bookingFor($this->user('user'), $this->user('provider'));

        $this->patchJson("/api/provider/requests/{$theirs->id}/status", ['status' => 'done'], $this->as($me))->assertNotFound();
        $this->postJson("/api/provider/requests/{$theirs->id}/calls", ['durationSec' => 5], $this->as($me))->assertNotFound();
        $this->assertDatabaseHas('consultation_bookings', ['id' => $theirs->id, 'status' => 'new']);

        $inbox = $this->bookingFor($this->user('user'));
        $this->patchJson("/api/provider/requests/{$inbox->id}/status", ['status' => 'called'], $this->as($me))->assertOk();
        $this->assertDatabaseHas('consultation_bookings', ['id' => $inbox->id, 'provider_id' => $me->id]);
    }

    public function test_provider_sees_and_updates_only_their_own_appointments(): void
    {
        $me = $this->user('provider');
        $other = $this->user('provider');
        $make = fn (User $p) => Appointment::create([
            'user_id' => $this->user('user')->id, 'provider_id' => $p->id,
            'date' => now()->toDateString(), 'time' => '09:00', 'status' => 'requested',
        ]);
        $mine = $make($me);
        $theirs = $make($other);

        $ids = collect($this->getJson('/api/provider/appointments', $this->as($me))->assertOk()->json())->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);

        $this->patchJson("/api/provider/appointments/{$theirs->id}/status", ['status' => 'canceled'], $this->as($me))->assertNotFound();
        $this->patchJson("/api/provider/appointments/{$mine->id}/status", ['status' => 'confirmed'], $this->as($me))->assertOk();
    }

    public function test_provider_files_list_only_covers_visible_patients(): void
    {
        $me = $this->user('provider');
        $related = $this->user('user');
        $this->bookingFor($related);
        $stranger = $this->user('user');
        StoredFile::create(['url' => 'a.jpg', 'fileName' => 'a.jpg', 'user_id' => $related->id, 'category' => 'lab']);
        StoredFile::create(['url' => 'b.jpg', 'fileName' => 'b.jpg', 'user_id' => $stranger->id, 'category' => 'lab']);

        $rows = $this->getJson('/api/provider/files', $this->as($me))->assertOk()->json();
        $this->assertSame([$related->id], collect($rows)->pluck('patientId')->all());
    }

    private function labTestFor(User $user): LabTest
    {
        return LabTest::create([
            'user_id' => $user->id, 'age' => 40, 'gender' => 'm', 'creatinine' => 1.1, 'urine_creatinine' => 90,
            'albumin' => 4.0, 'urine_albumin' => 20, 'gfr' => 80, 'calcium' => 9.3, 'phosphorous' => 3.9,
            'b_carbonate' => 25.5, 'stage' => 2, 'risk_2_years' => 5, 'risk_5_years' => 10,
            'albumin_creatinine_ratio' => 22.2,
        ]);
    }
}
