<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Services\Support\CallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CallbackRequestController extends Controller
{
    public function __construct(private readonly CallbackService $service) {}

    // POST /support/callback-requests
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'slot' => ['required', Rule::in(CallbackService::SLOTS)],
        ]);

        $callback = $this->service->createForPatient(auth()->user(), $data);

        return response()->json($this->service->presentForPatient($callback), 201);
    }
}
