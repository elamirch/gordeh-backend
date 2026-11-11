<?php

namespace Tests\Feature;

use App\Models\LabTest;
use App\Models\NephroConsultation;
use App\Models\NephrologistProfile;
use App\Models\NephroPayout;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class NephrologyConsultationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'user', int $wallet = 0): User
    {
        return User::factory()->create([
            'role' => $role,
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
            'wallet_balance' => $wallet,
        ]);
    }

    private function as(User $user): array
    {
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function doctor(int $fee = 4_500_000, array $over = []): User
    {
        $doctor = $this->user('nephrologist');
        NephrologistProfile::create(array_merge([
            'user_id' => $doctor->id,
            'status' => 'approved',
            'fee' => $fee,
            'capacity' => 5,
            'is_accepting' => true,
            'iban' => '123456789012345678901234',
            'focus' => ['بیماری مزمن کلیه (CKD)'],
            'approved_at' => now(),
        ], $over));

        return $doctor;
    }

    private function payload(User $doctor, array $over = []): array
    {
        return array_merge([
            'doctor_id' => $doctor->id,
            'consent' => true,
            'patient_name' => 'Sara Patient',
            'reason' => 'بررسی جواب آزمایش',
            'relation' => 'self',
        ], $over);
    }

    private function book(User $patient, User $doctor, array $over = [])
    {
        return $this->postJson('/api/nephrology/consultations', $this->payload($doctor, $over), $this->as($patient));
    }

    public function test_booking_charges_the_wallet_and_freezes_the_ten_percent_split(): void
    {
        $doctor = $this->doctor(4_500_000);
        $patient = $this->user('user', 10_000_000);

        $id = $this->book($patient, $doctor)->assertCreated()->json('id');

        $this->assertSame(5_500_000, $patient->fresh()->wallet_balance);
        $c = NephroConsultation::findOrFail($id);
        $this->assertSame('awaiting_reply', $c->status);
        $this->assertSame(4_500_000, $c->fee);
        $this->assertSame(450_000, $c->commission_amount);
        $this->assertSame(4_050_000, $c->doctor_amount);
        $this->assertSame(10, $c->commission_percent);
        $this->assertSame('nephrology', WalletTransaction::findOrFail($c->wallet_transaction_id)->source);

        // A later fee change never touches an existing consultation.
        $doctor->nephrologistProfile->update(['fee' => 8_000_000]);
        $this->assertSame(4_500_000, $c->fresh()->fee);
    }

    public function test_booking_without_enough_wallet_is_refused_and_creates_nothing(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 1_000_000);

        $this->book($patient, $doctor)->assertForbidden()->assertJsonPath('code', 'insufficient_wallet')->assertJsonPath('shortfall', 3_500_000);

        $this->assertSame(0, NephroConsultation::count());
        $this->assertSame(1_000_000, $patient->fresh()->wallet_balance);
    }

    public function test_stale_fee_unavailable_and_busy_doctors_are_refused(): void
    {
        $patient = $this->user('user', 50_000_000);

        $this->book($patient, $this->doctor(), ['expected_fee' => 1_000_000])->assertUnprocessable()->assertJsonValidationErrors('expected_fee');
        $this->book($patient, $this->doctor(over: ['is_accepting' => false]))->assertUnprocessable()->assertJsonValidationErrors('doctor_id');
        $this->book($patient, $this->doctor(over: ['status' => 'pending']))->assertUnprocessable();
        $this->book($patient, $this->doctor(over: ['vacation_from' => now()->subDay(), 'vacation_to' => now()->addDays(3)]))->assertUnprocessable();

        $full = $this->doctor(over: ['capacity' => 1]);
        $this->book($this->user('user', 50_000_000), $full)->assertCreated();
        $this->book($this->user('user', 50_000_000), $full)->assertUnprocessable()->assertJsonValidationErrors('doctor_id');

        // One open conversation per doctor and patient.
        $doctor = $this->doctor();
        $this->book($patient, $doctor)->assertCreated();
        $this->book($patient, $doctor)->assertUnprocessable();

        // A doctor can't book themselves.
        $self = $this->doctor();
        $self->update(['wallet_balance' => 50_000_000]);
        $this->book($self, $self)->assertUnprocessable();
    }

    public function test_only_the_patients_own_labs_and_files_are_shared(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $other = $this->user('user');

        $mine = LabTest::create(['user_id' => $patient->id, 'gender' => 'm', 'age' => 40, 'gfr' => 55, 'uacr' => 40, 'creatinine' => 1.4, 'stage' => 3]);
        $theirs = LabTest::create(['user_id' => $other->id, 'gender' => 'm', 'age' => 40, 'gfr' => 90, 'uacr' => 5, 'creatinine' => 1.0, 'stage' => 1]);

        $id = $this->book($patient, $doctor, ['lab_test_ids' => [$mine->id, $theirs->id]])->assertCreated()->json('id');
        $this->assertSame([$mine->id], NephroConsultation::find($id)->lab_test_ids);

        $record = $this->getJson("/api/nephrologist/consultations/{$id}/record", $this->as($doctor))->assertOk();
        $this->assertCount(1, $record->json('labs'));
        $this->assertEquals(55, $record->json('labs.0.gfr'));
        $this->assertSame(['g' => 2, 'a' => 1], $record->json('kdigo'));
    }

    public function test_conversation_flow_first_reply_opens_the_window_and_unread_counts(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $id = $this->book($patient, $doctor)->assertCreated()->json('id');

        // Doctor sees the request in their inbox with one unread.
        $this->getJson('/api/nephrologist/consultations', $this->as($doctor))->assertOk()->assertJsonPath('0.unread', 1);

        $this->postJson("/api/nephrology/consultations/{$id}/messages", ['body' => 'سلام دکتر'], $this->as($patient))->assertCreated();

        // A quick action can't be the first thing the doctor does.
        $this->postJson("/api/nephrologist/consultations/{$id}/quick-action", ['action' => 'bp_log'], $this->as($doctor))->assertUnprocessable();

        $this->postJson("/api/nephrologist/consultations/{$id}/messages", ['body' => 'سلام، بفرمایید'], $this->as($doctor))->assertCreated();

        $c = NephroConsultation::find($id);
        $this->assertSame('active', $c->status);
        $this->assertNotNull($c->first_replied_at);
        $this->assertEqualsWithDelta(48, now()->diffInHours($c->expires_at, true), 1);
        $this->assertNull($c->reply_due_at);
        $this->assertSame(1, $c->patient_unread);

        $show = $this->getJson("/api/nephrology/consultations/{$id}", $this->as($patient))->assertOk();
        // Opening the conversation marks it read.
        $this->assertSame(0, NephroConsultation::find($id)->patient_unread);

        // Polling returns only what's new.
        $last = collect($show->json('messages'))->last()['id'];
        $this->postJson("/api/nephrologist/consultations/{$id}/quick-action", ['action' => 'bp_log'], $this->as($doctor))->assertCreated();
        $this->getJson("/api/nephrology/consultations/{$id}/messages?after={$last}", $this->as($patient))->assertOk()->assertJsonCount(1, 'messages');

        $this->postJson("/api/nephrologist/consultations/{$id}/quick-action", ['action' => 'nope'], $this->as($doctor))->assertUnprocessable();
    }

    public function test_patient_messages_before_the_first_reply_are_capped(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $id = $this->book($patient, $doctor)->json('id');

        for ($i = 0; $i < 6; $i++) {
            $this->postJson("/api/nephrology/consultations/{$id}/messages", ['body' => "m{$i}"], $this->as($patient))->assertCreated();
        }
        $this->postJson("/api/nephrology/consultations/{$id}/messages", ['body' => 'one more'], $this->as($patient))->assertUnprocessable();
    }

    public function test_strangers_cannot_read_or_write_a_conversation(): void
    {
        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $stranger = $this->user('user');
        $id = $this->book($patient, $doctor)->json('id');

        $this->getJson("/api/nephrology/consultations/{$id}", $this->as($stranger))->assertNotFound();
        $this->postJson("/api/nephrology/consultations/{$id}/messages", ['body' => 'x'], $this->as($stranger))->assertNotFound();
        $this->getJson("/api/nephrologist/consultations/{$id}", $this->as($otherDoctor))->assertNotFound();
        $this->getJson("/api/nephrologist/consultations/{$id}/record", $this->as($otherDoctor))->assertNotFound();
        $this->getJson("/api/nephrologist/consultations/{$id}", $this->as($stranger))->assertForbidden();
        // The doctor-side API isn't reachable by the patient even for their own consultation.
        $this->getJson("/api/nephrologist/consultations/{$id}", $this->as($patient))->assertForbidden();
    }

    public function test_unanswered_consultation_is_refunded_in_full_by_housekeeping(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $id = $this->book($patient, $doctor)->json('id');
        $this->assertSame(5_500_000, $patient->fresh()->wallet_balance);

        $this->artisan('gordeh:nephrology-housekeeping')->assertSuccessful();
        $this->assertSame('awaiting_reply', NephroConsultation::find($id)->status); // not due yet

        NephroConsultation::whereKey($id)->update(['reply_due_at' => now()->subMinute()]);
        $this->artisan('gordeh:nephrology-housekeeping')->assertSuccessful();
        $this->artisan('gordeh:nephrology-housekeeping')->assertSuccessful(); // idempotent

        $c = NephroConsultation::find($id);
        $this->assertSame('refunded', $c->status);
        $this->assertSame('no_reply', $c->refund_reason);
        $this->assertSame(10_000_000, $patient->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::where('source', 'refund')->count());

        // A refunded conversation is closed, and earns the doctor nothing.
        $this->postJson("/api/nephrology/consultations/{$id}/messages", ['body' => 'hi'], $this->as($patient))->assertUnprocessable();
        $this->getJson('/api/nephrologist/earnings', $this->as($doctor))->assertOk()->assertJsonPath('summary.earned', 0);
    }

    public function test_patient_can_cancel_only_before_the_doctor_replies(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);

        $first = $this->book($patient, $doctor)->json('id');
        $this->postJson("/api/nephrology/consultations/{$first}/cancel", [], $this->as($patient))->assertOk();
        $this->assertSame(10_000_000, $patient->fresh()->wallet_balance);

        $second = $this->book($patient, $doctor)->json('id');
        $this->postJson("/api/nephrologist/consultations/{$second}/messages", ['body' => 'hello'], $this->as($doctor))->assertCreated();
        $this->postJson("/api/nephrology/consultations/{$second}/cancel", [], $this->as($patient))->assertUnprocessable();
        $this->postJson("/api/nephrologist/consultations/{$second}/decline", [], $this->as($doctor))->assertUnprocessable();
        $this->assertSame(5_500_000, $patient->fresh()->wallet_balance);
    }

    public function test_doctor_can_decline_an_unanswered_request(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $id = $this->book($patient, $doctor)->json('id');

        $this->postJson("/api/nephrologist/consultations/{$id}/decline", [], $this->as($doctor))->assertOk();

        $this->assertSame('doctor_declined', NephroConsultation::find($id)->refund_reason);
        $this->assertSame(10_000_000, $patient->fresh()->wallet_balance);
    }

    public function test_ending_credits_the_doctor_90_percent_and_payouts_respect_the_balance(): void
    {
        $doctor = $this->doctor(4_500_000);
        $patient = $this->user('user', 20_000_000);

        $id = $this->book($patient, $doctor)->json('id');
        $this->postJson("/api/nephrologist/consultations/{$id}/messages", ['body' => 'hello'], $this->as($doctor))->assertCreated();

        // Not yet withdrawable while in progress.
        $e = $this->getJson('/api/nephrologist/earnings', $this->as($doctor))->assertOk();
        $this->assertSame(0, $e->json('summary.available'));
        $this->assertSame(4_050_000, $e->json('summary.inProgress'));

        $this->postJson("/api/nephrologist/consultations/{$id}/end", ['summary' => 'short'], $this->as($doctor))->assertUnprocessable();
        $this->postJson("/api/nephrologist/consultations/{$id}/end", [
            'summary' => 'آزمایش‌ها بررسی شد و تکرار آن در سه ماه آینده لازم است.',
            'recommendations' => ['فشار خون را کنترل کنید'],
            'tests' => ['UACR'],
            'follow_up' => '3m',
        ], $this->as($doctor))->assertOk();

        $e = $this->getJson('/api/nephrologist/earnings', $this->as($doctor))->assertOk();
        $this->assertSame(4_050_000, $e->json('summary.available'));
        $this->assertSame(450_000, $e->json('summary.commission'));
        $this->assertSame(4_500_000, $e->json('summary.gross'));

        $this->postJson('/api/nephrologist/payouts', ['amount' => 5_000_000], $this->as($doctor))->assertUnprocessable();
        $this->postJson('/api/nephrologist/payouts', ['amount' => 500_000], $this->as($doctor))->assertUnprocessable(); // under the minimum
        $this->postJson('/api/nephrologist/payouts', ['amount' => 3_000_000], $this->as($doctor))->assertCreated();
        // The rest can be requested, but not the same money twice.
        $this->postJson('/api/nephrologist/payouts', ['amount' => 1_060_000], $this->as($doctor))->assertUnprocessable();
        $this->postJson('/api/nephrologist/payouts', ['amount' => 1_000_000], $this->as($doctor))->assertCreated();
        $this->assertSame(50_000, $this->getJson('/api/nephrologist/earnings', $this->as($doctor))->json('summary.available'));

        // The patient reads the published summary and may review once.
        $this->getJson("/api/nephrology/consultations/{$id}", $this->as($patient))->assertOk()->assertJsonPath('consultation.summary.follow_up', '3m');
        $this->postJson("/api/nephrology/consultations/{$id}/review", ['rating' => 5, 'text' => 'عالی'], $this->as($patient))->assertCreated();
        $this->postJson("/api/nephrology/consultations/{$id}/review", ['rating' => 1], $this->as($patient))->assertUnprocessable();
        $this->getJson('/api/nephrology/doctors', $this->as($patient))->assertOk()->assertJsonPath('0.ratingCount', 1)->assertJsonPath('0.rating', 5);
    }

    public function test_review_needs_a_finished_consultation(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $id = $this->book($patient, $doctor)->json('id');

        $this->postJson("/api/nephrology/consultations/{$id}/review", ['rating' => 5], $this->as($patient))->assertUnprocessable();
    }

    public function test_expired_active_conversation_closes_and_the_doctor_keeps_the_earning(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $id = $this->book($patient, $doctor)->json('id');
        $this->postJson("/api/nephrologist/consultations/{$id}/messages", ['body' => 'hello'], $this->as($doctor))->assertCreated();

        NephroConsultation::whereKey($id)->update(['expires_at' => now()->subMinute()]);
        $this->postJson("/api/nephrology/consultations/{$id}/messages", ['body' => 'late'], $this->as($patient))->assertUnprocessable();

        $this->artisan('gordeh:nephrology-housekeeping')->assertSuccessful();

        $c = NephroConsultation::find($id);
        $this->assertSame('ended', $c->status);
        $this->assertSame('system', $c->ended_by);
        $this->assertSame(4_050_000, $this->getJson('/api/nephrologist/earnings', $this->as($doctor))->json('summary.available'));
    }

    public function test_doctor_loses_access_to_the_record_30_days_after_the_end(): void
    {
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $id = $this->book($patient, $doctor)->json('id');
        $this->postJson("/api/nephrologist/consultations/{$id}/messages", ['body' => 'hello'], $this->as($doctor));
        $this->postJson("/api/nephrologist/consultations/{$id}/end", ['summary' => 'خلاصهٔ کافی برای پایان مشاوره'], $this->as($doctor))->assertOk();

        $this->getJson("/api/nephrologist/consultations/{$id}/record", $this->as($doctor))->assertOk();

        NephroConsultation::whereKey($id)->update(['ended_at' => now()->subDays(31)]);
        $this->getJson("/api/nephrologist/consultations/{$id}/record", $this->as($doctor))->assertForbidden();
    }

    public function test_public_doctor_list_exposes_no_private_data(): void
    {
        $doctor = $this->doctor();
        $doctor->nephrologistProfile->update(['national_id' => '0012345678']);
        $this->doctor(over: ['status' => 'pending']);

        $res = $this->getJson('/api/nephrology/doctors')->assertOk();
        $this->assertCount(1, $res->json());
        $body = json_encode($res->json());
        $this->assertStringNotContainsString('0012345678', $body);
        $this->assertStringNotContainsString('123456789012345678901234', $body);
        $this->assertStringNotContainsString($doctor->phone_number, $body);

        $this->getJson("/api/nephrology/doctors/{$doctor->id}")->assertOk()->assertJsonPath('doctor.id', $doctor->id);
        $pending = NephrologistProfile::where('status', 'pending')->first();
        $this->getJson("/api/nephrology/doctors/{$pending->user_id}")->assertNotFound();
    }

    public function test_application_to_approval_flow(): void
    {
        Storage::fake('local');
        $applicant = $this->user('user');
        $admin = $this->user('admin');

        $this->getJson('/api/nephrologist/application', $this->as($applicant))->assertOk()->assertJsonPath('status', 'none');

        // Not a doctor yet.
        $this->getJson('/api/nephrologist/me', $this->as($applicant))->assertForbidden();

        $this->putJson('/api/nephrologist/application', [
            'step' => 3, 'first_name' => 'سارا', 'last_name' => 'رحیمی', 'gender' => 'f', 'national_id' => '0012345678', 'council_number' => '123456',
            'base_specialty' => 'int', 'university' => 'دانشگاه نمونه', 'focus' => ['بیماری مزمن کلیه (CKD)'], 'fee' => 4_500_000, 'iban' => '123456789012345678901234',
            'terms' => true,
        ], $this->as($applicant))->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('fields.first_name', 'سارا');

        // An incomplete application can't be submitted.
        $this->postJson('/api/nephrologist/application/submit', [], $this->as($applicant))->assertUnprocessable()->assertJsonValidationErrors('documents');

        foreach (array_keys(array_filter(config('nephrology.documents'))) as $key) {
            $this->post("/api/nephrologist/application/documents/{$key}", ['file' => UploadedFile::fake()->image("{$key}.jpg")], $this->as($applicant) + ['Accept' => 'application/json'])->assertOk();
        }
        $this->postJson('/api/nephrologist/application/documents/bogus', [], $this->as($applicant))->assertNotFound();

        $this->postJson('/api/nephrologist/application/submit', [], $this->as($applicant))->assertOk()->assertJsonPath('status', 'pending');
        // Locked while under review.
        $this->putJson('/api/nephrologist/application', ['city' => 'Tehran'], $this->as($applicant))->assertUnprocessable();

        $profile = $applicant->nephrologistProfile;
        $this->assertSame('user', $applicant->fresh()->role);

        // Doctors aren't listed, and only admins review.
        $this->assertCount(0, $this->getJson('/api/nephrology/doctors')->json());
        $this->postJson("/api/admin/nephrology/doctors/{$profile->id}/review", ['decision' => 'approve'], $this->as($applicant))->assertForbidden();

        $this->postJson("/api/admin/nephrology/doctors/{$profile->id}/review", ['decision' => 'needs_fix'], $this->as($admin))->assertUnprocessable();
        $this->postJson("/api/admin/nephrology/doctors/{$profile->id}/review", ['decision' => 'needs_fix', 'note' => 'کارت خوانا نیست', 'doc' => 'councilB'], $this->as($admin))->assertOk();
        $this->getJson('/api/nephrologist/application', $this->as($applicant))->assertJsonPath('status', 'needs_fix')->assertJsonPath('reviewDoc', 'councilB');

        $this->post('/api/nephrologist/application/documents/councilB', ['file' => UploadedFile::fake()->image('b.jpg')], $this->as($applicant) + ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/nephrologist/application/submit', [], $this->as($applicant))->assertOk();

        $this->postJson("/api/admin/nephrology/doctors/{$profile->id}/review", ['decision' => 'approve'], $this->as($admin))->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame('nephrologist', $applicant->fresh()->role);

        $this->getJson('/api/nephrologist/me', $this->as($applicant))->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('isAccepting', false);
        // Approved, but patients can't book until the doctor switches on.
        $patient = $this->user('user', 10_000_000);
        $this->book($patient, $applicant)->assertUnprocessable();
        $this->patchJson('/api/nephrologist/profile', ['is_accepting' => true], $this->as($applicant))->assertOk()->assertJsonPath('isAccepting', true);
        $this->book($patient, $applicant)->assertCreated();

        // Suspension switches the doctor off and stops new bookings.
        $this->postJson("/api/admin/nephrology/doctors/{$profile->id}/review", ['decision' => 'suspend'], $this->as($admin))->assertOk();
        $this->book($this->user('user', 10_000_000), $applicant)->assertUnprocessable();
        $id = NephroConsultation::first()->id;
        $this->postJson("/api/nephrologist/consultations/{$id}/messages", ['body' => 'x'], $this->as($applicant))->assertUnprocessable();
    }

    public function test_admin_pays_out_and_only_admins_see_the_money_endpoints(): void
    {
        $doctor = $this->doctor();
        $admin = $this->user('admin');
        $patient = $this->user('user', 10_000_000);
        $id = $this->book($patient, $doctor)->json('id');
        $this->postJson("/api/nephrologist/consultations/{$id}/messages", ['body' => 'hello'], $this->as($doctor));
        $this->postJson("/api/nephrologist/consultations/{$id}/end", ['summary' => 'خلاصهٔ کافی برای پایان مشاوره'], $this->as($doctor))->assertOk();
        $payoutId = $this->postJson('/api/nephrologist/payouts', ['amount' => 2_000_000], $this->as($doctor))->assertCreated()->json('id');

        $this->getJson('/api/admin/nephrology/payouts', $this->as($doctor))->assertForbidden();
        $this->getJson('/api/admin/nephrology/overview', $this->as($patient))->assertForbidden();

        $o = $this->getJson('/api/admin/nephrology/overview', $this->as($admin))->assertOk();
        $this->assertSame(450_000, $o->json('commission'));
        $this->assertSame(2_000_000, $o->json('requestedPayouts'));

        $this->postJson("/api/admin/nephrology/payouts/{$payoutId}", ['decision' => 'paid'], $this->as($admin))->assertUnprocessable(); // needs a reference
        $this->postJson("/api/admin/nephrology/payouts/{$payoutId}", ['decision' => 'paid', 'reference' => 'TRX-1'], $this->as($admin))->assertOk();
        $this->postJson("/api/admin/nephrology/payouts/{$payoutId}", ['decision' => 'rejected', 'note' => 'x'], $this->as($admin))->assertUnprocessable(); // already processed

        $this->assertSame('paid', NephroPayout::find($payoutId)->status);
        $this->assertSame(2_050_000, $this->getJson('/api/nephrologist/earnings', $this->as($doctor))->json('summary.available'));

        // A rejected payout returns the money to the doctor's balance.
        $second = $this->postJson('/api/nephrologist/payouts', ['amount' => 1_500_000], $this->as($doctor))->json('id');
        $this->postJson("/api/admin/nephrology/payouts/{$second}", ['decision' => 'rejected', 'note' => 'IBAN اشتباه'], $this->as($admin))->assertOk();
        $this->assertSame(2_050_000, $this->getJson('/api/nephrologist/earnings', $this->as($doctor))->json('summary.available'));
    }

    public function test_attachments_belong_to_their_owner_and_are_typed(): void
    {
        Storage::fake('local');
        $doctor = $this->doctor();
        $patient = $this->user('user', 10_000_000);
        $other = $this->user('user');
        $headers = $this->as($patient) + ['Accept' => 'application/json'];

        $this->post('/api/nephrology/uploads', ['file' => UploadedFile::fake()->create('video.mp4', 100, 'video/mp4')], $headers)->assertUnprocessable();
        $fileId = $this->post('/api/nephrology/uploads', ['file' => UploadedFile::fake()->image('lab.jpg')], $headers)->assertCreated()->json('id');
        $foreign = StoredFile::create(['url' => 'uploads/x.jpg', 'fileName' => 'x.jpg', 'user_id' => $other->id, 'category' => 'nephro-consultation'])->id;

        $id = $this->book($patient, $doctor, ['file_ids' => [$fileId, $foreign]])->assertCreated()->json('id');
        $this->assertSame([$fileId], NephroConsultation::find($id)->file_ids);

        $this->postJson("/api/nephrology/consultations/{$id}/messages", ['file_id' => $foreign], $this->as($patient))->assertNotFound();
        $this->postJson("/api/nephrology/consultations/{$id}/messages", ['file_id' => $fileId], $this->as($patient))->assertCreated()->assertJsonPath('kind', 'file');
    }
}
