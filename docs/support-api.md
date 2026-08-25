# Support API

Patient-facing and Admin/Agent-facing endpoints for the Support Center, backed by one
shared set of tables and one shared service layer (`app/Services/Support/`). Both sides
read and write the same `tickets`, `ticket_messages`, `callback_requests`, and `faq_items`
rows — there is no separate admin copy of any of this data.

This file covers the parts an auto-generated spec doesn't: shared architecture, lifecycle
rules, and the security model. For the mechanical per-endpoint reference (exact request/
response schemas, validation rules), this project has [dedoc/scramble](https://scramble.dedoc.co)
already installed — it introspects every route live, so the endpoints below are already in
it with no extra work. Run the app and open `/docs/api` for the interactive version.

## Authentication

All endpoints below require `Authorization: Bearer <jwt>` (the same `auth:api` guard used
everywhere else in this API — no new auth system). `/admin/*` endpoints additionally require
the authenticated user's `role` to be `admin` or `support_agent` (`is_support_staff`
middleware, `app/Http/Middleware/IsSupportStaff.php`). Every other `/admin/*` route in this
app (dashboard, nutrition-monitoring, …) is unaffected — it still requires strict `is_admin`.

## Shared architecture

```
routes/api.php
  ├─ /support/*   (patient)  → App\Http\Controllers\Support\*
  └─ /admin/*     (staff)    → App\Http\Controllers\Admin\*
                                        │
                                        ▼
                        App\Services\Support\{Ticket,Callback,Faq,Report}Service
                                        │
                                        ▼
                              Ticket, TicketMessage, CallbackRequest, FaqItem
```

Controllers on both sides are thin — they validate input, call a service method, and
serialize the result. All state transitions, SLA math, and code generation live in the
services so patient and admin paths can never drift into inconsistent behavior. The two
sides differ only in **authorization** (ownership check vs. `is_support_staff`) and
**response shape** (`presentForPatient()` vs `presentForAdmin()`), never in business logic.

### Internal vocabulary vs. the patient-facing contract

The DB and the admin API use a richer vocabulary than what patients see, mapped in the
service layer so the existing patient API contract never had to change:

| Concept | Internal (DB / admin API) | Patient API |
|---|---|---|
| Ticket status | `open`, `in_progress`, `waiting_patient`, `closed` | `open`, `answered`, `closed` (`TicketService::mapStatusForPatient()`) |
| Message author | `user`, `agent`, `note` | `user`, `support` (`note` is filtered out entirely — never serialized to a patient) |
| Callback window | `slot` column | same values, exposed as `window` in the admin response |

## Patient endpoints (`/support/*`)

Unchanged from the first Support Center pass — see that work for detail. Ownership is
always enforced by `user_id === auth()->id()`; a ticket message list never includes
`author = 'note'` rows.

```
GET    /support/faq                          published FAQs only
GET    /support/tickets                      own tickets
POST   /support/tickets                      {category, subject, description, fileUrl?}
GET    /support/tickets/{id}                 own ticket only (403 otherwise)
GET    /support/tickets/{id}/messages         excludes internal notes
POST   /support/tickets/{id}/messages         {text, fileUrl?} — rejected (403) if ticket is closed
POST   /support/callback-requests             {phone, slot}
GET    /support/chat/messages?sessionId=      own session's history only
POST   /support/chat/messages                 {sessionId?, text, fileUrl?} — creates a session if sessionId is omitted
```

## Admin/Agent endpoints (`/admin/*`)

```
GET    /admin/tickets/queue-summary
GET    /admin/tickets?page=&status=&priority=&agent=&q=
GET    /admin/tickets/{id}
PATCH  /admin/tickets/{id}                    {status?, agentId?, priority?, tags?}
POST   /admin/tickets/{id}/messages           {text, type: 'reply'|'note', attachmentUrl?}

GET    /admin/callback-requests
PATCH  /admin/callback-requests/{id}          {state: 'now'|'wait'|'miss'|'done'}

GET    /admin/faq                             published + draft
POST   /admin/faq                             {question, answer, category?, status}
PATCH  /admin/faq/{id}                        any subset of the above

GET    /admin/agents                          role in (admin, support_agent) + activeTicketCount
GET    /admin/agents/shifts                   full 7×3 (weekday × window) grid, empty cells included
PATCH  /admin/agents/shifts                   {agentId, weekday, window, assigned: bool}

GET    /admin/chat/sessions?status=&page=&limit=  session list — status filter optional, paginated (same envelope as /admin/tickets)
PATCH  /admin/chat/sessions/{id}              {status: 'waiting'|'active'|'closed'} — explicit close/reopen
GET    /admin/chat/messages?sessionId=        any session — no ownership restriction for staff
POST   /admin/chat/messages                   {sessionId, text, fileUrl?} — sessionId is required (agents never start a session)

GET    /admin/support/reports                 KPIs, 14-day daily series, topic distribution
```

`GET /admin/tickets` and `GET /admin/callback-requests` return Laravel's standard paginator
envelope (`data`, `current_page`, `last_page`, `total`, …) — the same convention already
used by `InsuranceController::index`, not a custom shape.

Report values are raw numbers and ISO dates, not pre-formatted Persian strings — this
matches the explicit, documented convention already established for admin reporting in
`App\Services\NutritionMonitoring\MonitoringService` ("Persian digit/label formatting stays
in the frontend"). Whoever wires the admin frontend to this endpoint formats client-side,
same as every other admin report screen in this app already does.

### Reassignment note

The existing mocked admin frontend's `useReassignTicket` sends a raw `agent_name` string.
This backend accepts `agentId` (an integer, validated against real staff accounts) instead
— a plain name string can't be checked for "is this actually a support agent," so it can't
be the source of truth. Wiring the frontend to this contract means sending the agent's id,
not their name.

## Ticket lifecycle

```
        create                 agent replies              patient replies
 ─────────────────►  open  ─────────────────►  waiting_patient  ─────────────────►  in_progress
                       │  ▲                                                              │
                       │  │ agent assigned (no explicit status in the same request)       │
                       │  └───────────────────────────── in_progress ◄────────────────────┘
                       │
                       └──────────────────────► closed  (admin only, via PATCH status)
```

- A patient can never set `status`, `agentId`, `priority`, `sender`/`author`, or
  `authorId` — every one of those is either hard-coded server-side or simply absent
  from the patient-facing validated field list, so extra keys in the request body are
  silently ignored.
- An internal note (`type: 'note'`) never changes ticket status and is never visible to
  the patient, at any endpoint.
- Sending a message to a `closed` ticket is rejected (403) on both sides — the patient
  endpoint blocks it explicitly, and the admin reply path has no code path that clears
  `closed` except an explicit `PATCH {status: ...}` first.

## Chat session lifecycle

`chat_sessions.status`: `waiting` | `active` | `closed` — `waiting` by default at creation.
Unlike ticket status, a patient message never needs an explicit lifecycle rule beyond one
case; the interesting transitions are agent-driven:

```
create → waiting ──agent replies──► active ──agent closes (PATCH)──► closed
            ▲                                                            │
            └────────────────── patient sends a new message ─────────────┘
```

- An agent reply on a `waiting` **or** `closed` session → `active` (a `closed` session that
  gets picked back up by staff is being worked, not just reopened-and-idle).
- A patient message on a `closed` session → `waiting` (needs an agent again); on `waiting` or
  `active` it's a no-op — those already correctly signal "needs attention" or "in progress".
- Explicit `PATCH /admin/chat/sessions/{id}` can set any of the 3 values directly — this is
  the only way to reach `closed` at all, and the only way to reopen straight to `waiting`
  without an agent message.
- A same-status update (automatic or explicit) is a no-op: no `updated_at` touch, no
  `ChatSessionStatusChanged` broadcast. Only an actual change fires it.
- This logic lives once in `ChatService::applyAutoTransition()`/`updateStatus()`, shared by
  the patient send path, the agent send path, and the explicit PATCH endpoint — same
  "shared service, thin controllers" rule as everything else in this doc.

## SLA

`sla_deadline = created_at + SLA_HOURS[priority]`, recomputed whenever `priority` changes.
No SLA policy existed anywhere in this codebase before this feature (the closest thing,
`MonitoringSetting`, is scoped to nutrition-consultation bookings, not tickets), so the
values are a new, documented policy constant in `TicketService::SLA_HOURS`:

- `normal` → 2 hours (matches the response-time commitment already shown to patients on
  the ticket-created screen: "معمولاً تا ۲ ساعت پاسخ می‌دهیم")
- `urgent` → 30 minutes

Breach detection is computed on read (`now() > sla_deadline`, never true for a closed
ticket) — there is no cron marking tickets breached, and none was needed.

## Notifications

Reuses the existing `App\Services\SendSMS` + Kavenegar pipeline (same one used for OTP and
insurance reminders) — no new provider. Real sends are skipped during automated tests and
local debug mode, same guard `AuthController::sendotp` already uses.

| Event | Wired? |
|---|---|
| Ticket created | Yes — `SendSMS::supportNewTicket()` |
| Agent replies | Yes — `SendSMS::supportReply()` |
| Ticket closed | Yes — `SendSMS::supportTicketClosed()` |
| Callback requested | Yes — `SendSMS::supportCallbackRequest()` |

As with every other template already in `SendSMS` (`otp-kcp`, `insurance-generated`, …),
each of these calls a `template` string that must be registered with the SMS gateway
(Kavenegar) before it will actually deliver — that's an operational step on the provider's
dashboard, not a code gap.

## Real-time

Laravel Reverb (native, no third-party service — Pusher/Ably would mean an external
account and monthly cost for the same protocol Reverb already speaks; the app already runs
its own queue worker in dev via `composer dev`'s `queue:listen`, so self-hosting the socket
server alongside it is a small, natural addition rather than new infrastructure). Events
implement `ShouldBroadcast`, so they're dispatched onto the existing queue connection
(`QUEUE_CONNECTION=database`) and need a worker running to actually deliver — same
operational requirement `SendSmsJob` already has, nothing new.

### Setup already done in this repo

- `composer require laravel/reverb` (installed, `config/reverb.php` + `config/broadcasting.php`
  published).
- `.env`: `BROADCAST_CONNECTION=reverb` + `REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET`/
  `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` (auto-generated by `reverb:install`, see below
  for the actual dev values to give the frontend).
- `/broadcasting/auth` is registered under `/api` with `auth:api` + `check_last_logout` —
  **not** the framework's session-based default (`bootstrap/app.php`'s `withBroadcasting()`
  call, replacing `withRouting()`'s `channels:` param). This app is stateless (Bearer tokens
  only); the default `web`-guard registration would never authenticate a request from the
  Next.js frontend, which has no session cookie.
- `routes/channels.php` — the three private channels below, each explicitly scoped to the
  `api` guard (`'guards' => ['api']`) rather than relying on the default-guard fallback.
- To actually run the socket server: `php artisan reverb:start` (add it alongside
  `queue:listen` in `composer dev`'s concurrently list for local dev; in production it runs
  as its own long-lived process, typically behind a reverse proxy that upgrades the
  WebSocket connection — not configured here, since this repo has no production deploy
  config to begin with).

### Channels

| Channel | Who can join | Purpose |
|---|---|---|
| `private-chat.{sessionId}` | The patient who owns the session, or any support staff (`admin`/`support_agent`) | One patient↔support chat conversation |
| `private-ticket.{ticketId}` | The patient who owns the ticket, or any support staff | Ticket thread — **replies only**, never notes (see below) |
| `private-support.agents` | Support staff only, never a patient | Admin-panel-wide queue feed: new tickets, new chats, internal notes, anything staff should see regardless of which specific ticket they have open |

All three are **private** channels (Laravel Echo: `Echo.private('chat.5')`, not `.channel()`)
— the `private-` prefix is Echo's own convention, added automatically by the client library,
not something you type yourself.

### Events

| Event (`.ChatMessageSent` etc. — see `broadcastAs()`) | Broadcast on | Fired when |
|---|---|---|
| `ChatMessageSent` | `chat.{sessionId}` | Any chat message is stored — patient (`POST /support/chat/messages`) or agent (`POST /admin/chat/messages`) |
| `NewChatSessionStarted` | `support.agents` | A patient's first message creates a session (no `sessionId` in the request) |
| `ChatSessionStatusChanged` | `support.agents` | A session's status actually changes — automatic transition or explicit `PATCH /admin/chat/sessions/{id}` (never fires for a same-status no-op) |
| `TicketMessageSent` | `ticket.{ticketId}` **and** `support.agents`, unless it's a note | A ticket message is stored, from any of the three ticket-message endpoints |

**Why a note never reaches `ticket.{ticketId}`:** that channel is joinable by the patient.
`TicketMessageSent::broadcastOn()` only adds the `ticket.{ticketId}` channel when
`author !== 'note'` — a note broadcasts on `support.agents` alone. This is enforced in the
event class itself, not by trusting the client to filter — the patient's socket simply never
receives the frame. An agent viewing a specific ticket's thread should subscribe to *both*
`ticket.{ticketId}` (replies, live) and `support.agents` (filter client-side by the payload's
`ticketId` for notes on that ticket) to see the complete thread including notes.

### Payload shapes

Exactly what the equivalent REST endpoint already returns — nothing broadcast-specific:

**`ChatMessageSent`** — identical to `POST /support/chat/messages`'s response:
```json
{ "id": "12", "sessionId": "5", "sender": "user", "text": "...", "fileUrl": null, "createdAt": "2026-08-23T10:00:00+00:00" }
```
`sender` is `"user"` or `"support"` (the existing `ChatMessage.sender` enum — unchanged).

**`NewChatSessionStarted`**:
```json
{ "sessionId": "5", "patientId": 12, "patientName": "علی رضایی", "createdAt": "2026-08-23T10:00:00+00:00" }
```

**`ChatSessionStatusChanged`** — deliberately the smallest possible payload, `sessionId` as a
plain int (matching `GET /admin/chat/sessions`' shape, not `NewChatSessionStarted`'s string —
these two events were added in different rounds and never fully reconciled on that one field;
harmless since each is self-contained, but worth knowing if you're writing one shared handler
for both):
```json
{ "sessionId": 15, "status": "active" }
```

**`TicketMessageSent`** — same superset shape `TicketService::presentMessageForAdmin()` already
returns (not the patient's narrower `sender`/`fileUrl` shape):
```json
{
  "id": "41", "ticketId": "7", "author": "agent", "authorId": 3, "authorName": "سارا احمدی",
  "text": "...", "attachmentUrl": null, "createdAt": "2026-08-23T10:05:00+00:00"
}
```
`author` is `"user"`, `"agent"`, or `"note"`. A patient-side Echo listener maps
`author === 'agent' ? 'support' : 'user'` client-side to match the REST contract's `sender`
field — that's a display-label mapping, not a security boundary (`TicketService` already does
the identical mapping server-side for the REST response); the only thing that's actually kept
from the patient is the note itself, via the channel split above, not this field name.

### Frontend (Next.js) — laravel-echo config

```bash
npm install --save laravel-echo pusher-js
```

```ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'reverb',
  key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
  wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
  wsPort: process.env.NEXT_PUBLIC_REVERB_PORT,
  wssPort: process.env.NEXT_PUBLIC_REVERB_PORT,
  forceTLS: process.env.NEXT_PUBLIC_REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: `${process.env.NEXT_PUBLIC_API_URL}/broadcasting/auth`,
  authorizer: (channel: { name: string }) => ({
    authorize: (socketId: string, callback: (error: boolean, data: unknown) => void) => {
      // Reuse http-service.ts here instead of raw fetch, the same way every other
      // /api call already attaches the session's Bearer token — this endpoint needs
      // that same header, it's a normal authenticated POST like any other.
      createData(`/broadcasting/auth`, { socket_id: socketId, channel_name: channel.name })
        .then(data => callback(false, data))
        .catch(error => callback(true, error));
    },
  }),
});

// Patient's own ticket thread
echo.private(`ticket.${ticketId}`).listen('.TicketMessageSent', (e) => { /* append if e.author !== 'note' */ });

// Patient's chat
echo.private(`chat.${sessionId}`).listen('.ChatMessageSent', (e) => { /* append */ });

// Admin panel queue feed
echo.private('support.agents')
  .listen('.NewChatSessionStarted', (e) => { /* new chat toast; note e.sessionId is a string here */ })
  .listen('.ChatSessionStatusChanged', (e) => { /* move the session between the waiting/active/closed tabs */ })
  .listen('.TicketMessageSent', (e) => { /* toast if e.author === 'note', or refresh queue counts */ });
```

Dev values currently in `.env` (rotate `REVERB_APP_SECRET` for anything beyond local dev —
it's never sent to the client, only used server-side to sign auth responses):

```
NEXT_PUBLIC_REVERB_APP_KEY=u7uv5uj331i285jvtsmw
NEXT_PUBLIC_REVERB_HOST=localhost
NEXT_PUBLIC_REVERB_PORT=8080
NEXT_PUBLIC_REVERB_SCHEME=http
```

Note the leading `.` on `.TicketMessageSent`/`.ChatMessageSent`/`.NewChatSessionStarted` in
the `listen()` calls — Echo requires it for events using `broadcastAs()` (a custom name
without the app's namespace prefix), which all three of these do.

## Security

Enforced server-side, covered by `tests/Feature/AdminTicketTest.php`,
`AdminCallbackTest.php`, `AdminFaqTest.php`, `SupportBroadcastingTest.php`:

- A patient can only ever read/write tickets, messages, and callback requests where
  `user_id === auth()->id()` — checked on every patient route, not just the list endpoint.
- Internal notes (`author = 'note'`) are excluded at the query level for the patient
  messages endpoint — not just hidden in the response shape.
- `author` / `authorId` on a ticket message are never read from the request body; they're
  always derived from the authenticated user and the `type` field.
- `PATCH /admin/tickets/{id}` only ever reads `status`, `agentId`, `priority`, `tags` from
  the request — any other key (`patientId`, `createdAt`, …) is simply not in the validated
  array, so it has no effect regardless of what the client sends.
- `/admin/*` support routes require `role IN (admin, support_agent)`; a plain `user` gets
  403 on all of them.
- Channel authorization (`routes/channels.php`) mirrors the REST ownership rules exactly: a
  patient can open `chat.{id}`/`ticket.{id}` only for their own session/ticket;
  `support.agents` is staff-only. A note never reaches a patient's socket regardless of
  channel authorization, because `TicketMessageSent` simply never broadcasts it there
  (see Real-time above) — two independent layers, not one relying on the other.
