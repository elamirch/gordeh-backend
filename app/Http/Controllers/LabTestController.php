<?php

namespace App\Http\Controllers;

use App\Models\LabTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use App\Models\ScheduledSms;

class LabTestController extends Controller
{
    // Create a new test (equivalent to create in Nest service)
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'age' => 'required|integer',
            'gender' => 'required|in:m,f',
            'urine_creatinine' => 'required|numeric',
            'urine_albumin' => 'required|numeric',
            'creatinine' => 'required|numeric',
            'albumin' => 'required|numeric',
            'calcium' => 'nullable|numeric',
            'phosphorous' => 'nullable|numeric',
            'bCarbonate' => 'nullable|numeric',
        ]);

        try {
            $user = auth()->user();
            $gfrResult = $this->generateGfr((object) $data);

            $test = LabTest::create([
                'b_carbonate' => $data['bCarbonate'] ?? null,
                'age' => $data['age'],
                'albumin' => $data['albumin'],
                'urine_albumin' => $data['urine_albumin'],
                'calcium' => $data['calcium'] ?? null,
                'gfr' => $gfrResult['gfr'],
                'gender' => $data['gender'],
                'risk_2_years' => $gfrResult['risk2Years'],
                'risk_5_years' => $gfrResult['risk5Years'],
                'phosphorous' => $data['phosphorous'] ?? null,
                'creatinine' => $data['creatinine'],
                'urine_creatinine' => $data['urine_creatinine'],
                'stage' => $gfrResult['stage'],
                'user_id' => $user ? $user->id : null,
                'albumin_creatinine_ratio' => $gfrResult['albumin_creatinine_ratio'],
            ]);

            //Schedule assessment reminder
            if($gfrResult['gfr'] > 89) {
                $stage = 1;
            } elseif ($gfrResult['gfr'] > 59) {
                $stage = 2;
            } elseif ($gfrResult['gfr'] > 29) {
                $stage = 3;
            }
            elseif ($gfrResult['gfr'] > 14) {
                $stage = 4;
            } else {
                $stage = 1;
            }
            
            $date = now()->addDays(365);

            //7 days before date reminder
            $this->scheduleAssessmentReminderSMS(
                'cron-assess-reminder-7d',
                $stage,
                $this->gregorianToJalali($date->year + 1, $date->month, $date->day)
            );

            //Day reminder
            $this->scheduleAssessmentReminderSMS(
                'cron-assess-reminder',
                $stage
            );

            //Due reminder after 30 days
            $this->scheduleAssessmentReminderSMS(
                'cron-assess-reminder-after',
                $stage
            );

            //Due reminder after 14 for high risk users
            $this->scheduleAssessmentReminderSMS(
                'cron-assess-reminder-after-14d-onlyhigh',
                $stage
            );
            

            return response()->json([
                'message' => 'test saved successfully.',
                'data' => $test,
            ], 201);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    // Helper: GFR generation logic
    private function generateGfr(object $input): array
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

        $k = $gender === 'female' ? 0.7 : 0.9;
        $a = $gender === 'female' ? -0.241 : -0.302;
        $genderCoeff = $gender === 'female' ? 1.012 : 1.0;

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

        // Albumin-to-creatinine ratio (mg/g)
        $acr = $urine_creatinine != 0 ? ($urine_albumin / $urine_creatinine) : 0;
        $acr = round($acr, 2);

        // Linear predictor (Tangri 2011 centered variables)
        $lp =
            -0.4936 * ($gfr / 5 - 7.22) +
            0.16117 * (($gender === 'male' ? 1 : 0) - 0.56) +
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

    // Paginated list of authenticated user's tests (equivalent to findAllOwnTests)
    public function indexOwn(Request $request): JsonResponse
    {
        $page = max(1, intval($request->query('page', 1)));
        $limit = $request->has('limit') ? intval($request->query('limit')) : 10;
        if ($limit <= 0 || $limit > 100) $limit = 10;

        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = LabTest::where('user_id', $user->id)->with('user');
        $count = $query->count();
        $data = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $data,
            'count' => $count,
        ]);
    }

    // Paginated list of all tests (equivalent to findAll)
    public function index(Request $request): JsonResponse
    {
        $page = max(1, intval($request->query('page', 1)));
        $limit = $request->has('limit') ? intval($request->query('limit')) : 10;
        if ($limit <= 0 || $limit > 100) $limit = 10;

        $query = LabTest::with('user');
        $count = $query->count();
        $data = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $data,
            'count' => $count,
        ]);
    }

    // Find by id (equivalent to findById)
    public function show($id): JsonResponse
    {
        $test = LabTest::find($id);

        if (! $test) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'message' => 'test found',
            'data' => $test,
        ]);
    }

    // Monthly avg GFR for a user (converted to Jalaali YYYY-MM)
    public function userMonthlyAvgGfr(): JsonResponse
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $result = DB::table('lab_tests as t')
            ->selectRaw("DATE_FORMAT(t.created_at, '%Y-%m-01') as month")
            ->selectRaw("AVG(t.gfr) as avgGfr")
            ->where('t.user_id', $user->id)
            ->groupByRaw("DATE_FORMAT(t.created_at, '%Y-%m-01')")
            ->orderBy('month', 'asc')
            ->get();

        $mapped = $result->map(function ($row) {
            $date = new \DateTime($row->month);
            $j = $this->gregorianToJalali(
                (int) $date->format('Y'),
                (int) $date->format('n'),
                (int) $date->format('j')
            );
            $monthLabel = sprintf('%04d-%02d', $j['jy'], $j['jm']);
            return [
                'month' => $monthLabel,
                'avgGfr' => (float) $row->avgGfr,
            ];
        });

        return response()->json($mapped->values());
    }

    // Monthly avg GFR for all users (converted to Jalaali YYYY-MM)
    public function allUsersMonthlyAvgGfr(): JsonResponse
    {
        $result = DB::table('lab_tests as t')
            ->selectRaw("DATE_FORMAT(t.created_at, '%Y-%m-01') as month")
            ->selectRaw("AVG(t.gfr) as avgGfr")
            ->groupByRaw("DATE_FORMAT(t.created_at, '%Y-%m-01')")
            ->orderBy('month', 'asc')
            ->get();

        $mapped = $result->map(function ($row) {
            $date = new \DateTime($row->month);
            $j = $this->gregorianToJalali(
                (int) $date->format('Y'),
                (int) $date->format('n'),
                (int) $date->format('j')
            );
            $monthLabel = sprintf('%04d-%02d', $j['jy'], $j['jm']);
            return [
                'month' => $monthLabel,
                'avgGfr' => (float) $row->avggfr,
            ];
        });

        return response()->json($mapped->values());
    }

    /**
     * Convert Gregorian date to Jalali (returns array with keys jy, jm, jd)
     * Implementation adapted from common algorithms — lightweight, no external package.
     */
    private function gregorianToJalali(int $g_y, int $g_m, int $g_d): array
    {
        $g_days_in_month = [31,28,31,30,31,30,31,31,30,31,30,31];
        $j_days_in_month = [31,31,31,31,31,31,30,30,30,30,30,29];

        $gy = $g_y-1600;
        $gm = $g_m-1;
        $gd = $g_d-1;

        $g_day_no = 365*$gy + intval(($gy+3)/4) - intval(($gy+99)/100) + intval(($gy+399)/400);
        for ($i=0; $i < $gm; ++$i) $g_day_no += $g_days_in_month[$i];
        if ($gm>1 && (($gy%4==0 && $gy%100!=0) || ($gy%400==0))) $g_day_no++;
        $g_day_no += $gd;

        $j_day_no = $g_day_no - 79;
        $j_np = intval($j_day_no / 12053);
        $j_day_no = $j_day_no % 12053;
        $jy = 979 + 33*$j_np + 4*intval($j_day_no/1461);
        $j_day_no %= 1461;
        if ($j_day_no >= 366) {
            $jy += intval(($j_day_no-1)/365);
            $j_day_no = ($j_day_no-1) % 365;
        }
        for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) {
            $j_day_no -= $j_days_in_month[$i];
        }
        $jm = $i+1;
        $jd = $j_day_no+1;

        return ['jy' => $jy, 'jm' => $jm, 'jd' => $jd];
    }

    private function scheduleAssessmentReminderSMS($template, $token2 = null, $token3 = null, $days) {
        ScheduledSms::create([
            'user_id' => auth()->id(),
            'phone_number' => auth()->phone_number,
            'template' => $template,
            'token' => auth()->first_name,
            'token2' => $token2,
            'token3' => $token2,
            'send_at' => now()->addDays($days),
        ]);
    }
}
