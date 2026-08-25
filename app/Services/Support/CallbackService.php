<?php

namespace App\Services\Support;

use App\Models\CallbackRequest;
use App\Models\User;
use App\Services\SendSMS;
use App\Services\Support\Concerns\GuardsSmsSending;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class CallbackService
{
    use GuardsSmsSending;

    public const SLOTS = ['morning', 'noon', 'evening'];

    public const STATES = ['now', 'wait', 'miss', 'done'];

    public function createForPatient(User $patient, array $data): CallbackRequest
    {
        $callback = CallbackRequest::create([
            'user_id' => $patient->id,
            'phone' => $data['phone'],
            'slot' => $data['slot'],
            'topic' => $data['topic'] ?? null,
            'status' => 'now',
        ]);

        Log::info('support.callback_requested', ['callback_id' => $callback->id, 'user_id' => $patient->id]);

        $this->sendSmsSafely(fn () => (new SendSMS)->supportCallbackRequest(
            $patient->phone_number,
            $patient->first_name ?? 'کاربر'
        ));

        return $callback;
    }

    public function presentForPatient(CallbackRequest $callback): array
    {
        return [
            'id' => (string) $callback->id,
            'phone' => $callback->phone,
            'slot' => $callback->slot,
            'status' => $callback->status,
            'createdAt' => $callback->created_at->toIso8601String(),
        ];
    }

    public function listForAdmin(): LengthAwarePaginator
    {
        return CallbackRequest::with('user')->orderByDesc('created_at')->paginate(20);
    }

    public function updateState(CallbackRequest $callback, string $state): CallbackRequest
    {
        $callback->status = $state;
        $callback->save();

        return $callback->fresh();
    }

    public function presentForAdmin(CallbackRequest $callback): array
    {
        $callback->loadMissing('user');

        return [
            'id' => $callback->id,
            'patientId' => $callback->user_id,
            'patientName' => $callback->user ? $this->fullName($callback->user) : null,
            'phone' => $callback->phone,
            'window' => $callback->slot,
            'topic' => $callback->topic,
            'state' => $callback->status,
            'requestedAt' => $callback->created_at->toIso8601String(),
        ];
    }

    private function fullName(User $user): ?string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }
}
