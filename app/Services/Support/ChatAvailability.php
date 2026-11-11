<?php

namespace App\Services\Support;

use App\Models\ChatSession;
use App\Models\User;
use Carbon\CarbonInterface;

/** Working hours, agent presence and the queue wait estimate behind GET /support/chat/config. */
class ChatAvailability
{
    public function withinWorkingHours(?CarbonInterface $at = null): bool
    {
        $now = ($at ?? now())->copy()->setTimezone(config('support.chat.timezone'))->format('H:i');
        $from = $this->hours()['from'];
        $to = $this->hours()['to'];

        // A window like 20:00-02:00 wraps past midnight.
        return $from <= $to
            ? $now >= $from && $now < $to
            : $now >= $from || $now < $to;
    }

    public function onlineAgentCount(): int
    {
        return User::onlineSupportStaff()->count();
    }

    public function isOnline(): bool
    {
        return $this->withinWorkingHours() && $this->onlineAgentCount() > 0;
    }

    /** Minutes until a chat at the given 1-based queue position is picked up. */
    public function estimatedWaitMinutes(int $position): int
    {
        return $this->waitFor($position, $this->onlineAgentCount());
    }

    /**
     * The same estimate, or null while nobody is answering (outside working hours or no agent
     * online): a number would promise a reply that is not coming soon.
     */
    public function currentWaitMinutes(int $position): ?int
    {
        if (! $this->withinWorkingHours()) {
            return null;
        }

        $agents = $this->onlineAgentCount();

        return $agents > 0 ? $this->waitFor($position, $agents) : null;
    }

    private function waitFor(int $position, int $agents): int
    {
        $avg = (int) config('support.chat.avg_handle_minutes');

        return max(1, (int) (ceil($position / max($agents, 1)) * $avg));
    }

    public function waitingCount(): int
    {
        return ChatSession::where('status', 'waiting')->count();
    }

    public function present(): array
    {
        return [
            'online' => $this->isOnline(),
            'workingHours' => $this->hours(),
            // A new chat would join behind everyone already waiting.
            'estimatedWaitMinutes' => $this->estimatedWaitMinutes($this->waitingCount() + 1),
        ];
    }

    /** @return array{from: string, to: string} */
    private function hours(): array
    {
        return [
            'from' => (string) config('support.chat.hours_from'),
            'to' => (string) config('support.chat.hours_to'),
        ];
    }
}
