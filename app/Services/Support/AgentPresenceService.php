<?php

namespace App\Services\Support;

use App\Events\AgentPresenceChanged;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Writes a support agent's presence (users.support_state / support_state_at). Repeating the
 * current state is a normal heartbeat, not an error: it just refreshes support_state_at.
 */
class AgentPresenceService
{
    public const STATES = ['online', 'in_call', 'break', 'offline'];

    /** @return string the state now in effect */
    public function set(User $agent, string $state): string
    {
        $before = $agent->effectiveSupportState();

        // A heartbeat every minute must not keep bumping users.updated_at.
        User::withoutTimestamps(function () use ($agent, $state) {
            $agent->forceFill(['support_state' => $state, 'support_state_at' => now()])->save();
        });

        // Only a real change is news; heartbeats stay silent.
        if ($before !== $state) {
            Log::info('support.agent_presence_changed', ['agent_id' => $agent->id, 'state' => $state]);

            AgentPresenceChanged::dispatch($agent->id, $state);
        }

        return $state;
    }

    /** Signing out ends the shift: nobody should be routed chats to a console that is gone. */
    public function signedOut(?User $user): void
    {
        if ($user !== null && $user->isSupportStaff()) {
            $this->set($user, 'offline');
        }
    }
}
