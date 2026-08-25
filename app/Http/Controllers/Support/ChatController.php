<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Services\Support\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $service) {}

    // GET /support/chat/messages?sessionId=
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sessionId' => ['required', 'integer'],
        ]);

        $session = $this->service->findSession($data['sessionId']);
        if (! $session) {
            return response()->json(['message' => 'Chat session not found'], 404);
        }
        if ($session->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        $messages = $this->service->history($session);

        return response()->json($messages->map(fn ($m) => $this->service->present($m))->values());
    }

    // POST /support/chat/messages
    public function storeMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sessionId' => ['nullable', 'integer'],
            'text' => ['required', 'string', 'min:1', 'max:5000'],
            'fileUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $patient = auth()->user();

        if (! empty($data['sessionId'])) {
            $session = $this->service->findSession($data['sessionId']);
            if (! $session) {
                return response()->json(['message' => 'Chat session not found'], 404);
            }
            if ($session->user_id !== $patient->id) {
                abort(403, 'Unauthorized');
            }
        } else {
            $session = $this->service->createSession($patient);
        }

        $message = $this->service->addMessage($session, 'user', $data['text'], $data['fileUrl'] ?? null);

        return response()->json($this->service->present($message), 201);
    }
}
