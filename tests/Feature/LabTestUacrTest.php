<?php

namespace Tests\Feature;

use App\Models\LabTest;
use App\Models\LabTestFile;
use App\Models\Payment;
use App\Models\User;
use App\Services\LabTestScoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * UACR = urine albumin (mg/L) ÷ urine creatinine (g/L) — equivalently mg/L ÷ mg/dL × 100.
 * Reported case: 357.5 mg/L and 39.22 mg/dL must give ≈ 911.5 mg/g (A3), not 9.12 (A1).
 */
class LabTestUacrTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'phone_number' => '09'.fake()->unique()->numerify('#########'),
        ]);
    }

    private function submit(array $urine, ?User $user = null)
    {
        $user ??= $this->user();
        Payment::create([
            'user_id' => $user->id,
            'amount' => 1000,
            'authority' => Str::random(20),
            'status' => 'success',
            'is_used_lab_test' => false,
            'is_used_insurance' => false,
        ]);
        $this->resetJwtState();

        return $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user)])
            ->postJson('/api/lab-tests', [
                'age' => 60,
                'gender' => 'm',
                'creatinine' => 1.8,
                'albumin' => 4.0,
                'calcium' => 9.4,
                'phosphorous' => 3.8,
            ] + $urine);
    }

    public function test_reported_case_is_stored_as_a3(): void
    {
        // What the app now sends: 39.22 mg/dL converted to 0.3922 g/L.
        $test = $this->submit(['urine_albumin' => 357.5, 'urine_creatinine' => 0.3922])
            ->assertCreated()
            ->json('data');

        $this->assertEqualsWithDelta(911.5, $test['albumin_creatinine_ratio'], 0.1);
        $this->assertSame('A3', LabTestScoring::uacrCategory($test['albumin_creatinine_ratio']));
    }

    public function test_kfre_risk_uses_the_corrected_uacr(): void
    {
        $base = ['age' => 60, 'gender' => 'm', 'creatinine' => 1.8, 'albumin' => 4.0, 'calcium' => 9.4, 'phosphorous' => 3.8, 'urine_albumin' => 357.5];
        $correct = LabTestScoring::score((object) ($base + ['urine_creatinine' => 0.3922]));
        $buggy = LabTestScoring::score((object) ($base + ['urine_creatinine' => 39.22]));

        $this->assertEqualsWithDelta(911.52, $correct['albumin_creatinine_ratio'], 0.01);
        $this->assertEqualsWithDelta(9.12, $buggy['albumin_creatinine_ratio'], 0.01);
        // ln(100) × 0.35066 more in the linear predictor: several times the risk.
        $this->assertGreaterThan($buggy['risk2Years'] * 3, $correct['risk2Years']);
        $this->assertGreaterThan($buggy['risk5Years'] * 3, $correct['risk5Years']);
    }

    public function test_urine_creatinine_sent_in_mg_dl_is_rejected_not_scored(): void
    {
        // The old upload path: 39.22 (mg/dL) where g/L is expected.
        $this->submit(['urine_albumin' => 357.5, 'urine_creatinine' => 39.22])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('urine_creatinine');

        $this->assertDatabaseCount('lab_tests', 0);
    }

    public function test_category_boundaries(): void
    {
        $this->assertSame('A1', LabTestScoring::uacrCategory(29.99));
        $this->assertSame('A2', LabTestScoring::uacrCategory(30));
        $this->assertSame('A2', LabTestScoring::uacrCategory(300));
        $this->assertSame('A3', LabTestScoring::uacrCategory(300.01));

        // 30 mg/L ÷ 1 g/L (100 mg/dL) and 300 mg/L ÷ 1 g/L through the API.
        $at30 = $this->submit(['urine_albumin' => 30, 'urine_creatinine' => 1])->assertCreated()->json('data');
        $this->assertSame('A2', LabTestScoring::uacrCategory($at30['albumin_creatinine_ratio']));
        $at300 = $this->submit(['urine_albumin' => 300, 'urine_creatinine' => 1])->assertCreated()->json('data');
        $this->assertSame('A2', LabTestScoring::uacrCategory($at300['albumin_creatinine_ratio']));
    }

    public function test_audit_reports_affected_tests_without_changing_them(): void
    {
        $user = $this->user();
        $values = ['age' => 60, 'gender' => 'm', 'creatinine' => 1.8, 'albumin' => 4.0, 'calcium' => 9.4, 'phosphorous' => 3.8, 'urine_albumin' => 357.5];

        // Saved by the old upload path: mg/dL value stored as-is, UACR 9.12.
        $buggy = LabTest::create($values + [
            'user_id' => $user->id, 'urine_creatinine' => 39.22, 'albumin_creatinine_ratio' => 9.12,
            'gfr' => 40, 'stage' => 3, 'risk_2_years' => 1.0, 'risk_5_years' => 3.0,
        ]);
        LabTestFile::create([
            'user_id' => $user->id, 'lab_test_id' => $buggy->id, 'disk' => 'local', 'storage_key' => 'x',
            'original_filename' => 'lab.jpg', 'mime_type' => 'image/jpeg', 'size' => 1,
            'ocr_status' => LabTestFile::OCR_SUCCESS,
            'ocr_result' => ['error' => false, 'urine_creatinine' => ['value' => 39.22, 'unit' => 'mg/dL']],
        ]);
        // Dilute urine from the upload path: plausible as g/L, caught by matching the OCR value.
        $dilute = LabTest::create($values + [
            'user_id' => $user->id, 'urine_creatinine' => 5.1, 'albumin_creatinine_ratio' => 70.1,
            'gfr' => 40, 'stage' => 3, 'risk_2_years' => 1.0, 'risk_5_years' => 3.0,
        ]);
        LabTestFile::create([
            'user_id' => $user->id, 'lab_test_id' => $dilute->id, 'disk' => 'local', 'storage_key' => 'y',
            'original_filename' => 'lab.jpg', 'mime_type' => 'image/jpeg', 'size' => 1,
            'ocr_status' => LabTestFile::OCR_SUCCESS,
            'ocr_result' => ['error' => false, 'urine_creatinine' => ['value' => 5.1, 'unit' => 'mg/dL']],
        ]);
        // Entered by hand, correct: not reported.
        $manual = LabTest::create($values + [
            'user_id' => $user->id, 'urine_creatinine' => 0.3922, 'albumin_creatinine_ratio' => 911.52,
            'gfr' => 40, 'stage' => 3, 'risk_2_years' => 10.0, 'risk_5_years' => 30.0,
        ]);

        $before = LabTest::orderBy('id')->get()->toArray();
        $csv = tempnam(sys_get_temp_dir(), 'uacr');

        $this->artisan('lab-tests:audit-uacr', ['--csv' => $csv])
            ->expectsOutputToContain('Affected (UACR stored 100× too small): 2 tests, 1 users')
            ->expectsOutputToContain('…of which it changes into A3: 2')
            ->assertSuccessful();

        $rows = array_map('str_getcsv', array_filter(explode("\n", file_get_contents($csv))));
        $header = array_shift($rows);
        $byId = collect($rows)->map(fn ($r) => array_combine($header, $r))->keyBy('lab_test_id');
        $this->assertEqualsWithDelta(911.52, (float) $byId[$buggy->id]['corrected_uacr'], 0.01);
        $this->assertSame('A3', $byId[$buggy->id]['corrected_category']);
        $this->assertFalse($byId->has($manual->id));

        // Read-only: nothing changed.
        $this->assertEquals($before, LabTest::orderBy('id')->get()->toArray());
        unlink($csv);
    }
}
