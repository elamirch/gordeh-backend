<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientWalletException;
use App\Models\LabTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use App\Services\Jalali;
use App\Services\LabTestScoring;
use App\Services\PaymentService;
use App\Services\ReminderScheduler;

class LabTestController extends Controller
{
    // Create a new test (equivalent to create in Nest service)
    public function store(Request $request): JsonResponse
    {
        $paymentService = new PaymentService;
        // Fail fast, before validating a form the user cannot submit anyway: a legacy credit or
        // enough in the wallet. The charge itself happens inside the transaction below.
        if (! $paymentService->canAfford(auth()->user(), 'lab_test')) {
            throw new InsufficientWalletException(PaymentService::price('lab_test'), $paymentService->wallet()->balance(auth()->user()), 'lab_test');
        }

        [$minCr, $maxCr] = LabTestScoring::URINE_CREATININE_G_PER_L;
        [$minAlb, $maxAlb] = LabTestScoring::URINE_ALBUMIN_MG_PER_L;
        $data = $request->validate([
            'age' => 'required|integer',
            'gender' => 'required|in:m,f',
            // g/L and mg/L — see LabTestScoring. Out-of-range means a unit wasn't converted.
            'urine_creatinine' => "required|numeric|between:$minCr,$maxCr",
            'urine_albumin' => "required|numeric|between:$minAlb,$maxAlb",
            'creatinine' => 'required|numeric',
            'albumin' => 'required|numeric',
            'calcium' => 'nullable|numeric',
            'phosphorous' => 'nullable|numeric',
            'bCarbonate' => 'nullable|numeric',
        ], [
            'urine_creatinine.between' => 'مقدار کراتینین ادرار با واحد آن همخوانی ندارد؛ عدد و واحد را بررسی کنید.',
            'urine_albumin.between' => 'مقدار آلبومین ادرار با واحد آن همخوانی ندارد؛ عدد و واحد را بررسی کنید.',
        ]);

        try {
            $user = auth()->user();
            $gfrResult = LabTestScoring::score((object) $data);

            // Saving the test, its reminders and consuming the payment succeed or fail together: a
            // reminder failure used to leave a saved test with an unused payment (re-submittable).
            $test = DB::transaction(function () use ($data, $gfrResult, $user, $paymentService) {
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

                //Schedule assessment reminders
                (new ReminderScheduler)->scheduleAssessmentReminders($user, $test);

                // A legacy credit first, else the wallet; rolls back with the test if anything fails.
                $paymentService->charge($user, 'lab_test');

                return $test;
            });
            
            return response()->json([
                'message' => 'test saved successfully.',
                'data' => $test,
            ], 201);
        } catch (InsufficientWalletException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    /**
     * Deletes a test (its owner, or an admin), together with the lab-sheet files attached to it
     * and the reminders that were scheduled from it. The user's reminder plan then follows their
     * latest remaining test, or stops when there is none. Nothing paid is refunded: the analysis
     * was delivered.
     */
    public function destroy($id): JsonResponse
    {
        $test = LabTest::find($id);
        if (! $test) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (auth()->id() !== $test->user_id && auth()->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }

        $user = $test->user;

        DB::transaction(function () use ($test, $user) {
            foreach ($test->files as $file) {
                Storage::disk($file->disk)->delete($file->storage_key);
                $file->delete();
            }

            $test->delete();

            if ($user) {
                $scheduler = new ReminderScheduler;
                $scheduler->cancelAssessmentReminders($user);

                $latest = LabTest::where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->first();
                if ($latest) {
                    $scheduler->scheduleAssessmentReminders($user, $latest);
                }
            }
        });

        return response()->json(null, 204);
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
            $j = Jalali::fromGregorian(
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
            $j = Jalali::fromGregorian(
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
}
