<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientWalletException;
use App\Models\Insurance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Services\SendSMS;
use App\Services\Support\Concerns\GuardsSmsSending;
use App\Services\PaymentService;
use App\Services\ReminderScheduler;

class InsuranceController extends Controller
{
    use GuardsSmsSending;

    /**
     * List all insurance entries (paginated)
     */
    public function index(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            abort(403);
        }

        $perPage = min(max((int) $request->query('limit', 10), 1), 100);
        $insurances = Insurance::orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($insurances);
    }

    /**
     * List authenticated user's insurance entries (paginated)
     */
    public function indexMe(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = $request->has('limit') ? (int) $request->query('limit') : 10;
        if ($limit <= 0 || $limit > 100) $limit = 10;

        $query = Insurance::where('user_id', $user->id)->orderBy('created_at', 'desc');

        $count = $query->count();
        $data = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return response()->json([
            'data' => $data,
            'count' => $count,
        ]);
    }

    /**
     * Create new insurance
     */
    public function store(Request $request)
    {
        $paymentService = new PaymentService;
        if (! $paymentService->canAfford(auth()->user(), 'insurance')) {
            throw new InsufficientWalletException(PaymentService::price('insurance'), $paymentService->wallet()->balance(auth()->user()), 'insurance');
        }

        $data = $request->validate([
            'national_code'  => ['required','string','size:10'],
            'insurance_type' => ['required','string'],
            'first_name'     => ['nullable','string'],
            'last_name'      => ['nullable','string'],
            'identification_code' => 'nullable|string',
            'status' => ['nullable', Rule::in(['created','in_progress', 'completed'])],
        ]);

        try {
            $data['user_id'] = auth()->id();
            $data['status'] = 'created';

            // Same all-or-nothing as lab tests: no saved request with an unused payment if scheduling fails.
            $insurance = DB::transaction(function () use ($data, $paymentService) {
                $insurance = Insurance::create($data);

                //Creating reminders
                (new ReminderScheduler)->scheduleInsuranceReminders(auth()->user(), $insurance);

                $paymentService->charge(auth()->user(), 'insurance');

                return $insurance;
            });

            $this->notifyAdminSafely('support-admin-new-insurance', fn ($adminPhone) => (new SendSMS)->supportAdminNewInsurance(
                $adminPhone,
                $insurance->id,
                $insurance->first_name ?? auth()->user()->first_name
            ));

            return response()->json($insurance, 201);
        } catch (InsufficientWalletException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    /**
     * Show single insurance
     */
    public function show($id)
    {
        $insurance = Insurance::with('creator')->findOrFail($id);
        if (auth()->id() !== $insurance->user_id && auth()->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }
        if (! $insurance) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json($insurance);
    }

    /**
     * Update insurance.
     *
     * The owner can correct their own request (name, insurance type, national code) for as long
     * as nobody has started on it (status «created»). The status and the identification code are
     * the admin's: they drive the SMS the patient receives.
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['created','in_progress', 'completed'])],
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'insurance_type' => 'nullable|string|max:50',
            'national_code' => 'nullable|string|size:10',
            'identification_code' => 'nullable|string',
        ]);

        $insurance = Insurance::findOrFail($id);
        $isAdmin = auth()->user()->role === 'admin';
        if (auth()->id() !== $insurance->user_id && ! $isAdmin) {
            abort(403, 'Unauthorized');
        }

        if (! $isAdmin) {
            if (isset($data['status']) || isset($data['identification_code'])) {
                abort(403, 'Unauthorized');
            }
            if ($insurance->status !== 'created') {
                return response()->json(['message' => 'این درخواست در حال بررسی است و دیگر قابل ویرایش نیست.'], 409);
            }
        }

        try {
            $fillable = $isAdmin
                ? ['status', 'first_name', 'last_name', 'insurance_type', 'national_code', 'identification_code']
                : ['first_name', 'last_name', 'insurance_type', 'national_code'];
            foreach ($fillable as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    $insurance->{$field} = $data[$field];
                }
            }

            $insurance->save();

            if ($isAdmin && isset($data['status']) && isset($data['identification_code'])) {
                if($data['status'] == 'completed') {
                    $user = User::findOrFail($insurance->user_id);

                    $userFirstName = $user['first_name'] ?? "کاربر";
                    $sendSMS = new SendSMS;
                    $SMSResponse = $sendSMS->insurance_code(
                            $user['phone_number'],
                            $data['identification_code'],
                            $userFirstName);

                    return response()->json([
                        'insurance' => $insurance,
                        'sms-response' => $SMSResponse
                    ]);
                }
            }

            return response()->json([
                'insurance' => $insurance,
            ]);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    /**
     * Delete insurance (an admin, or its owner while nobody has started on it). Its pending
     * reminders go with it.
     */
    public function destroy($id)
    {
        $insurance = Insurance::find($id);
        if (! $insurance) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (auth()->id() !== $insurance->user_id && auth()->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }
        if (auth()->user()->role !== 'admin' && $insurance->status !== 'created') {
            return response()->json(['message' => 'این درخواست در حال بررسی است و دیگر قابل حذف نیست.'], 409);
        }

        DB::transaction(function () use ($insurance) {
            // Only this request's reminders: «insurance_id» ties them to it.
            \App\Models\ScheduledSMS::where('insurance_id', $insurance->id)->where('status', 'pending')->delete();
            $insurance->delete();
        });

        return response()->json(null, 204);
    }
}
