<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * Payments started from the app. The app only gets a link to the payment page on the website
 * (init) and, afterwards, the verified result (status); the page and the gateway callback use
 * the public token endpoints.
 */
class MobilePaymentController extends Controller
{
    public function init(Request $request, PaymentService $service)
    {
        $validated = $request->validate([
            'purpose' => ['required', 'in:wallet,consultation,analysis'],
            // Only read for a wallet top-up; a service is always charged at its server-side price.
            'amount' => ['required_if:purpose,wallet', 'nullable', 'integer', 'min:1000'],
        ]);

        return response()->json(
            $service->initMobilePayment($request->user(), $validated['purpose'], $validated['amount'] ?? null)
        );
    }

    public function show(string $token, PaymentService $service)
    {
        $summary = $service->mobilePaymentSummary($token);

        return $summary
            ? response()->json($summary)
            : response()->json(['state' => 'invalid'], 404);
    }

    public function continue(string $token, PaymentService $service)
    {
        $result = $service->continueMobilePayment($token);

        return $result
            ? response()->json($result)
            : response()->json(['state' => 'invalid'], 404);
    }

    public function callback(Request $request, PaymentService $service)
    {
        $validated = $request->validate(['authority' => ['required', 'string', 'max:250']]);

        $result = $service->settleMobilePayment($validated['authority']);

        return $result
            ? response()->json($result)
            : response()->json(['status' => 'invalid'], 404);
    }

    public function status(Request $request, int $payment, PaymentService $service)
    {
        $status = $service->paymentStatus($request->user(), $payment);

        return $status ? response()->json($status) : response()->json(['message' => 'Not found'], 404);
    }
}
