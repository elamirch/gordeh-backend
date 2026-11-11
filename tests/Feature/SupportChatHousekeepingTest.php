<?php

namespace Tests\Feature;

use App\Events\ChatSessionStatusChanged;
use App\Events\ChatSessionUpdated;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithSupportChat;
use Tests\TestCase;

class SupportChatHousekeepingTest extends TestCase
{
    use InteractsWithSupportChat;
    use RefreshDatabase;

    private function run_(): void
    {
        $this->artisan('gordeh:support-chat-housekeeping')->assertSuccessful();
    }

    private function aged(ChatSession $session, int $minutes): ChatSession
    {
        $at = now()->subMinutes($minutes);
        $session->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $session;
    }

    private function message(ChatSession $session, int $minutesAgo, string $sender = 'user'): ChatMessage
    {
        $message = ChatMessage::create(['chat_session_id' => $session->id, 'sender' => $sender, 'text' => 'x']);
        $message->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $message;
    }

    public function test_empty_waiting_sessions_older_than_fifteen_minutes_are_closed_by_the_system(): void
    {
        $stale = $this->aged($this->waitingSession(), 16);
        $fresh = $this->aged($this->waitingSession(), 14);

        $this->run_();

        $stale->refresh();
        $this->assertSame('closed', $stale->status);
        $this->assertSame('system', $stale->closed_by);
        $this->assertNotNull($stale->closed_at);
        $this->assertSame('waiting', $fresh->fresh()->status);
    }

    public function test_a_waiting_session_with_a_message_stays_open_for_forty_eight_hours(): void
    {
        $overnight = $this->aged($this->waitingSession(), 47 * 60);
        $this->message($overnight, 47 * 60 - 1);
        $recent = $this->aged($this->waitingSession(), 120);
        $this->message($recent, 100);

        $this->run_();

        $this->assertSame('waiting', $overnight->fresh()->status);
        $this->assertSame('waiting', $recent->fresh()->status);
    }

    public function test_a_waiting_session_with_messages_is_closed_by_the_system_after_forty_eight_hours(): void
    {
        $stale = $this->aged($this->waitingSession(), 49 * 60);
        $this->message($stale, 49 * 60 - 1);
        $this->message($stale, 30 * 60);                   // late user messages don't extend the wait
        $other = $this->aged($this->waitingSession(), 5 * 60);
        $this->message($other, 4 * 60);
        Event::fake([ChatSessionUpdated::class, ChatSessionStatusChanged::class]);

        $this->run_();

        $stale->refresh();
        $this->assertSame('closed', $stale->status);
        $this->assertSame('system', $stale->closed_by);
        $this->assertNotNull($stale->closed_at);
        $this->assertSame('waiting', $other->fresh()->status);
        Event::assertDispatched(ChatSessionStatusChanged::class, fn ($e) => $e->session->id === $stale->id && $e->session->status === 'closed');
        Event::assertDispatched(ChatSessionUpdated::class, fn ($e) => $e->session->id === $stale->id);
        // The remaining waiting member session is refreshed (its queue position moved).
        Event::assertDispatched(ChatSessionUpdated::class, fn ($e) => $e->session->id === $other->id);
    }

    public function test_a_long_closed_chat_that_the_user_just_reopened_is_not_mistaken_for_an_old_one(): void
    {
        $member = $this->member();
        $old = ChatSession::create(['user_id' => $member->id, 'status' => 'closed', 'closed_by' => 'agent', 'closed_at' => now()->subDays(10)]);
        $this->aged($old, 10 * 24 * 60);
        $this->message($old, 10 * 24 * 60 - 1);

        // The user writes again: the real service path reopens it (closed -> waiting).
        $this->asMember($member)
            ->postJson('/api/support/chat/messages', ['sessionId' => $old->id, 'text' => 'هنوز مشکل دارم'])
            ->assertCreated();
        $this->assertSame('waiting', $old->fresh()->status);

        $this->run_();

        $this->assertSame('waiting', $old->fresh()->status);
        $this->assertNull($old->fresh()->closed_by);
    }

    public function test_active_sessions_are_closed_after_thirty_minutes_without_messages(): void
    {
        $idle = $this->aged(ChatSession::create(['user_id' => $this->member()->id, 'status' => 'active']), 90);
        $this->message($idle, 31);
        $chatty = $this->aged(ChatSession::create(['user_id' => $this->member()->id, 'status' => 'active']), 90);
        $this->message($chatty, 31);
        $this->message($chatty, 5, 'support');
        $justStarted = $this->aged(ChatSession::create(['user_id' => $this->member()->id, 'status' => 'active']), 10);
        $noMessagesButOld = $this->aged(ChatSession::create(['user_id' => $this->member()->id, 'status' => 'active']), 45);

        $this->run_();

        $this->assertSame('closed', $idle->fresh()->status);
        $this->assertSame('system', $idle->fresh()->closed_by);
        $this->assertSame('active', $chatty->fresh()->status);
        $this->assertSame('active', $justStarted->fresh()->status);
        $this->assertSame('closed', $noMessagesButOld->fresh()->status);
    }

    public function test_guest_sessions_older_than_a_day_are_closed_even_when_active(): void
    {
        [$old] = $this->guestSession(['status' => 'active']);
        $this->aged($old, 25 * 60);
        $this->message($old, 1);                           // recent chatter does not save it
        [$young] = $this->guestSession(['status' => 'active']);
        $this->aged($young, 23 * 60);
        $this->message($young, 1);
        // Members are not subject to the 24h rule.
        $member = $this->aged(ChatSession::create(['user_id' => $this->member()->id, 'status' => 'active']), 25 * 60);
        $this->message($member, 1);

        $this->run_();

        $this->assertSame('closed', $old->fresh()->status);
        $this->assertSame('system', $old->fresh()->closed_by);
        $this->assertSame('active', $young->fresh()->status);
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_closed_sessions_are_left_alone(): void
    {
        $session = ChatSession::create([
            'user_id' => $this->member()->id, 'status' => 'closed', 'closed_by' => 'agent', 'closed_at' => now()->subDay(),
        ]);
        $this->aged($session, 24 * 60);
        Event::fake([ChatSessionUpdated::class, ChatSessionStatusChanged::class]);

        $this->run_();

        $this->assertSame('agent', $session->fresh()->closed_by);
        Event::assertNotDispatched(ChatSessionStatusChanged::class);
    }

    public function test_each_system_close_dispatches_both_events_and_the_queue_is_refreshed_once(): void
    {
        $a = $this->aged($this->waitingSession(), 20);
        $b = $this->aged($this->waitingSession(), 20);
        $survivor = $this->waitingSession();
        $this->message($survivor, 1);
        [$guestWaiting] = $this->guestSession();           // guests poll: never re-broadcast
        $this->message($guestWaiting, 1);
        Event::fake([ChatSessionUpdated::class, ChatSessionStatusChanged::class]);

        $this->run_();

        foreach ([$a, $b] as $closed) {
            Event::assertDispatched(ChatSessionStatusChanged::class, fn ($e) => $e->session->id === $closed->id && $e->session->status === 'closed');
            Event::assertDispatched(ChatSessionUpdated::class, fn ($e) => $e->session->id === $closed->id);
        }
        Event::assertDispatchedTimes(ChatSessionStatusChanged::class, 2);

        // One refresh for the remaining waiting member session — not one per closed session.
        $refreshes = Event::dispatched(ChatSessionUpdated::class, fn ($e) => $e->session->id === $survivor->id);
        $this->assertCount(1, $refreshes);
        Event::assertNotDispatched(ChatSessionUpdated::class, fn ($e) => $e->session->id === $guestWaiting->id);
    }

    public function test_it_is_scheduled_every_minute(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'gordeh:support-chat-housekeeping'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
