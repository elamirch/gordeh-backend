<?php

namespace App\Services\Support;

use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Presentation is intentionally locale-agnostic (ISO dates, raw numbers) — Persian
 * digit/label formatting stays in the frontend, matching MonitoringService's convention
 * for admin reporting elsewhere in this codebase.
 */
class ReportService
{
    private const DAILY_WINDOW_DAYS = 14;

    public function summary(): array
    {
        $tickets = Ticket::all();

        return [
            'kpis' => $this->kpis($tickets),
            'daily' => $this->dailySeries(self::DAILY_WINDOW_DAYS),
            'topics' => $this->topicDistribution($tickets),
        ];
    }

    private function kpis(Collection $tickets): array
    {
        $total = $tickets->count();
        $open = $tickets->where('status', '!=', 'closed')->count();
        $resolved = $tickets->where('status', 'closed')->count();

        return [
            'totalTickets' => $total,
            'openTickets' => $open,
            'resolvedTickets' => $resolved,
            'averageResponseTime' => $this->averageResponseMinutes($tickets),
            'averageResolutionTime' => $this->averageResolutionMinutes($tickets),
            'slaCompliance' => $this->slaCompliance($tickets),
        ];
    }

    /** Minutes from ticket creation to the first agent reply, averaged across all tickets that got one. */
    private function averageResponseMinutes(Collection $tickets): ?int
    {
        $diffs = $tickets
            ->map(function (Ticket $t) {
                $firstReply = $t->messages()->where('author', 'agent')->oldest()->first();

                return $firstReply ? $t->created_at->diffInMinutes($firstReply->created_at) : null;
            })
            ->filter(fn ($v) => $v !== null);

        return $diffs->isEmpty() ? null : (int) round($diffs->avg());
    }

    /** Minutes from creation to close, averaged across closed tickets (updated_at is used as the close timestamp — there's no dedicated status-history table, same proxy MonitoringService already uses for ConsultationBooking). */
    private function averageResolutionMinutes(Collection $tickets): ?int
    {
        $diffs = $tickets->where('status', 'closed')
            ->map(fn (Ticket $t) => $t->created_at->diffInMinutes($t->updated_at));

        return $diffs->isEmpty() ? null : (int) round($diffs->avg());
    }

    private function slaCompliance(Collection $tickets): ?float
    {
        $withSla = $tickets->filter(fn (Ticket $t) => $t->sla_deadline !== null);
        if ($withSla->isEmpty()) {
            return null;
        }

        $met = $withSla->filter(function (Ticket $t) {
            $comparedAgainst = $t->status === 'closed' ? $t->updated_at : now();

            return $comparedAgainst->lte($t->sla_deadline);
        });

        return round($met->count() / $withSla->count(), 2);
    }

    private function dailySeries(int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        $createdByDate = Ticket::where('created_at', '>=', $since)->get()
            ->groupBy(fn (Ticket $t) => $t->created_at->toDateString());
        $resolvedByDate = Ticket::where('status', 'closed')->where('updated_at', '>=', $since)->get()
            ->groupBy(fn (Ticket $t) => $t->updated_at->toDateString());

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $series[] = [
                'date' => $date,
                'created' => $createdByDate->get($date, collect())->count(),
                'resolved' => $resolvedByDate->get($date, collect())->count(),
            ];
        }

        return $series;
    }

    private function topicDistribution(Collection $tickets): array
    {
        $total = $tickets->count();

        return $tickets->groupBy('category')
            ->map(fn (Collection $group, string $category) => [
                'category' => $category,
                'count' => $group->count(),
                'percentage' => $total > 0 ? round(($group->count() / $total) * 100, 1) : 0,
            ])
            ->sortByDesc('count')
            ->values()->all();
    }
}
