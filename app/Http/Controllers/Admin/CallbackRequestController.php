<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallbackRequest;
use App\Services\Support\CallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CallbackRequestController extends Controller
{
    public function __construct(private readonly CallbackService $service) {}

    // GET /admin/callback-requests
    public function index(): JsonResponse
    {
        $callbacks = $this->service->listForAdmin();
        $callbacks->through(fn (CallbackRequest $c) => $this->service->presentForAdmin($c));

        return response()->json($callbacks);
    }

    // PATCH /admin/callback-requests/{callbackRequest}
    public function update(Request $request, CallbackRequest $callbackRequest): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in(CallbackService::STATES)],
        ]);

        $callback = $this->service->updateState($callbackRequest, $data['state']);

        return response()->json($this->service->presentForAdmin($callback));
    }
}
