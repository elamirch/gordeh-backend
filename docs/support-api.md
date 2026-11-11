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
POST   /support/callback-requests             {phone, slot, name?, source?} — members *or guests* (see Chat widget)
```

The chat endpoints are documented under [Chat widget](#chat-widget-members--guests) below.

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

GET    /admin/agents                          role in (admin, support_agent) + activeTicketCount; `state` is the effective presence, `stateAt` when it was set
PATCH  /admin/agents/me/state                 {state: online|in_call|break|offline} — own presence; also the 60 s heartbeat (see Agent presence)
GET    /admin/agents/shifts                   full 7×3 (weekday × window) grid, empty cells included
PATCH  /admin/agents/shifts                   {agentId, weekday, window, assigned: bool}

GET    /admin/chat/sessions?status=&page=&limit=  session list — status filter optional, paginated (same envelope as /admin/tickets)
PATCH  /admin/chat/sessions/{id}              {status: 'waiting'|'active'|'closed'} — explicit close/reopen; `active` claims the session, `closed` records closed_by='agent'
POST   /admin/chat/sessions/{id}/typing       204 — flags "agent is typing" for 5 s and broadcasts ChatAgentTyping
GET    /admin/chat/messages?sessionId=        any session — no ownership restriction; opening it marks the user's messages as read (ChatMessagesRead)
POST   /admin/chat/messages                   {sessionId, text, fileUrl?} — sessionId is required (agents never start a session); the first reply claims the session

GET    /admin/support/reports                 KPIs, 14-day daily series, topic distribution, chat ratings
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

## Chat widget (members + guests)

The global support widget runs on every page, so a visitor without an account can chat too.
Everything below is relative to `API_URL` (which already ends in `/api`).

### Who is calling — `chat.actor`

`ResolveChatActor` (alias `chat.actor`) identifies the caller on the widget routes:

1. `Authorization: Bearer` present → must be a valid JWT (same checks as `auth:api` +
   `check_last_logout`); an invalid/expired one is `401`. The caller is a **member**.
2. else `X-Chat-Token` → the **guest** owning the session whose `guest_token_hash` equals
   `sha256(token)` (compared with `hash_equals`). A guest only ever reaches that one session,
   and the token stops working 24 h after the session was created.
3. else no actor. Public endpoints allow that; the others answer `401` (no session to target)
   or `403` (a session was named but the caller does not own it).

Session ownership is one rule (`ChatActor::canAccess`): `session.user_id === the member`, or
the guest token's own session. Support staff are *not* special here — they use `/admin/chat/*`.
`X-Chat-Token` is allowed through CORS (`allowed_headers: ['*']`; covered by a preflight test).

### Endpoints

```
GET    /support/chat/config                         public, 60/min
POST   /support/chat/sessions                       identity optional, 10/min — {phone?, name?, message? 3–5000 (required while offline)}
GET    /support/chat/sessions/{id}                  owner (member or guest token) — the snapshot; ETag/304 supported
POST   /support/chat/sessions/{id}/end              owner — idempotent
POST   /support/chat/sessions/{id}/rating           owner — {rating: great|good|ok|bad, note?≤1000}
GET    /support/chat/messages?sessionId=            owner
POST   /support/chat/messages                       owner — {sessionId, text, fileUrl?}
POST   /support/chat/uploads                        member or guest token, 10/min — multipart `file` (jpg/png ≤ 10 MB)
POST   /support/callback-requests                   identity optional, 5/min — {phone, slot, name?}
```

Each rate limit is a named limiter with its own bucket (members keyed by account, others by
IP) — the plain `throttle:N,1` form would share one bucket across every route using it.

**`GET /support/chat/config`** → `{ "online": true, "workingHours": {"from":"09:00","to":"21:00"}, "estimatedWaitMinutes": 3 }`.
`online` = inside the working hours (Asia/Tehran clock; `from` inclusive, `to` exclusive; a
window like 20:00–02:00 wraps midnight) **and** at least one staff user is *effectively*
online — `support_state = 'online'` with a `support_state_at` newer than the presence TTL
(see [Agent presence](#agent-presence)). `online=false` only changes the widget's wording and the
wait estimate; it never stops a chat from being started. `estimatedWaitMinutes = max(1, ceil((waiting + 1) / max(onlineAgents, 1)) * avgHandleMinutes)`.
Configured in `config/support.php` (env `SUPPORT_CHAT_HOURS_FROM`, `SUPPORT_CHAT_HOURS_TO`,
`SUPPORT_CHAT_AVG_HANDLE_MINUTES`, `SUPPORT_CHAT_PRESENCE_TTL`).

**`POST /support/chat/sessions`** — `{phone, name?, message?}`; a member's `phone`/`name` are ignored.
- **Online or offline, a chat can always be started.** While offline (outside working hours,
  or nobody effectively online) the visitor must leave a first `message`, which agents answer
  once they are back; the snapshot's `estimatedWaitMinutes` is then `null`. Leaving it out
  while offline is an ordinary validation `422` on `message` — there is no special "offline"
  response. A markup-only message such as `<b></b>` is a `422` on `message` as well.
- Member: returns their open (`waiting|active`) session with `200`, else creates one (`201`).
  A `message`, if sent, is added to whichever session that is — never a second session.
- Guest: `phone` required (`^09\d{9}$`), `name` ≤ 60; always a new session (`201`). The
  response carries `guestToken` — **once, here only**; only its sha256 is stored. Send it back
  as `X-Chat-Token` on every later call.
- `message` (3–5000 characters, plain text — tag-shaped markup is stripped) is optional while
  online and is accepted then too. It is stored in the same transaction as the session, so a
  chat never exists without the message it was opened with.
- Dispatches `NewChatSessionStarted` (now with `patientPhone` + `isGuest`), then `ChatMessageSent`
  for the first message — after the commit.

**Snapshot** (`POST /sessions`, `GET /sessions/{id}`, `/end`, `/rating`, and the `ChatSessionUpdated` push):
```json
{
  "id": 123, "status": "waiting", "queuePosition": 2, "estimatedWaitMinutes": 4,
  "agent": { "name": "مریم رضایی", "joinedAt": "2026-10-13T10:31:00+00:00" },
  "agentTyping": false, "lastReadMessageId": 1012, "lastMessageId": 1020, "rated": false
}
```
- `queuePosition` = `1 + waiting sessions with a smaller id`; `estimatedWaitMinutes` uses that
  position. Both are `null` unless `waiting`, and `estimatedWaitMinutes` is also `null` while the
  chat is offline (no promise of a quick reply at night).
- `agent` is `null` until the first claim, then stays (also after closing). Display name only —
  never phone, email or id. `agentTyping` = `agent_typing_until > now`.
- `lastReadMessageId` = highest user-message id an agent has opened; `lastMessageId` = newest
  message of the session — guests poll the snapshot every ~3 s and only re-fetch history when
  it changes. Send `If-None-Match` to get a bodyless `304` while nothing changed.
- The guest phone and token hash are never part of it.

**Messages** — same shape as before (`id, sessionId, sender, text, fileUrl (signed), createdAt`).
`text` is required (the widget sends `"عکس"` for a picture without a caption) and is plain
text: anything shaped like an HTML tag is stripped, and a message that is *only* markup is a
`422`. `sessionId` is always sent by the widget; omitting it still lazily creates a session for
a member (old behaviour), but is a `422` for a guest. A message on a `closed` session still
reopens it (`closed → waiting`), exactly as before.

**`fileUrl`** must be the `url` returned by `POST /support/chat/uploads` **to the same
caller** — an arbitrary path, an external URL, another user's upload or a file from another
guest session is `422` on `fileUrl`.

**Uploads** → `201 { "url": "chat-uploads/…", "signed_url": "https://…" }` (same keys as
`/stored-files`). jpg/png only (`422` otherwise). Members also get a `StoredFile` row
(`category: chat`); guests have none, their files live in `chat-uploads/guest-{sessionId}/`,
capped at 5 per session (`422`). `GET /api/files` serves anything under `chat-uploads/`
(signed URLs only — guests have no record to attach the file to).

**End / rating** — `end` sets `closed`, `closed_by='user'`, `closed_at` and fires
`ChatSessionStatusChanged` + `ChatSessionUpdated`; repeating it changes nothing and fires
nothing. `rating` needs a `closed` session (`409` otherwise) and is accepted once (`409`);
input is validated first (`422`).

**After hours** — there is no separate "offline message" endpoint: a visitor leaves a message
by starting a chat with `message` (above). Such a `waiting` chat is kept for up to 48 h (see
Housekeeping) and shows up in the agents' queue once they are online. (`callback_requests.topic`
is `TEXT`, and `source` is `support_page` | `chat_widget`.)

**Guest callback requests** — `POST /support/callback-requests` no longer needs a login:
guests are stored with `user_id = null`, `source = 'chat_widget'`; the confirmation SMS is
sent to members only. A member's request is `support_page` unless the widget sends
`source: "chat_widget"`.

### Agent presence

The chat is "online" only while an agent is, so the console must report presence:

```
PATCH  /admin/agents/me/state     is_support_staff, 30/min — {state: online|in_call|break|offline} → 200 {"id": 7, "state": "online"}
```

- Writes `users.support_state` and `users.support_state_at = now()` for the caller.
- **Idempotent — it doubles as the heartbeat.** While `online`, the console repeats
  `state=online` every ~60 s. Repeating the current state is not an error: it only refreshes
  `support_state_at` (without touching `users.updated_at`).
- **Presence expires.** `online` counts only while `support_state_at` is newer than
  `chat.presence_ttl_seconds` (env `SUPPORT_CHAT_PRESENCE_TTL`, default 120). A closed tab just
  stops sending heartbeats and drops out by itself; nothing needs to flip the column. Only
  `online` expires — `in_call` / `break` have no heartbeat and are reported as set.
- `GET /admin/agents` returns the *effective* `state` (a lapsed `online` reads `offline`) plus
  `stateAt`, the last time the state was written (`null` if never).
- `auth/logout` and `auth/logout-all` set a staff user `offline`.
- `AgentPresenceChanged { agentId, state }` is broadcast on `support.agents` when the effective
  state changes — not on heartbeats. A silent expiry sends nothing, so a live list should also
  refetch `GET /admin/agents` now and then.

### Housekeeping (`gordeh:support-chat-housekeeping`, scheduled every minute)

- `waiting` with no message at all and older than 15 min → `closed` (`closed_by='system'`).
- `waiting` *with* messages (e.g. an overnight message) stays open for up to 48 h, then
  `closed` (`system`). The 48 h run from when the chat last entered `waiting` (`updated_at`),
  not from creation — a chat the user reopens after weeks is not closed again at once.
- `active` with no message (and no status change) for 30 min → `closed` (`system`).
- Guest sessions older than 24 h → `closed` (`system`), whatever their state.
- Each close fires `ChatSessionStatusChanged` + `ChatSessionUpdated`; queue positions of the
  remaining waiting member sessions are re-broadcast once per run.

Thresholds live in `config/support.php` (`empty_waiting_minutes`, `waiting_with_messages_hours`, `idle_active_minutes`, `guest_ttl_hours`). Remember the scheduler (`php artisan schedule:work`
or the usual cron entry) must be running.

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
- Closing records `closed_at` + `closed_by` (`user` | `agent` | `system`); leaving `closed`
  clears both. Entering or leaving `waiting` re-broadcasts `ChatSessionUpdated` to the waiting
  *member* sessions queued behind it (guests poll instead).
- **Claim:** the first agent reply — or `PATCH {status:'active'}` — sets `agent_id` /
  `agent_joined_at`; later agents never replace it, and it survives closing.
- Bookkeeping that is not "activity" (claim, typing, read receipts) is saved without touching
  `updated_at`, so it never reorders `GET /admin/chat/sessions`.
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
| `private-chat.{sessionId}` | The patient who owns the session, or any support staff (`admin`/`support_agent`). Guest sessions: staff only — guests poll instead | One patient↔support chat conversation |
| `private-ticket.{ticketId}` | The patient who owns the ticket, or any support staff | Ticket thread — **replies only**, never notes (see below) |
| `private-support.agents` | Support staff only, never a patient | Admin-panel-wide queue feed: new tickets, new chats, internal notes, anything staff should see regardless of which specific ticket they have open |

All three are **private** channels (Laravel Echo: `Echo.private('chat.5')`, not `.channel()`)
— the `private-` prefix is Echo's own convention, added automatically by the client library,
not something you type yourself.

### Events

| Event (`.ChatMessageSent` etc. — see `broadcastAs()`) | Broadcast on | Fired when |
|---|---|---|
| `ChatMessageSent` | `chat.{sessionId}` | Any chat message is stored — patient (`POST /support/chat/messages`) or agent (`POST /admin/chat/messages`) |
| `NewChatSessionStarted` | `support.agents` | A session is created — `POST /support/chat/sessions`, or a member's first message without a `sessionId` |
| `ChatSessionUpdated` | `chat.{sessionId}` | The widget snapshot changed: claim, status change, end, rating, queue position |
| `AgentPresenceChanged` | `support.agents` | An agent's effective presence changed (`PATCH /admin/agents/me/state`, logout) — never for a heartbeat |
| `ChatAgentTyping` | `chat.{sessionId}` | `POST /admin/chat/sessions/{id}/typing` |
| `ChatMessagesRead` | `chat.{sessionId}` | An agent opened the conversation and `last_read_message_id` advanced |
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

**`NewChatSessionStarted`** (staff-only channel, so the phone may be included):
```json
{
  "sessionId": "5", "patientId": 12, "patientName": "علی رضایی", "patientPhone": "09121234567",
  "isGuest": false, "createdAt": "2026-08-23T10:00:00+00:00"
}
```
For a guest `patientId` is `null` and `patientName` is the given name or `"مهمان"`.

**`AgentPresenceChanged`** — `{ "agentId": 7, "state": "online" }`.

**`ChatSessionUpdated`** — the snapshot above (never a `guestToken`). **`ChatAgentTyping`** —
`{ "sessionId": 123 }`. **`ChatMessagesRead`** — `{ "sessionId": 123, "lastReadMessageId": 1012 }`.

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
echo.private(`chat.${sessionId}`)
  .listen('.ChatMessageSent', (e) => { /* append */ })
  .listen('.ChatSessionUpdated', (e) => { /* replace the snapshot: queue position, agent, status, rated */ })
  .listen('.ChatAgentTyping', (e) => { /* show "typing…" for ~5 s */ })
  .listen('.ChatMessagesRead', (e) => { /* mark own messages with id <= e.lastReadMessageId as seen */ });
// Guests cannot join private channels: poll GET /support/chat/sessions/{id} (X-Chat-Token) every ~3 s.

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
- Guest tokens are stored only as sha256, compared with `hash_equals`, never logged and never
  returned after creation; the guest phone is only ever shown to staff (`ChatSession` hides
  `guest_phone` / `guest_token_hash` from serialization as a second layer).
- Chat text is plain text (tag-shaped markup stripped server-side; the frontend still escapes).
  `fileUrl` accepts only files the same caller uploaded through the chat uploader.
- Covered by `SupportChatWidgetTest`, `SupportChatUploadTest`, `SupportChatHousekeepingTest`
  and `AgentPresenceTest`.
- Channel authorization (`routes/channels.php`) mirrors the REST ownership rules exactly: a
  patient can open `chat.{id}`/`ticket.{id}` only for their own session/ticket;
  `support.agents` is staff-only. A note never reaches a patient's socket regardless of
  channel authorization, because `TicketMessageSent` simply never broadcasts it there
  (see Real-time above) — two independent layers, not one relying on the other.
