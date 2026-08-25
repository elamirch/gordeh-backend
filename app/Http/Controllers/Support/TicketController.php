<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Support\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $service) {}

    // GET /support/tickets
    public function index(): JsonResponse
    {
        $tickets = $this->service->listForPatient(auth()->user());

        return response()->json($tickets->map(fn (Ticket $t) => $this->service->presentForPatient($t))->values());
    }

    // POST /support/tickets
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(TicketService::CATEGORIES)],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['required', 'string', 'min:1', 'max:5000'],
            'fileUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $ticket = $this->service->createForPatient(auth()->user(), $data);

        return response()->json($this->service->presentForPatient($ticket), 201);
    }

    // GET /support/tickets/{ticket}
    public function show(Ticket $ticket): JsonResponse
    {
        $this->authorizeOwner($ticket);

        return response()->json($this->service->presentForPatient($ticket));
    }

    // GET /support/tickets/{ticket}/messages
    public function messages(Ticket $ticket): JsonResponse
    {
        $this->authorizeOwner($ticket);

        $messages = $this->service->messagesForPatient($ticket);

        return response()->json($messages->map(fn (TicketMessage $m) => $this->service->presentMessageForPatient($m))->values());
    }

    // POST /support/tickets/{ticket}/messages
    public function storeMessage(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeOwner($ticket);

        if ($ticket->status === 'closed') {
            return response()->json(['message' => 'This ticket is closed'], 403);
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:5000'],
            'fileUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $message = $this->service->addPatientMessage(
            $ticket,
            auth()->user(),
            $data['text'],
            $data['fileUrl'] ?? null
        );

        return response()->json($this->service->presentMessageForPatient($message), 201);
    }

    private function authorizeOwner(Ticket $ticket): void
    {
        if (auth()->id() !== $ticket->user_id) {
            abort(403, 'Unauthorized');
        }
    }
}
