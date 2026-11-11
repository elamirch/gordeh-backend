<?php

namespace App\Console\Commands;

use App\Models\LabTest;
use App\Models\LabTestFile;
use App\Services\LabTestScoring;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * READ-ONLY. Finds lab tests whose UACR was stored 100× too small because urine creatinine
 * reached /lab-tests in mg/dL instead of g/L (the uploaded-report path sent the model's
 * mg/dL value unconverted), and shows what UACR, its category and the KFRE risks would be
 * with the value converted. Nothing is written — not even with any option.
 */
class AuditLabTestUacr extends Command
{
    protected $signature = 'lab-tests:audit-uacr
                            {--csv= : Also write every flagged test to this CSV file}
                            {--all : List tests flagged for review too, not only the affected ones}';

    protected $description = 'Read-only: reports lab tests whose stored UACR used urine creatinine in mg/dL as if it were g/L';

    /** Model output for urine creatinine was always asked for in mg/dL before the fix. */
    private const OCR_UNIT_MG_DL = 'mg/dl';

    public function handle(): int
    {
        [, $maxCreatinine] = LabTestScoring::URINE_CREATININE_G_PER_L;

        // One row per test that either has an implausible stored value, or came with files.
        $tests = LabTest::query()
            ->where(fn ($q) => $q
                ->where('urine_creatinine', '>', $maxCreatinine)
                ->orWhereHas('files'))
            ->with('files')
            ->orderBy('id')
            ->get();

        $rows = $tests->map(fn (LabTest $test) => $this->assess($test, $maxCreatinine))->filter();

        $affected = $rows->where('verdict', 'affected');
        $review = $rows->where('verdict', 'review');

        $this->info('Lab tests checked: '.$tests->count().' (with uploaded files or implausible urine creatinine)');
        $this->line('Affected (UACR stored 100× too small): '.$affected->count()
            .' tests, '.$affected->pluck('user_id')->unique()->count().' users');
        $this->line('  …of which the A category changes: '.$affected->filter(fn ($r) => $r['stored_category'] !== $r['corrected_category'])->count());
        $this->line('  …of which it changes into A3: '.$affected->where('corrected_category', 'A3')->where('stored_category', '!=', 'A3')->count());
        $this->line('Needs manual review (from an upload, but the stored value does not match the OCR value): '.$review->count());

        $listed = $this->option('all') ? $rows : $affected;
        if ($listed->isNotEmpty()) {
            $this->table(
                ['test', 'user', 'created', 'source', 'verdict', 'u-alb mg/L', 'u-cr stored', 'u-cr OCR', 'UACR stored', 'UACR fixed', 'risk2 stored→fixed', 'risk5 stored→fixed'],
                $listed->map(fn ($r) => [
                    $r['lab_test_id'],
                    $r['user_id'],
                    $r['created_at'],
                    $r['source'],
                    $r['verdict'],
                    $r['urine_albumin'],
                    $r['urine_creatinine_stored'],
                    trim($r['ocr_urine_creatinine'].' '.$r['ocr_urine_creatinine_unit']),
                    $r['stored_uacr'].' '.$r['stored_category'],
                    $r['corrected_uacr'] === null ? '—' : $r['corrected_uacr'].' '.$r['corrected_category'],
                    $r['risk_2_years_stored'].' → '.($r['risk_2_years_corrected'] ?? '—'),
                    $r['risk_5_years_stored'].' → '.($r['risk_5_years_corrected'] ?? '—'),
                ])->all(),
            );
        }

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $rows);
            $this->info("CSV written to {$path}");
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null  null when there is nothing to report for this test */
    private function assess(LabTest $test, float $maxCreatinine): ?array
    {
        $ocrFile = $test->files->first(fn (LabTestFile $f) => $f->ocr_status === LabTestFile::OCR_SUCCESS
            && is_array($f->ocr_result));
        $ocr = $ocrFile?->ocr_result['urine_creatinine'] ?? null;
        $ocrValue = is_array($ocr) && is_numeric($ocr['value'] ?? null) ? (float) $ocr['value'] : null;
        $ocrUnit = is_array($ocr) ? (string) ($ocr['unit'] ?? '') : '';

        $stored = (float) $test->urine_creatinine;
        $matchesOcrMgDl = $ocrValue !== null
            && str_replace(' ', '', strtolower($ocrUnit)) === self::OCR_UNIT_MG_DL
            && abs($stored - $ocrValue) <= max(0.01, $ocrValue * 0.001);

        $verdict = match (true) {
            // No real urine has > 8 g/L creatinine: the number was mg/dL.
            $stored > $maxCreatinine, $matchesOcrMgDl => 'affected',
            $ocrFile !== null => 'review',
            default => null,
        };
        if ($verdict === null) return null;

        $storedUacr = (float) ($test->albumin_creatinine_ratio ?? $test->uacr ?? 0);
        $corrected = $verdict === 'affected' ? $this->rescore($test, $stored / 100) : null;

        return [
            'lab_test_id' => $test->id,
            'user_id' => $test->user_id,
            'created_at' => $test->created_at?->toDateTimeString(),
            'source' => $ocrFile ? 'ocr' : ($test->files->isNotEmpty() ? 'files' : 'none'),
            'verdict' => $verdict,
            'urine_albumin' => $test->urine_albumin,
            'urine_creatinine_stored' => $stored,
            'ocr_urine_creatinine' => $ocrValue,
            'ocr_urine_creatinine_unit' => $ocrUnit,
            'stored_uacr' => $storedUacr,
            'stored_category' => LabTestScoring::uacrCategory($storedUacr),
            'corrected_uacr' => $corrected['albumin_creatinine_ratio'] ?? null,
            'corrected_category' => $corrected ? LabTestScoring::uacrCategory($corrected['albumin_creatinine_ratio']) : null,
            'risk_2_years_stored' => $test->risk_2_years,
            'risk_2_years_corrected' => $corrected['risk2Years'] ?? null,
            'risk_5_years_stored' => $test->risk_5_years,
            'risk_5_years_corrected' => $corrected['risk5Years'] ?? null,
        ];
    }

    /** Scores the stored inputs again with urine creatinine in g/L. Pure computation. */
    private function rescore(LabTest $test, float $urineCreatinineGL): array
    {
        return LabTestScoring::score((object) [
            'age' => $test->age,
            'gender' => $test->gender,
            'creatinine' => $test->creatinine,
            'albumin' => $test->albumin,
            'calcium' => $test->calcium,
            'phosphorous' => $test->phosphorous,
            'bCarbonate' => $test->b_carbonate,
            'urine_albumin' => $test->urine_albumin,
            'urine_creatinine' => $urineCreatinineGL,
        ]);
    }

    private function writeCsv(string $path, Collection $rows): void
    {
        $handle = fopen($path, 'w');
        if ($rows->isNotEmpty()) {
            fputcsv($handle, array_keys($rows->first()));
            $rows->each(fn ($row) => fputcsv($handle, $row));
        }
        fclose($handle);
    }
}
