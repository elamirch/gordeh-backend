<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function request(Request $request, PaymentService $service)
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1000'],
            'callback_url' => ['nullable', 'url'],
        ]);

        return response()->json(
            $service->requestPayment(
                auth()->user(),
                $validated['amount'],
                $validated['callback_url'] ?? null
            )
        );
    }

    public function verify(Request $request, PaymentService $service)
    {
        $validated = $request->validate([
            'authority' => 'required|string',
        ]);

        return response()->json(
            $service->verifyPayment($validated['authority'])
        );
    }

    public function getOwnPayments(Request $request, PaymentService $service)
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(
            $service->getOwnPayments(
                auth()->user(),
                $validated['page'],
                $validated['limit']
            )
        );
    }

    public function redeemDiscount(Request $request, PaymentService $service)
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $payment = $service->redeemDiscountCode(auth()->user(), $validated['code']);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'کد تخفیف نامعتبر است'], 422);
        }

        return response()->json([
            'message' => 'Discount code redeemed successfully',
            'payment' => $payment,
        ]);
    }
}
