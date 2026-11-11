<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live chat (support widget)
    |--------------------------------------------------------------------------
    |
    | "Online" means: inside the working hours below (Asia/Tehran wall clock) AND at least
    | one support staff member is `online` with a fresh heartbeat (see presence_ttl_seconds).
    | It only changes the widget's wording and wait estimate: a visitor can always start a
    | chat, and outside these hours does so by leaving a first message that agents answer
    | once they are back (those chats may wait up to waiting_with_messages_hours).
    |
    */

    'chat' => [
        'timezone' => 'Asia/Tehran',
        'hours_from' => env('SUPPORT_CHAT_HOURS_FROM', '09:00'),
        'hours_to' => env('SUPPORT_CHAT_HOURS_TO', '21:00'),

        // Average minutes one agent spends per chat; drives the queue wait estimate.
        'avg_handle_minutes' => (int) env('SUPPORT_CHAT_AVG_HANDLE_MINUTES', 2),

        // An agent counts as online only while their console keeps re-sending `online`
        // (every ~60 s); a closed tab simply stops doing so and expires after this long.
        'presence_ttl_seconds' => (int) env('SUPPORT_CHAT_PRESENCE_TTL', 120),

        // A guest token stops working this many hours after the session was created (and
        // housekeeping closes the session at the same point).
        'guest_ttl_hours' => 24,

        // Max chat image uploads per guest session.
        'guest_upload_limit' => 5,

        // Housekeeping (gordeh:support-chat-housekeeping)
        'empty_waiting_minutes' => 15,
        // A waiting chat that has messages (typically one left overnight) may wait this long
        // for an agent before it is closed.
        'waiting_with_messages_hours' => 48,
        'idle_active_minutes' => 30,
    ],

];
