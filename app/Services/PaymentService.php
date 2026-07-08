<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Exception;

class PaymentService
{
    /**
     * Get user payments
     */
    public function getOwnPayments(User $user, int $page = 1, int $limit = 10)
    {
        $limit = min($limit, 100);

        return Payment::where('user_id', $user->id)
            ->latest('id')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Create Zarinpal payment request
     */
    public function requestPayment(User $user, int $amount)
    {
        $description = 'پرداخت جهت انجام تست کلیه';
        $callback = env('ZARINPAL_CALLBACK_URL');

        if(env('APP_DEBUG')) {
            $API_URL = env('ZARINPAL_API_URL');
            $MERCHANT_ID = env('ZARINPAL_MERCHANT_ID');
        } else {
            $API_URL = env('ZARINPAL_TEST_API_URL');
            $MERCHANT_ID = env('ZARINPAL_TEST_MERCHANT_ID');
        }
        
        $response = Http::post(
            $API_URL . 'request.json',
            [
                'merchant_id' => $MERCHANT_ID,
                'amount' => $amount,
                'callback_url' => $callback,
                'description' => $description,
                'metadata' => [
                    'mobile' => $user->phone_number,
                ],
            ]
        );

        if (!$response->successful()) {
            throw new Exception('Unable to connect to Zarinpal: ' . $response);
        }

        $data = $response->json('data');

        Payment::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'authority' => $data['authority'],
            'status' => 'pending',
            'description' => $description,
        ]);

        return [
            'message' => 'payment created successfully',
            'url' => 'https://payment.zarinpal.com/pg/StartPay/' . $data['authority'],
            'authority' => $data['authority'],
        ];
    }

    /**
     * Verify payment
     */
    public function verifyPayment(string $authority)
    {
        $payment = Payment::where('authority', $authority)
            ->with('user')
            ->first();

        if (!$payment) {
            throw new Exception('Payment not found.');
        }

        if ($payment->status !== 'pending') {
            throw new Exception('Payment already processed.');
        }

        if(env('APP_DEBUG')) {
            $API_URL = env('ZARINPAL_API_URL');
            $MERCHANT_ID = env('ZARINPAL_MERCHANT_ID');
        } else {
            $API_URL = env('ZARINPAL_TEST_API_URL');
            $MERCHANT_ID = env('ZARINPAL_TEST_MERCHANT_ID');
        }

        $response = Http::post(
            $API_URL . 'verify.json',
            [
                'merchant_id' => $MERCHANT_ID,
                'amount' => $payment->amount,
                'authority' => $authority,
            ]
        );

        if (!$response->successful()) {
            throw new Exception('Verification request failed: ' . $response);
        }

        $data = $response->json('data');

        if (in_array($data['code'], [100, 101])) {

            $payment->update([
                'status' => 'success',
                'ref_id' => $data['ref_id'],
            ]);

            return [
                'message' => 'Payment verified successfully',
                'status' => 'success',
                'ref_id' => $data['ref_id'],
            ];
        }

        $payment->update([
            'status' => 'failed',
        ]);

        return [
            'message' => 'Payment failed',
            'status' => 'failed',
            'error_code' => $data['code'],
        ];
    }

    /**
     * Find latest unused successful payment
     */
    public function lastPayment(int $userId, string $type)
    {
        return Payment::where('user_id', $userId)
            ->where('status', 'success')
            ->where($type, false)
            ->latest('created_at')
            ->first();
    }

    /**
     * Mark payment as used
     */
    public function updatePaymentUsedStatus(int $id, string $type)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            throw ValidationException::withMessages([
                'payment' => ['Invalid payment id.'],
            ]);
        }

        if ($type === 'lab-test') {
            $payment->is_used_lab_test = true;
        }

        if ($type === 'insurance') {
            $payment->is_used_insurance = true;
        }

        $payment->save();

        return $payment;
    }
}