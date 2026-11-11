<?php

namespace App\Services;

/**
 * eGFR, CKD stage, UACR and KFRE risk from one set of lab values — used when a test is
 * saved and by `lab-tests:audit-uacr` to recompute stored ones.
 *
 * Units (the app converts before sending, see the frontend's utils/lab-units.ts):
 *   creatinine mg/dL, albumin g/dL, calcium mg/dL, phosphorous mg/dL, bCarbonate mmol/L,
 *   urine_albumin mg/L, urine_creatinine g/L — so urine_albumin ÷ urine_creatinine is mg/g.
 */
class LabTestScoring
{
    /**
     * Loose bounds on what can be stored — not a clinical range. Urine creatinine above
     * 8 g/L (800 mg/dL) is almost certainly a mg/dL value sent as g/L, which made UACR 100×
     * too small for tests entered from an uploaded report.
     */
    public const URINE_CREATININE_G_PER_L = [0.02, 8];

    public const URINE_ALBUMIN_MG_PER_L = [0, 20000];

    /**
     * @param  object  $input  age, gender, creatinine, albumin, urine_albumin, urine_creatinine,
     *                         and optionally calcium, phosphorous, bCarbonate
     * @return array{gfr: float, stage: int, albumin_creatinine_ratio: float, risk2Years: float, risk5Years: float}
     */
    public static function score(object $input): array
    {
        $age = $input->age;
        $gender = $input->gender;
        $urine_creatinine = $input->urine_creatinine;
        $urine_albumin = $input->urine_albumin;
        $creatinine = $input->creatinine;
        $albumin = $input->albumin;
        $calcium = $input->calcium ?? 9.35;
        $phosphorous = $input->phosphorous ?? 3.93;
        $bCarbonate = $input->bCarbonate ?? 25.54;

        // Tests are stored as 'm' / 'f' (see store()'s validation); comparing against 'female' / 'male'
        // used to run every patient through the male equation and leave out the Tangri male term.
        $isFemale = in_array(strtolower((string) $gender), ['f', 'female'], true);

        $k = $isFemale ? 0.7 : 0.9;
        $a = $isFemale ? -0.241 : -0.302;
        $genderCoeff = $isFemale ? 1.012 : 1.0;

        $minCr = min($creatinine / $k, 1);
        $maxCr = max($creatinine / $k, 1);

        $gfr = 142 * pow($minCr, $a) * pow($maxCr, -1.2) * pow(0.9938, $age) * $genderCoeff;
        $gfr = round($gfr, 2);

        // Stage classification
        $stage = 1;
        if ($gfr < 90) $stage = 2;
        if ($gfr < 60) $stage = 3;
        if ($gfr < 30) $stage = 4;
        if ($gfr < 15) $stage = 5;

        $acr = round(self::uacr($urine_albumin, $urine_creatinine), 2);

        // Linear predictor (Tangri 2011 centered variables)
        $lp =
            -0.4936 * ($gfr / 5 - 7.22) +
            0.16117 * (($isFemale ? 0 : 1) - 0.56) +
            0.35066 * (log(max($acr, 1e-9)) - 5.2775) +
            -0.19883 * ($age / 10 - 7.04) +
            -0.33867 * ($albumin - 3.99) +
            0.24197 * ($phosphorous - 3.93) +
            -0.07429 * ($bCarbonate - 25.54) +
            -0.22129 * ($calcium - 9.35);

        // Baseline survival rates
        $S0_5yr = 0.929;
        $S0_2yr = 0.978;

        $risk5Years = (1 - pow($S0_5yr, exp($lp))) * 100;
        $risk2Years = (1 - pow($S0_2yr, exp($lp))) * 100;

        return [
            'gfr' => round($gfr, 2),
            'stage' => $stage,
            'albumin_creatinine_ratio' => round($acr, 2),
            'risk2Years' => round($risk2Years, 2),
            'risk5Years' => round($risk5Years, 2),
        ];
    }

    /** Albumin-to-creatinine ratio in mg/g: urine albumin (mg/L) ÷ urine creatinine (g/L). */
    public static function uacr(float $urineAlbuminMgL, float $urineCreatinineGL): float
    {
        return $urineCreatinineGL != 0 ? $urineAlbuminMgL / $urineCreatinineGL : 0;
    }

    /** KDIGO albuminuria category. */
    public static function uacrCategory(float $uacr): string
    {
        if ($uacr > 300) return 'A3';
        if ($uacr >= 30) return 'A2';

        return 'A1';
    }
}
