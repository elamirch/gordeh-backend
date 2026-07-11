<?php

namespace App\Http\Controllers;

use App\Models\Insurance;
use App\Models\User;
use App\Models\ScheduledSMS;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Services\SendSMS;
use App\Services\PaymentService;

class InsuranceController extends Controller
{
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
        $lastPayment = $paymentService->lastPayment(auth()->id(), 'is_used_insurance');
        
        if(!$lastPayment) {
            return response()->json(['message' => 'Payment needed to proceed'], 403);
        }
        
        $data = $request->validate([
            'national_code'  => ['required','string','size:10'],
            'insurance_type' => ['required','string'],
            'first_name'     => ['nullable','string'],
            'last_name'      => ['nullable','string'],
            'identification_code' => 'nullable|string|size:10',
            'status' => ['nullable', Rule::in(['created','in_progress', 'completed'])],
        ]);

        try {
            $data['user_id'] = auth()->id();
            $data['status'] = 'created';

            $insurance = Insurance::create($data);

            //Creating reminders
            $this->scheduleInsuranceReminderSMS('cron-insurance-reminder-7d', 7, $insurance->id);
            $this->scheduleInsuranceReminderSMS('cron-insurance-reminder-14d', 14, $insurance->id);
            $this->scheduleInsuranceReminderSMS('cron-insurance-reminder-25d', 25, $insurance->id);

            $paymentService->updatePaymentUsedStatus($lastPayment['id'], 'insurance');

            return response()->json($insurance, 201);
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
     * Update insurance
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['created','in_progress', 'completed'])],
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'insurance_type' => 'nullable|string',
            'national_code' => 'nullable|string|size',
            'identification_code' => 'nullable|string|size:10',
        ]);

        $insurance = Insurance::findOrFail($id);
        if (auth()->id() !== $insurance->user_id && auth()->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }

        try {
            if (isset($data['status'])) $insurance->status = $data['status'];
            // if (isset($data['first_name'])) $insurance->first_name = $data['first_name'];
            // if (isset($data['last_name'])) $insurance->last_name = $data['last_name'];
            // if (isset($data['insurance_type'])) $insurance->insurance_type = $data['insurance_type'];
            // if (isset($data['national_code'])) $insurance->national_code = $data['national_code'];
            // if (isset($data['identification_code'])) $insurance->identification_code = $data['identification_code'];

            $insurance->save();

            if (isset($data['status']) && isset($data['identification_code'])) {
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
     * Delete insurance
     */
    public function destroy($id)
    {
        $insurance = Insurance::find($id);
        if (auth()->id() !== $insurance->user_id && auth()->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }
        if (! $insurance) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $insurance->delete();

        return response()->json(null, 204);
    }

    private function scheduleInsuranceReminderSMS($template, $days, $insurance_id) {
        $user = auth()->user();
        ScheduledSMS::create([
            'user_id' => $user->id,
            'phone_number' => $user->phone_number,
            'template' => $template,
            'token' => $user->first_name,
            'send_at' => now()->addDays($days),
            'insurance_id' => $insurance_id
        ]);
    }
}
