<?php

namespace Tests\Feature;

use App\Models\Insurance;
use App\Models\LabTest;
use App\Models\Payment;
use App\Models\QuickAssessment;
use App\Models\ScheduledSMS;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $balance = 0, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
            'wallet_balance' => $balance,
        ]);
    }

    private function as(User $user): array
    {
        $this->resetJwtState();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function labTestBody(): array
    {
        return [
            'age' => 50, 'gender' => 'm', 'creatinine' => 1.0,
            'urine_creatinine' => 1.0, 'urine_albumin' => 30, 'albumin' => 4.0,
        ];
    }

    private function fakeZarinpal(string $authority = 'A00000000000000000000000000000001', int $code = 100): void
    {
        config(['services.zarinpal' => []]);
        Http::fake([
            '*request.json' => Http::response(['data' => ['authority' => $authority, 'code' => 100]]),
            '*verify.json' => Http::response(['data' => ['code' => $code, 'ref_id' => 123456]]),
        ]);
    }

    // ---- wallet: reading ----

    public function test_wallet_shows_balance_prices_and_topup_options(): void
    {
        $user = $this->user(7_000_000);

        $this->getJson('/api/wallet', $this->as($user))
            ->assertOk()
            ->assertJsonPath('balance', 7_000_000)
            ->assertJsonPath('prices.lab_test', 3_990_000)
            ->assertJsonPath('prices.consultation', 4_500_000)
            ->assertJsonPath('credits.lab_test', 0)
            ->assertJsonStructure(['topups', 'min_topup', 'max_topup', 'transactions']);
    }

    // ---- wallet: topping up ----

    public function test_topup_credits_the_wallet_once_when_the_payment_is_verified(): void
    {
        $user = $this->user();
        $this->fakeZarinpal('A-TOPUP-1');

        $this->postJson('/api/wallet/topup', ['amount' => 10_000_000], $this->as($user))
            ->assertOk()
            ->assertJsonPath('authority', 'A-TOPUP-1');

        $this->assertDatabaseHas('payments', ['authority' => 'A-TOPUP-1', 'purpose' => 'wallet', 'status' => 'pending', 'is_used_lab_test' => true]);
        $this->assertSame(0, (int) $user->fresh()->wallet_balance);

        $this->postJson('/api/payments/verify', ['authority' => 'A-TOPUP-1'], $this->as($user))->assertJsonPath('status', 'success');
        // The web callback page and the app both verify: the second must not credit again.
        $this->postJson('/api/payments/verify', ['authority' => 'A-TOPUP-1'], $this->as($user))->assertJsonPath('status', 'success');

        $this->assertSame(10_000_000, (int) $user->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'credit')->count());
    }

    public function test_a_failed_payment_does_not_credit_the_wallet(): void
    {
        $user = $this->user();
        $this->fakeZarinpal('A-TOPUP-2', code: -50);

        $this->postJson('/api/wallet/topup', ['amount' => 10_000_000], $this->as($user))->assertOk();
        $this->postJson('/api/payments/verify', ['authority' => 'A-TOPUP-2'], $this->as($user))->assertJsonPath('status', 'failed');

        $this->assertSame(0, (int) $user->fresh()->wallet_balance);
    }

    public function test_topup_amount_must_be_within_limits(): void
    {
        $user = $this->user();
        $this->fakeZarinpal();

        $this->postJson('/api/wallet/topup', ['amount' => 5_000], $this->as($user))->assertUnprocessable();
        $this->postJson('/api/wallet/topup', ['amount' => 900_000_000], $this->as($user))->assertUnprocessable();
    }

    public function test_a_one_off_payment_cannot_buy_a_credit_for_less_than_the_price(): void
    {
        $user = $this->user();
        $this->fakeZarinpal();

        // The gateway amount is the client's choice: 1000 rial must not buy an analysis.
        $this->postJson('/api/payments/request', ['amount' => 1_000], $this->as($user))->assertUnprocessable();
        $this->postJson('/api/payments/request', ['amount' => 3_990_000], $this->as($user))->assertOk();
    }

    public function test_the_same_credit_reference_never_credits_twice(): void
    {
        $user = $this->user();
        $wallet = new WalletService;

        $wallet->credit($user, 1_000_000, 'bazaar', 'token-1', 'test');
        $wallet->credit($user, 1_000_000, 'bazaar', 'token-1', 'test');

        $this->assertSame(1_000_000, $wallet->balance($user));
    }

    // ---- paying for things ----

    public function test_a_lab_test_is_paid_from_the_wallet(): void
    {
        $user = $this->user(10_000_000);

        $this->postJson('/api/lab-tests', $this->labTestBody(), $this->as($user))->assertCreated();

        $this->assertSame(10_000_000 - 3_990_000, (int) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $user->id, 'type' => 'debit', 'source' => 'lab_test', 'amount' => 3_990_000]);
    }

    public function test_a_legacy_credit_is_spent_before_the_wallet(): void
    {
        $user = $this->user(10_000_000);
        Payment::create(['user_id' => $user->id, 'amount' => 0, 'authority' => Str::random(20), 'status' => 'success', 'is_used_lab_test' => false, 'is_used_insurance' => false]);

        $this->postJson('/api/lab-tests', $this->labTestBody(), $this->as($user))->assertCreated();

        $this->assertSame(10_000_000, (int) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('payments', ['user_id' => $user->id, 'is_used_lab_test' => true, 'is_used_insurance' => false]);
    }

    public function test_an_empty_wallet_refuses_with_the_shortfall_and_saves_nothing(): void
    {
        $user = $this->user(1_000_000);

        $this->postJson('/api/lab-tests', $this->labTestBody(), $this->as($user))
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_wallet')
            ->assertJsonPath('required', 3_990_000)
            ->assertJsonPath('balance', 1_000_000)
            ->assertJsonPath('shortfall', 2_990_000);

        $this->assertSame(0, LabTest::count());
        $this->assertSame(1_000_000, (int) $user->fresh()->wallet_balance);
    }

    public function test_a_rejected_request_is_not_charged(): void
    {
        $user = $this->user(10_000_000);

        // Out-of-range urine creatinine fails validation before anything is spent.
        $this->postJson('/api/lab-tests', ['urine_creatinine' => 9999] + $this->labTestBody(), $this->as($user))->assertUnprocessable();

        $this->assertSame(10_000_000, (int) $user->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_an_insurance_request_is_paid_from_the_wallet(): void
    {
        $user = $this->user(5_000_000);

        $this->postJson('/api/insurances', ['national_code' => '0012345678', 'insurance_type' => 'tamin', 'first_name' => 'علی', 'last_name' => 'رضایی'], $this->as($user))
            ->assertCreated();

        $this->assertSame(5_000_000 - 3_990_000, (int) $user->fresh()->wallet_balance);
    }

    public function test_the_wallet_never_goes_negative_under_repeated_charges(): void
    {
        $user = $this->user(5_000_000); // enough for exactly one analysis

        $this->postJson('/api/lab-tests', $this->labTestBody(), $this->as($user))->assertCreated();
        $this->postJson('/api/lab-tests', $this->labTestBody(), $this->as($user))->assertForbidden();

        $this->assertSame(1, LabTest::count());
        $this->assertSame(1_010_000, (int) $user->fresh()->wallet_balance);
    }

    public function test_a_pre_paid_bazaar_consultation_is_spent_by_the_booking_not_the_wallet(): void
    {
        $user = $this->user(0);
        Payment::create([
            'user_id' => $user->id, 'amount' => 4_500_000, 'authority' => 'bazaar-x', 'status' => 'success', 'purpose' => 'consultation',
            'is_used_lab_test' => true, 'is_used_insurance' => true, 'is_used_consultation' => false,
        ]);

        $this->assertTrue(app(\App\Services\PaymentService::class)->canAfford($user, 'consultation'));
        $this->assertFalse(app(\App\Services\PaymentService::class)->canAfford($user, 'lab_test'));
    }

    // ---- admin ----

    public function test_admin_can_add_money_to_a_wallet(): void
    {
        $admin = $this->user(0, 'admin');
        $patient = $this->user();

        $this->postJson('/api/admin/credits', ['phoneNumber' => $patient->phone_number, 'walletAmount' => 20_000_000], $this->as($admin))
            ->assertCreated()
            ->assertJsonPath('walletBalance', 20_000_000);

        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $patient->id, 'source' => 'admin', 'amount' => 20_000_000]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_admin_credit_needs_a_count_or_an_amount(): void
    {
        $admin = $this->user(0, 'admin');
        $patient = $this->user();

        $this->postJson('/api/admin/credits', ['phoneNumber' => $patient->phone_number], $this->as($admin))->assertUnprocessable();
    }

    // ---- deleting and editing ----

    public function test_owner_deletes_a_test_with_its_reminders_and_others_cannot(): void
    {
        $owner = $this->user(10_000_000);
        $other = $this->user();
        $id = $this->postJson('/api/lab-tests', $this->labTestBody(), $this->as($owner))->assertCreated()->json('data.id');
        $this->assertSame(3, ScheduledSMS::where('lab_test_id', $id)->count());

        $this->deleteJson("/api/lab-tests/{$id}", [], $this->as($other))->assertForbidden();
        $this->assertDatabaseHas('lab_tests', ['id' => $id]);

        $this->deleteJson("/api/lab-tests/{$id}", [], $this->as($owner))->assertNoContent();
        $this->assertDatabaseMissing('lab_tests', ['id' => $id]);
        $this->assertSame(0, ScheduledSMS::where('lab_test_id', $id)->count());
    }

    public function test_deleting_a_missing_test_is_a_404(): void
    {
        $this->deleteJson('/api/lab-tests/99999', [], $this->as($this->user()))->assertNotFound();
    }

    public function test_deleting_the_latest_test_hands_the_reminders_to_the_previous_one(): void
    {
        $user = $this->user(20_000_000);
        $first = $this->postJson('/api/lab-tests', $this->labTestBody(), $this->as($user))->json('data.id');
        $second = $this->postJson('/api/lab-tests', $this->labTestBody(), $this->as($user))->json('data.id');
        LabTest::whereKey($first)->update(['created_at' => now()->subDays(40)]);

        $this->deleteJson("/api/lab-tests/{$second}", [], $this->as($user))->assertNoContent();

        $this->assertSame(0, ScheduledSMS::where('lab_test_id', $second)->count());
        $this->assertGreaterThan(0, ScheduledSMS::where('lab_test_id', $first)->where('status', 'pending')->count());
    }

    private function insurance(User $user, string $status = 'created'): Insurance
    {
        return Insurance::create([
            'user_id' => $user->id, 'national_code' => '0012345678', 'insurance_type' => 'tamin',
            'first_name' => 'علی', 'last_name' => 'رضایی', 'status' => $status,
        ]);
    }

    public function test_owner_can_edit_an_insurance_request_until_it_is_picked_up(): void
    {
        $user = $this->user();
        $insurance = $this->insurance($user);

        $this->patchJson("/api/insurances/{$insurance->id}", ['first_name' => 'محمد', 'insurance_type' => 'ihio'], $this->as($user))
            ->assertOk();
        $this->assertDatabaseHas('insurances', ['id' => $insurance->id, 'first_name' => 'محمد', 'insurance_type' => 'ihio']);

        $insurance->update(['status' => 'in_progress']);
        $this->patchJson("/api/insurances/{$insurance->id}", ['first_name' => 'رضا'], $this->as($user))->assertStatus(409);
        $this->assertDatabaseHas('insurances', ['id' => $insurance->id, 'first_name' => 'محمد']);
    }

    public function test_owner_cannot_change_status_or_identification_code(): void
    {
        $user = $this->user();
        $insurance = $this->insurance($user);

        $this->patchJson("/api/insurances/{$insurance->id}", ['status' => 'completed'], $this->as($user))->assertForbidden();
        $this->patchJson("/api/insurances/{$insurance->id}", ['identification_code' => '123'], $this->as($user))->assertForbidden();
        $this->assertDatabaseHas('insurances', ['id' => $insurance->id, 'status' => 'created']);
    }

    public function test_someone_else_cannot_edit_or_delete_an_insurance_request(): void
    {
        $insurance = $this->insurance($this->user());
        $stranger = $this->user();

        $this->patchJson("/api/insurances/{$insurance->id}", ['first_name' => 'x'], $this->as($stranger))->assertForbidden();
        $this->deleteJson("/api/insurances/{$insurance->id}", [], $this->as($stranger))->assertForbidden();
    }

    public function test_owner_cannot_delete_a_request_once_it_is_picked_up(): void
    {
        $user = $this->user();
        $insurance = $this->insurance($user, 'in_progress');

        $this->deleteJson("/api/insurances/{$insurance->id}", [], $this->as($user))->assertStatus(409);
        $this->assertDatabaseHas('insurances', ['id' => $insurance->id]);

        $this->deleteJson("/api/insurances/{$insurance->id}", [], $this->as($this->user(0, 'admin')))->assertNoContent();
    }

    public function test_owner_deletes_an_insurance_request_and_a_missing_one_is_a_404(): void
    {
        $user = $this->user();
        $insurance = $this->insurance($user);

        $this->deleteJson("/api/insurances/{$insurance->id}", [], $this->as($user))->assertNoContent();
        $this->assertDatabaseMissing('insurances', ['id' => $insurance->id]);
        $this->deleteJson('/api/insurances/99999', [], $this->as($user))->assertNotFound();
    }

    // ---- quick assessment ----

    private function assessmentBody(array $over = []): array
    {
        return array_merge([
            'answers' => ['bp' => 'yes', 'dm' => 'no', 'fam' => 'unsure', 'aki' => 'no', 'bmi' => 'yes'],
            'age' => 52, 'gender' => 'male', 'creatinine' => 1.4, 'gfr' => 58.2, 'risk_level' => 2, 'source' => 'app',
        ], $over);
    }

    public function test_quick_assessment_is_stored_per_account_and_read_back_anywhere(): void
    {
        $user = $this->user();

        $this->getJson('/api/quick-assessment', $this->as($user))->assertOk()->assertJsonPath('completed', false);

        $this->putJson('/api/quick-assessment', $this->assessmentBody(), $this->as($user))->assertCreated()->assertJsonPath('completed', true);

        // «Another device»: nothing local, but the account has it.
        $this->getJson('/api/quick-assessment', $this->as($user))
            ->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('assessment.risk_level', 2)
            ->assertJsonPath('assessment.answers.bp', 'yes');
    }

    public function test_redoing_the_quick_assessment_replaces_it(): void
    {
        $user = $this->user();
        $this->putJson('/api/quick-assessment', $this->assessmentBody(), $this->as($user))->assertCreated();
        $this->putJson('/api/quick-assessment', $this->assessmentBody(['risk_level' => 0, 'creatinine' => null, 'gfr' => null]), $this->as($user))->assertOk();

        $this->assertSame(1, QuickAssessment::where('user_id', $user->id)->count());
        $this->assertSame(0, QuickAssessment::first()->risk_level);
    }

    public function test_quick_assessment_validates_its_input(): void
    {
        $user = $this->user();

        $this->putJson('/api/quick-assessment', $this->assessmentBody(['answers' => ['bp' => 'maybe']]), $this->as($user))->assertUnprocessable();
        $this->putJson('/api/quick-assessment', $this->assessmentBody(['risk_level' => 7]), $this->as($user))->assertUnprocessable();
        $this->putJson('/api/quick-assessment', $this->assessmentBody(['gender' => 'x']), $this->as($user))->assertUnprocessable();
        $this->getJson('/api/quick-assessment')->assertUnauthorized();
    }

    public function test_admin_lists_quick_assessments_with_phone_and_risk_split(): void
    {
        $admin = $this->user(0, 'admin');
        $a = $this->user();
        $b = $this->user();
        $this->putJson('/api/quick-assessment', $this->assessmentBody(['risk_level' => 2]), $this->as($a))->assertCreated();
        $this->putJson('/api/quick-assessment', $this->assessmentBody(['risk_level' => 0]), $this->as($b))->assertCreated();

        $this->getJson('/api/admin/quick-assessments', $this->as($admin))
            ->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.by_risk.high', 1)
            ->assertJsonPath('summary.by_risk.low', 1)
            ->assertJsonFragment(['phone_number' => $a->phone_number]);

        $this->getJson('/api/admin/quick-assessments?risk_level=0', $this->as($admin))->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/quick-assessments', $this->as($a))->assertForbidden();
    }

    // ---- webinars ----

    public function test_webinars_are_listed_for_guests_upcoming_first(): void
    {
        config(['webinars' => [
            'old-one' => ['starts_at' => now()->subMonth()->format('Y-m-d H:i'), 'title' => 'قدیمی'],
            'next-one' => ['starts_at' => now()->addWeek()->format('Y-m-d H:i'), 'title' => 'بعدی'],
        ]]);

        $this->getJson('/api/webinars')
            ->assertOk()
            ->assertJsonPath('0.slug', 'next-one')
            ->assertJsonPath('0.status', 'upcoming')
            ->assertJsonPath('0.registration_open', true)
            ->assertJsonPath('1.slug', 'old-one')
            ->assertJsonPath('1.status', 'past')
            ->assertJsonPath('1.registration_open', false);
    }
}
