<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShiftAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    private const AGENT_ROLES = ['admin', 'support_agent'];

    private const WINDOWS = ['morning', 'noon', 'evening'];

    // GET /admin/agents
    public function index(): JsonResponse
    {
        $agents = User::whereIn('role', self::AGENT_ROLES)->orderBy('first_name')->get();

        $activeCounts = Ticket::whereIn('agent_id', $agents->pluck('id'))
            ->where('status', '!=', 'closed')
            ->get()
            ->groupBy('agent_id');

        return response()->json($agents->map(fn (User $a) => [
            'id' => $a->id,
            'name' => $this->fullName($a),
            'role' => $a->role,
            'state' => $a->support_state ?? 'offline',
            'activeTicketCount' => $activeCounts->get($a->id, collect())->count(),
        ])->values());
    }

    // GET /admin/agents/shifts
    public function shifts(): JsonResponse
    {
        return response()->json($this->shiftGrid());
    }

    // PATCH /admin/agents/shifts
    public function updateShifts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agentId' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('role', self::AGENT_ROLES)),
            ],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'window' => ['required', Rule::in(self::WINDOWS)],
            'assigned' => ['required', 'boolean'],
        ]);

        if ($data['assigned']) {
            ShiftAssignment::firstOrCreate([
                'agent_id' => $data['agentId'],
                'weekday' => $data['weekday'],
                'window' => $data['window'],
            ]);
        } else {
            ShiftAssignment::where([
                'agent_id' => $data['agentId'],
                'weekday' => $data['weekday'],
                'window' => $data['window'],
            ])->delete();
        }

        return response()->json($this->shiftGrid());
    }

    /** Full 7-weekday x 3-window grid, including empty cells, so the client can render a complete schedule without guessing which slots exist. */
    private function shiftGrid(): array
    {
        $assignments = ShiftAssignment::with('agent')->get()
            ->groupBy(fn (ShiftAssignment $a) => $a->weekday.'-'.$a->window);

        $cells = [];
        foreach (range(0, 6) as $weekday) {
            foreach (self::WINDOWS as $window) {
                $group = $assignments->get("{$weekday}-{$window}", collect());
                $cells[] = [
                    'weekday' => $weekday,
                    'window' => $window,
                    'agents' => $group->map(fn (ShiftAssignment $a) => [
                        'id' => $a->agent_id,
                        'name' => $a->agent ? $this->fullName($a->agent) : null,
                    ])->values()->all(),
                ];
            }
        }

        return $cells;
    }

    private function fullName(User $user): ?string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }
}
