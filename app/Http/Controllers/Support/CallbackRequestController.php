<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Services\Support\CallbackService;
use App\Support\ChatActor;
use App\Support\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CallbackRequestController extends Controller
{
    public function __construct(private readonly CallbackService $service) {}

    // POST /support/callback-requests (members, or guests from the chat widget)
    public function store(Request $request): JsonResponse
    {
        PlainText::sanitizeRequest($request, ['name']);
        $data = $request->validate([
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'slot' => ['required', Rule::in(CallbackService::SLOTS)],
            'name' => ['nullable', 'string', 'max:60'],
            // Lets the widget label a member's request; guests are always 'chat_widget'.
            'source' => ['nullable', Rule::in(['support_page', 'chat_widget'])],
        ]);

        $member = ChatActor::from($request)?->user;

        $callback = $member
            ? $this->service->createForPatient($member, $data)
            : $this->service->createForGuest($data);

        return response()->json($this->service->presentForPatient($callback), 201);
    }
}
