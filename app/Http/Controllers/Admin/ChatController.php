<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Services\Support\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $service) {}

    // GET /admin/chat/sessions
    public function sessions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ChatService::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sessions = $this->service->listForAdmin($filters);
        $sessions->through(fn (ChatSession $s) => $this->service->presentSessionForAdmin($s));

        return response()->json($sessions);
    }

    // PATCH /admin/chat/sessions/{chatSession}
    public function updateSessionStatus(Request $request, ChatSession $chatSession): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ChatService::STATUSES)],
        ]);

        $session = $this->service->updateStatus($chatSession, $data['status']);

        return response()->json($this->service->presentSessionForAdmin($session));
    }

    // GET /admin/chat/messages?sessionId=
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sessionId' => ['required', 'integer'],
        ]);

        $session = $this->service->findSession($data['sessionId']);
        if (! $session) {
            return response()->json(['message' => 'Chat session not found'], 404);
        }

        $messages = $this->service->history($session);

        return response()->json($messages->map(fn ($m) => $this->service->present($m))->values());
    }

    // POST /admin/chat/messages
    public function storeMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sessionId' => ['required', 'integer'],
            'text' => ['required', 'string', 'min:1', 'max:5000'],
            'fileUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $session = $this->service->findSession($data['sessionId']);
        if (! $session) {
            return response()->json(['message' => 'Chat session not found'], 404);
        }

        $message = $this->service->addMessage($session, 'support', $data['text'], $data['fileUrl'] ?? null);

        return response()->json($this->service->present($message), 201);
    }
}
