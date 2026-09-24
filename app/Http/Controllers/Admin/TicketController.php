<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $service) {}

    // GET /admin/tickets
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::in(TicketService::STATUSES)],
            'priority' => ['nullable', Rule::in(TicketService::PRIORITIES)],
            'agent' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $tickets = $this->service->listForAdmin($filters);
        $tickets->through(fn (Ticket $t) => $this->service->presentForAdmin($t));

        return response()->json($tickets);
    }

    // GET /admin/tickets/queue-summary
    public function queueSummary(): JsonResponse
    {
        return response()->json($this->service->queueSummary());
    }

    // GET /admin/tickets/{ticket}
    public function show(Ticket $ticket): JsonResponse
    {
        return response()->json($this->service->presentDetailForAdmin($ticket));
    }

    // PATCH /admin/tickets/{ticket}
    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(TicketService::STATUSES)],
            'agentId' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('role', ['admin', 'support_agent'])),
            ],
            'priority' => ['sometimes', Rule::in(TicketService::PRIORITIES)],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $ticket = $this->service->updateFromAdmin($ticket, $data);

        return response()->json($this->service->presentDetailForAdmin($ticket));
    }

    // POST /admin/tickets/{ticket}/messages
    public function storeMessage(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:5000'],
            'type' => ['required', Rule::in(['reply', 'note'])],
            'attachmentUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        /** @var User $agent */
        $agent = auth()->user();

        $message = $this->service->addAgentMessage(
            $ticket,
            $agent,
            $data['text'],
            $data['type'] === 'note',
            $data['attachmentUrl'] ?? null
        );

        return response()->json($this->service->presentMessageForAdmin($message), 201);
    }
}
