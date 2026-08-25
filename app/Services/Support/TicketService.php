<?php

namespace App\Services\Support;

use App\Events\TicketMessageSent;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SendSMS;
use App\Services\Support\Concerns\GuardsSmsSending;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TicketService
{
    use GuardsSmsSending;

    public const CATEGORIES = ['account', 'payment', 'lab-test', 'app', 'consultation', 'other'];

    public const STATUSES = ['open', 'in_progress', 'waiting_patient', 'closed'];

    public const PRIORITIES = ['urgent', 'normal'];

    public const CHANNELS = ['chat', 'email', 'phone'];

    /**
     * Hours-to-first-response by priority. No existing SLA config covers support tickets
     * anywhere in this codebase (MonitoringSetting is scoped to nutrition consultations) —
     * 'normal' matches the response-time commitment already shown to patients on the
     * ticket-created screen ("معمولاً تا ۲ ساعت پاسخ می‌دهیم"); 'urgent' is a deliberately
     * tighter policy choice, easy to retune here later.
     */
    private const SLA_HOURS = [
        'urgent' => 0.5,
        'normal' => 2,
    ];

    // --- Patient-facing --------------------------------------------------

    public function createForPatient(User $patient, array $data): Ticket
    {
        $ticket = Ticket::create([
            'user_id' => $patient->id,
            'subject' => $data['subject'],
            'category' => $data['category'],
            'channel' => 'chat',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $ticket->code = $this->generateCode($ticket->id);
        $ticket->sla_deadline = $this->computeSlaDeadline($ticket->created_at, 'normal');
        $ticket->save();

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author' => 'user',
            'author_id' => $patient->id,
            'text' => $data['description'],
            'file_url' => $data['fileUrl'] ?? null,
        ]);

        Log::info('support.ticket_created', ['ticket_id' => $ticket->id, 'user_id' => $patient->id]);

        $this->sendSmsSafely(fn () => (new SendSMS)->supportNewTicket(
            $patient->phone_number,
            $patient->first_name ?? 'کاربر',
            $ticket->id
        ));

        return $ticket;
    }

    public function addPatientMessage(Ticket $ticket, User $patient, string $text, ?string $fileUrl): TicketMessage
    {
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author' => 'user',
            'author_id' => $patient->id,
            'text' => $text,
            'file_url' => $fileUrl,
        ]);

        if ($ticket->status === 'waiting_patient') {
            $ticket->status = 'in_progress';
            $ticket->save();
        } else {
            $ticket->touch();
        }

        Log::info('support.ticket_message_created', ['ticket_id' => $ticket->id, 'user_id' => $patient->id]);

        TicketMessageSent::dispatch($message);

        return $message;
    }

    public function listForPatient(User $patient): Collection
    {
        return Ticket::where('user_id', $patient->id)->orderByDesc('updated_at')->get();
    }

    public function messagesForPatient(Ticket $ticket): Collection
    {
        return $ticket->messages()->where('author', '!=', 'note')->get();
    }

    public function mapStatusForPatient(string $internalStatus): string
    {
        return match ($internalStatus) {
            'waiting_patient' => 'answered',
            'closed' => 'closed',
            default => 'open', // 'open', 'in_progress'
        };
    }

    public function presentForPatient(Ticket $ticket): array
    {
        return [
            'id' => (string) $ticket->id,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'status' => $this->mapStatusForPatient($ticket->status),
            'createdAt' => $ticket->created_at->toIso8601String(),
            'updatedAt' => $ticket->updated_at->toIso8601String(),
        ];
    }

    public function presentMessageForPatient(TicketMessage $message): array
    {
        return [
            'id' => (string) $message->id,
            'sender' => $message->author === 'agent' ? 'support' : 'user',
            'text' => $message->text,
            'fileUrl' => $message->file_url,
            'createdAt' => $message->created_at->toIso8601String(),
        ];
    }

    // --- Admin-facing ------------------------------------------------------

    public function addAgentMessage(Ticket $ticket, User $agent, string $text, bool $isNote, ?string $fileUrl): TicketMessage
    {
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author' => $isNote ? 'note' : 'agent',
            'author_id' => $agent->id,
            'text' => $text,
            'file_url' => $fileUrl,
        ]);

        if ($isNote) {
            $ticket->touch();

            TicketMessageSent::dispatch($message);

            return $message;
        }

        $ticket->status = 'waiting_patient';
        $ticket->save();

        Log::info('support.ticket_agent_replied', ['ticket_id' => $ticket->id, 'agent_id' => $agent->id]);

        TicketMessageSent::dispatch($message);

        $patient = $ticket->user;
        if ($patient) {
            $this->sendSmsSafely(fn () => (new SendSMS)->supportReply(
                $patient->phone_number,
                $patient->first_name ?? 'کاربر',
                $ticket->id
            ));
        }

        return $message;
    }

    /**
     * $data may contain any of: status, agentId, priority, tags. Anything else (e.g. a
     * client-supplied patientId/createdAt) is simply never read here — callers only ever
     * pass through the whitelisted, validated fields.
     */
    public function updateFromAdmin(Ticket $ticket, array $data): Ticket
    {
        $previousStatus = $ticket->status;
        $previousAgentId = $ticket->agent_id;

        if (array_key_exists('priority', $data) && $data['priority'] !== $ticket->priority) {
            $ticket->priority = $data['priority'];
            $ticket->sla_deadline = $this->computeSlaDeadline($ticket->created_at, $data['priority']);
        }

        if (array_key_exists('agentId', $data)) {
            $ticket->agent_id = $data['agentId'];
        }

        if (array_key_exists('tags', $data)) {
            $ticket->tags = $data['tags'];
        }

        if (array_key_exists('status', $data)) {
            $ticket->status = $data['status'];
        } elseif ($ticket->agent_id && $ticket->agent_id !== $previousAgentId && $previousStatus === 'open') {
            // Assigning an agent to a fresh ticket implicitly starts work on it, unless the
            // caller explicitly asked for a different status in the same request.
            $ticket->status = 'in_progress';
        }

        $ticket->save();

        if ($ticket->status !== $previousStatus) {
            AuditLog::record('ticket_status_change', $ticket);

            if ($ticket->status === 'closed') {
                $patient = $ticket->user;
                if ($patient) {
                    $this->sendSmsSafely(fn () => (new SendSMS)->supportTicketClosed(
                        $patient->phone_number,
                        $patient->first_name ?? 'کاربر',
                        $ticket->id
                    ));
                }
            }
        }

        if ($ticket->agent_id !== $previousAgentId) {
            AuditLog::record('ticket_assignment', $ticket);
        }

        return $ticket->fresh();
    }

    public function listForAdmin(array $filters): LengthAwarePaginator
    {
        $query = Ticket::with(['user', 'agent'])->orderByDesc('updated_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['agent'])) {
            $query->where('agent_id', $filters['agent']);
        }
        if (! empty($filters['q'])) {
            $term = $filters['q'];
            $query->where(function ($sub) use ($term) {
                $sub->where('subject', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhereHas('user', function ($userQuery) use ($term) {
                        $userQuery->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('phone_number', 'like', "%{$term}%");
                    });
            });
        }

        $perPage = min(max((int) ($filters['limit'] ?? 20), 1), 100);

        return $query->paginate($perPage, ['*'], 'page', $filters['page'] ?? 1);
    }

    public function queueSummary(): array
    {
        $base = Ticket::query();

        return [
            'openCount' => (clone $base)->where('status', 'open')->count(),
            'inProgressCount' => (clone $base)->where('status', 'in_progress')->count(),
            'waitingPatientCount' => (clone $base)->where('status', 'waiting_patient')->count(),
            'pastSlaCount' => (clone $base)
                ->whereNotNull('sla_deadline')
                ->where('sla_deadline', '<', now())
                ->where('status', '!=', 'closed')
                ->count(),
            'urgentCount' => (clone $base)->where('priority', 'urgent')->where('status', '!=', 'closed')->count(),
        ];
    }

    public function isSlaBreached(Ticket $ticket): bool
    {
        if ($ticket->status === 'closed' || ! $ticket->sla_deadline) {
            return false;
        }

        return now()->gt($ticket->sla_deadline);
    }

    public function presentForAdmin(Ticket $ticket): array
    {
        $ticket->loadMissing(['user', 'agent']);

        return [
            'id' => $ticket->id,
            'code' => $ticket->code,
            'subject' => $ticket->subject,
            'patient' => $ticket->user ? [
                'id' => $ticket->user->id,
                'name' => $this->fullName($ticket->user),
                'phone' => $ticket->user->phone_number,
            ] : null,
            'category' => $ticket->category,
            'channel' => $ticket->channel,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'agent' => $ticket->agent ? [
                'id' => $ticket->agent->id,
                'name' => $this->fullName($ticket->agent),
            ] : null,
            'slaDeadline' => $ticket->sla_deadline?->toIso8601String(),
            'slaBreached' => $this->isSlaBreached($ticket),
            'tags' => $ticket->tags ?? [],
            'createdAt' => $ticket->created_at->toIso8601String(),
            'updatedAt' => $ticket->updated_at->toIso8601String(),
        ];
    }

    public function presentDetailForAdmin(Ticket $ticket): array
    {
        $ticket->loadMissing('messages.authorUser');

        return array_merge($this->presentForAdmin($ticket), [
            'messages' => $ticket->messages
                ->map(fn (TicketMessage $m) => $this->presentMessageForAdmin($m))
                ->values()->all(),
        ]);
    }

    public function presentMessageForAdmin(TicketMessage $message): array
    {
        $message->loadMissing('authorUser');

        return [
            'id' => $message->id,
            'author' => $message->author,
            'authorId' => $message->author_id,
            'authorName' => $message->authorUser ? $this->fullName($message->authorUser) : null,
            'text' => $message->text,
            'attachmentUrl' => $message->file_url,
            'createdAt' => $message->created_at->toIso8601String(),
        ];
    }

    private function generateCode(int $id): string
    {
        return 'TCK-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private function computeSlaDeadline(Carbon $from, string $priority): Carbon
    {
        $hours = self::SLA_HOURS[$priority] ?? self::SLA_HOURS['normal'];

        return $from->copy()->addMinutes((int) round($hours * 60));
    }

    private function fullName(User $user): ?string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }
}
