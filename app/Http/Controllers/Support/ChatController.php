<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Services\Support\ChatAvailability;
use App\Services\Support\ChatService;
use App\Services\Support\ChatUploadService;
use App\Support\ChatActor;
use App\Support\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Chat endpoints for the global support widget. Callers are members (JWT) or guests
 * (X-Chat-Token) — see ResolveChatActor / ChatActor. Staff answer through /admin/chat/*.
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $service,
        private readonly ChatAvailability $availability,
        private readonly ChatUploadService $uploads,
    ) {}

    // GET /support/chat/config (public)
    public function config(): JsonResponse
    {
        return response()->json($this->availability->present());
    }

    // POST /support/chat/sessions
    public function start(Request $request): JsonResponse
    {
        $member = ChatActor::from($request)?->user;

        $leftAsMarkup = $this->isMarkupOnly($request->input('message'));
        PlainText::sanitizeRequest($request, ['name', 'message']);
        if ($leftAsMarkup) {
            throw ValidationException::withMessages(['message' => ['پیام معتبر نیست']]);
        }

        // Being offline never blocks a chat; it only means the visitor has to leave a message
        // (the frontend always sends one then) for agents to pick up when they are back.
        $messageNeeded = ! $request->filled('message') && ! $this->availability->isOnline();

        $data = $request->validate([
            // Members are identified by their account; the phone only matters for guests.
            'phone' => $member ? ['nullable'] : ['required', 'string', 'regex:'.ChatService::PHONE_REGEX],
            'name' => ['nullable', 'string', 'max:60'],
            'message' => [$messageNeeded ? 'required' : 'nullable', 'string', 'min:3', 'max:5000'],
        ]);
        $message = $data['message'] ?? null;

        if ($member) {
            [$session, $created] = $this->service->startForMember($member, $message);
            $status = $created ? 201 : 200;
        } else {
            [$session, $token] = $this->service->startForGuest($data['phone'], $data['name'] ?? null, $message);
            $status = 201;
        }

        $snapshot = $this->service->presentSession($session);

        // The raw guest token is only ever returned here.
        return response()->json(isset($token) ? $snapshot + ['guestToken' => $token] : $snapshot, $status);
    }

    // GET /support/chat/sessions/{chatSession}
    public function show(Request $request, ChatSession $chatSession): JsonResponse
    {
        $this->authorizeSession($request, $chatSession);

        $snapshot = $this->service->presentSession($chatSession);

        // Guests poll this every few seconds; let unchanged state answer with a bodyless 304.
        $response = response()->json($snapshot);
        $response->setEtag(md5(json_encode($snapshot)));
        $response->isNotModified($request);

        return $response;
    }

    // POST /support/chat/sessions/{chatSession}/end
    public function end(Request $request, ChatSession $chatSession): JsonResponse
    {
        $this->authorizeSession($request, $chatSession);

        return response()->json($this->service->presentSession($this->service->end($chatSession)));
    }

    // POST /support/chat/sessions/{chatSession}/rating
    public function rate(Request $request, ChatSession $chatSession): JsonResponse
    {
        $this->authorizeSession($request, $chatSession);

        PlainText::sanitizeRequest($request, ['note']);
        $data = $request->validate([
            'rating' => ['required', Rule::in(ChatService::RATINGS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $session = $this->service->rate($chatSession, $data['rating'], $data['note'] ?? null);

        return response()->json($this->service->presentSession($session));
    }

    // GET /support/chat/messages?sessionId=
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sessionId' => ['required', 'integer'],
        ]);

        $session = $this->service->findSession($data['sessionId']);
        if (! $session) {
            return response()->json(['message' => 'Chat session not found'], 404);
        }
        $this->authorizeSession($request, $session);

        $messages = $this->service->history($session);

        return response()->json($messages->map(fn ($m) => $this->service->present($m))->values());
    }

    // POST /support/chat/messages
    public function storeMessage(Request $request): JsonResponse
    {
        $actor = ChatActor::from($request);

        // Without a session to target, only a logged-in member can (lazily) start one.
        if (! $actor && ! $request->filled('sessionId')) {
            abort(401, 'Unauthenticated');
        }

        PlainText::sanitizeRequest($request, ['text']);
        $data = $request->validate([
            'sessionId' => ['nullable', 'integer'],
            'text' => ['required', 'string', 'min:1', 'max:5000'],
            'fileUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        if (isset($data['sessionId'])) {
            $session = $this->service->findSession($data['sessionId']);
            if (! $session) {
                return response()->json(['message' => 'Chat session not found'], 404);
            }
            $this->authorizeSession($request, $session);
        } elseif ($actor->isGuest()) {
            throw ValidationException::withMessages(['sessionId' => ['sessionId is required']]);
        } else {
            $session = null;
        }

        // Checked before a lazy session is created, so a bad attachment leaves nothing behind.
        $fileUrl = $this->uploads->attachmentFor($actor, $data['fileUrl'] ?? null);

        $session ??= $this->service->createSession($actor->user);

        $message = $this->service->addMessage($session, 'user', $data['text'], $fileUrl);

        return response()->json($this->service->present($message), 201);
    }

    // POST /support/chat/uploads
    public function upload(Request $request): JsonResponse
    {
        $actor = ChatActor::from($request) ?? abort(401, 'Unauthenticated');

        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,png', 'max:10240'],
        ]);

        return response()->json($this->uploads->store($actor, $request->file('file')), 201);
    }

    /** A non-empty string that cleans down to nothing, e.g. "<b></b>". */
    private function isMarkupOnly(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && PlainText::clean($value) === '';
    }

    /** 403 unless the caller (member owner or the guest's own token) owns the session. */
    private function authorizeSession(Request $request, ChatSession $session): void
    {
        abort_unless(ChatActor::from($request)?->canAccess($session), 403, 'Unauthorized');
    }
}
