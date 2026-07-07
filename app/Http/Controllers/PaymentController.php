<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function request(Request $request, PaymentService $service)
    {
        $request->validate([
            'amount' => ['required', 'integer', 'min:1000'],
        ]);

        return response()->json(
            $service->requestPayment(
                auth()->user(),
                $request->integer('amount')
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
}
