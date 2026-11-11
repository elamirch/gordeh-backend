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
            // What the payment buys: the original one-off credit, a consultation, or a wallet top-up.
            'purpose' => ['nullable', 'in:analysis,consultation,wallet'],
        ]);

        return response()->json(
            $service->requestPayment(
                auth()->user(),
                $validated['amount'],
                $validated['callback_url'] ?? null,
                $validated['purpose'] ?? 'analysis'
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

    public function verifyBazaar(Request $request, PaymentService $service)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:100'],
            'purchase_token' => ['required', 'string', 'max:200'],
        ]);

        return response()->json(
            $service->verifyBazaarPurchase(auth()->user(), $validated['product_id'], $validated['purchase_token'])
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
