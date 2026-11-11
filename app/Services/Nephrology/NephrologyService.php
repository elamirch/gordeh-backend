<?php

namespace App\Services\Nephrology;

use App\Events\NephroConsultationUpdated;
use App\Events\NephroMessageSent;
use App\Models\LabTest;
use App\Models\NephroConsultation;
use App\Models\NephrologistProfile;
use App\Models\NephroMessage;
use App\Models\NephroPayout;
use App\Models\NephroReview;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The nephrology text consultation: paying for it, the conversation rules, refunds and what the
 * doctor earns.
 *
 * The patient pays exactly the doctor's fee from the wallet. Gordeh keeps `commission_percent`
 * of it, frozen on the consultation at payment time; the rest is the doctor's earning and counts
 * as withdrawable once the consultation has ended. A consultation the doctor never answers (or
 * declines, or the patient cancels before any reply) goes back to the wallet in full.
 */
class NephrologyService
{
    public const FILE_CATEGORY = 'nephro-consultation';

    public function __construct(private ?WalletService $wallet = null, private ?NephrologyNotifier $notifier = null)
    {
        $this->wallet ??= new WalletService;
        $this->notifier ??= new NephrologyNotifier;
    }

    /** @return array{commission:int, doctor:int} */
    public static function split(int $fee, ?int $percent = null): array
    {
        $percent ??= (int) config('nephrology.commission_percent');
        $commission = (int) round($fee * $percent / 100);

        return ['commission' => $commission, 'doctor' => $fee - $commission];
    }

    public static function openCount(int $doctorId): int
    {
        return NephroConsultation::where('nephrologist_id', $doctorId)->whereIn('status', NephroConsultation::OPEN_STATUSES)->count();
    }

    // --- Booking -------------------------------------------------------------

    public function book(User $patient, int $doctorId, array $data): NephroConsultation
    {
        $consultation = DB::transaction(function () use ($patient, $doctorId, $data) {
            // Locking the profile serialises concurrent bookings, so the capacity check holds.
            $profile = NephrologistProfile::where('user_id', $doctorId)->lockForUpdate()->first();

            if (! $profile || ! $profile->isApproved() || ! $profile->is_accepting || $profile->onVacation() || $profile->fee < (int) config('nephrology.fee.min')) {
                throw ValidationException::withMessages(['doctor_id' => ['این پزشک در حال حاضر مشاورهٔ جدید نمی‌پذیرد.']]);
            }
            if ($profile->user_id === $patient->id) {
                throw ValidationException::withMessages(['doctor_id' => ['نمی‌توانید برای خودتان مشاوره رزرو کنید.']]);
            }
            if (isset($data['expected_fee']) && (int) $data['expected_fee'] !== $profile->fee) {
                throw ValidationException::withMessages(['expected_fee' => ['تعرفهٔ پزشک تغییر کرده است. صفحه را تازه کنید و دوباره تلاش کنید.']]);
            }
            if (self::openCount($doctorId) >= $profile->capacity) {
                throw ValidationException::withMessages(['doctor_id' => ['ظرفیت پذیرش این پزشک فعلاً پر است. بعداً دوباره تلاش کنید یا پزشک دیگری را انتخاب کنید.']]);
            }
            $alreadyOpen = NephroConsultation::where('patient_id', $patient->id)->where('nephrologist_id', $doctorId)
                ->whereIn('status', NephroConsultation::OPEN_STATUSES)->exists();
            if ($alreadyOpen) {
                throw ValidationException::withMessages(['doctor_id' => ['یک گفت‌وگوی باز با این پزشک دارید. ادامهٔ همان گفت‌وگو را از «مشاوره‌های من» باز کنید.']]);
            }

            // Only the patient's own records can be shared.
            $labIds = LabTest::where('user_id', $patient->id)->whereIn('id', $data['lab_test_ids'] ?? [])->pluck('id')->all();
            $files = StoredFile::where('user_id', $patient->id)->where('category', self::FILE_CATEGORY)
                ->whereIn('id', $data['file_ids'] ?? [])->get();

            $split = self::split($profile->fee);

            $debit = $this->wallet->debit(
                $patient,
                $profile->fee,
                'nephrology',
                'مشاورهٔ متنی نفرولوژی با '.Presenter::doctorName($profile->user),
                ['doctor_id' => $doctorId],
            );

            $now = now();
            $c = NephroConsultation::create([
                'patient_id' => $patient->id,
                'nephrologist_id' => $doctorId,
                'status' => 'awaiting_reply',
                'patient_name' => $data['patient_name'],
                'patient_birth_year' => $data['patient_birth_year'] ?? null,
                'patient_sex' => $data['patient_sex'] ?? null,
                'relation' => $data['relation'] ?? 'self',
                'reason' => $data['reason'],
                'symptoms' => $data['symptoms'] ?? [],
                'symptom_duration' => $data['symptom_duration'] ?? null,
                'diagnoses' => $data['diagnoses'] ?? [],
                'medications' => $data['medications'] ?? null,
                'notes' => $data['notes'] ?? null,
                'lab_test_ids' => $labIds,
                'file_ids' => $files->pluck('id')->all(),
                'consent_at' => $now,
                'fee' => $profile->fee,
                'commission_percent' => (int) config('nephrology.commission_percent'),
                'commission_amount' => $split['commission'],
                'doctor_amount' => $split['doctor'],
                'wallet_transaction_id' => $debit->id,
                'reply_due_at' => $now->copy()->addHours((int) config('nephrology.reply_deadline_hours')),
                'doctor_unread' => 1,
                'last_message_at' => $now,
            ]);

            $this->addMessage($c, null, 'system', 'action', 'مشاوره آغاز شد. پزشک تا '.(int) config('nephrology.reply_deadline_hours').' ساعت آینده پاسخ می‌دهد.');
            if ($labIds) {
                $this->addMessage($c, $patient->id, 'patient', 'lab', 'نتایج آزمایش ثبت‌شده در گرده', ['lab_test_ids' => $labIds]);
            }
            foreach ($files as $file) {
                $this->addMessage($c, $patient->id, 'patient', 'file', null, ['initial' => true], $file->id);
            }

            return $c;
        });

        $consultation->load(['patient', 'nephrologist']);
        $this->notifier->newConsultation($consultation);
        $this->ping($consultation->nephrologist_id, $consultation, 'new');

        return $consultation;
    }

    // --- Conversation --------------------------------------------------------

    public function postMessage(NephroConsultation $consultation, User $sender, string $role, string $kind, ?string $body, ?StoredFile $file = null, ?array $meta = null): NephroMessage
    {
        $firstReply = false;

        $message = DB::transaction(function () use ($consultation, $sender, $role, $kind, $body, $file, $meta, &$firstReply) {
            $c = NephroConsultation::whereKey($consultation->id)->lockForUpdate()->firstOrFail();

            if (! $c->isOpen() || ($c->status === 'active' && $c->expires_at && $c->expires_at->isPast())) {
                throw ValidationException::withMessages(['message' => ['این گفت‌وگو بسته شده و پیام جدید پذیرفته نمی‌شود.']]);
            }

            if ($role === 'patient' && $c->status === 'awaiting_reply' && $kind === 'text') {
                $sent = NephroMessage::where('consultation_id', $c->id)->where('sender_role', 'patient')->where('kind', 'text')->count();
                if ($sent >= (int) config('nephrology.max_messages_before_reply')) {
                    throw ValidationException::withMessages(['message' => ['تا پاسخ پزشک، حداکثر '.(int) config('nephrology.max_messages_before_reply').' پیام می‌توانید بفرستید. لطفاً منتظر پاسخ بمانید.']]);
                }
            }

            $changes = ['last_message_at' => now()];

            if ($role === 'doctor') {
                if ($c->status === 'awaiting_reply') {
                    if (! in_array($kind, ['text', 'file'], true)) {
                        throw ValidationException::withMessages(['message' => ['ابتدا برای بیمار پیام بنویسید.']]);
                    }
                    $changes += [
                        'status' => 'active',
                        'first_replied_at' => now(),
                        'expires_at' => now()->addHours((int) config('nephrology.chat_open_hours')),
                        'reply_due_at' => null,
                    ];
                    $firstReply = true;
                }
                $changes['patient_unread'] = $c->patient_unread + 1;
            } else {
                $changes['doctor_unread'] = $c->doctor_unread + 1;
            }

            $c->update($changes);
            $consultation->setRawAttributes($c->fresh()->getAttributes(), true);

            return $this->addMessage($c, $sender->id, $role, $kind, $body, $meta, $file?->id);
        });

        $this->safely(fn () => broadcast(new NephroMessageSent($message))->toOthers());
        $other = $role === 'doctor' ? $consultation->patient_id : $consultation->nephrologist_id;
        $this->ping($other, $consultation, 'message');

        if ($firstReply) {
            $consultation->loadMissing(['patient', 'nephrologist']);
            $this->notifier->doctorReplied($consultation);
        }

        return $message;
    }

    public function markRead(NephroConsultation $c, string $role): void
    {
        $column = $role === 'doctor' ? 'doctor_unread' : 'patient_unread';
        if ($c->{$column} > 0) {
            NephroConsultation::whereKey($c->id)->update([$column => 0]);
            $c->{$column} = 0;
        }
    }

    // --- Ending, refunds -----------------------------------------------------

    /** The doctor closes an active consultation and publishes the summary; the earning becomes withdrawable. */
    public function end(NephroConsultation $consultation, array $summary): NephroConsultation
    {
        $ended = DB::transaction(function () use ($consultation, $summary) {
            $c = NephroConsultation::whereKey($consultation->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== 'active') {
                throw ValidationException::withMessages(['status' => ['فقط گفت‌وگوی فعال را می‌توان بست.']]);
            }

            $c->update([
                'status' => 'ended',
                'ended_by' => 'doctor',
                'ended_at' => now(),
                'summary' => $summary,
                'patient_unread' => $c->patient_unread + 1,
            ]);
            $this->addMessage($c, $c->nephrologist_id, 'system', 'action', 'پزشک مشاوره را بست و خلاصهٔ آن را منتشر کرد.');

            return $c->fresh();
        });

        $ended->load(['patient', 'nephrologist']);
        $this->notifier->summaryPublished($ended);
        $this->broadcastLast($ended);
        $this->ping($ended->patient_id, $ended, 'ended');

        return $ended;
    }

    /** A conversation whose window ran out closes by itself; the doctor keeps the earning. */
    public function expire(NephroConsultation $consultation): bool
    {
        $done = DB::transaction(function () use ($consultation) {
            $c = NephroConsultation::whereKey($consultation->id)->lockForUpdate()->first();
            if (! $c || $c->status !== 'active' || ! $c->expires_at || $c->expires_at->isFuture()) {
                return null;
            }
            $c->update(['status' => 'ended', 'ended_by' => 'system', 'ended_at' => now(), 'patient_unread' => $c->patient_unread + 1]);
            $this->addMessage($c, null, 'system', 'action', 'مدت باز بودن گفت‌وگو به پایان رسید.');

            return $c->fresh();
        });

        if ($done) {
            $this->broadcastLast($done);
            $this->ping($done->patient_id, $done, 'ended');
            $this->ping($done->nephrologist_id, $done, 'ended');
        }

        return (bool) $done;
    }

    /**
     * Gives the fee back to the wallet. Only possible before the doctor has written anything:
     * once they have engaged, the consultation is theirs to finish.
     */
    public function refund(NephroConsultation $consultation, string $reason): bool
    {
        $notes = [
            'no_reply' => 'پزشک در مهلت تعیین‌شده پاسخ نداد؛ هزینه به کیف پول شما بازگشت.',
            'patient_cancelled' => 'مشاوره پیش از پاسخ پزشک لغو شد؛ هزینه به کیف پول بازگشت.',
            'doctor_declined' => 'پزشک نتوانست این مشاوره را بپذیرد؛ هزینه به کیف پول شما بازگشت.',
        ];

        $done = DB::transaction(function () use ($consultation, $reason, $notes) {
            $c = NephroConsultation::whereKey($consultation->id)->lockForUpdate()->first();
            if (! $c || $c->status !== 'awaiting_reply') {
                return null;
            }

            if ($c->walletTransaction) {
                $this->wallet->refund($c->walletTransaction, 'بازگشت هزینهٔ مشاورهٔ نفرولوژی');
            }
            $c->update(['status' => 'refunded', 'refund_reason' => $reason, 'refunded_at' => now(), 'reply_due_at' => null, 'patient_unread' => $c->patient_unread + 1]);
            $this->addMessage($c, null, 'system', 'action', $notes[$reason] ?? $notes['no_reply']);

            return $c->fresh();
        });

        if ($done) {
            $done->load(['patient', 'nephrologist']);
            $this->notifier->refunded($done);
            $this->broadcastLast($done);
            $this->ping($done->patient_id, $done, 'refunded');
            $this->ping($done->nephrologist_id, $done, 'refunded');
        }

        return (bool) $done;
    }

    // --- Reviews -------------------------------------------------------------

    public function review(NephroConsultation $c, int $rating, ?string $text): NephroReview
    {
        if ($c->status !== 'ended') {
            throw ValidationException::withMessages(['review' => ['بعد از پایان مشاوره می‌توانید نظر ثبت کنید.']]);
        }
        if ($c->review()->exists()) {
            throw ValidationException::withMessages(['review' => ['برای این مشاوره قبلاً نظر ثبت کرده‌اید.']]);
        }

        return NephroReview::create([
            'consultation_id' => $c->id,
            'patient_id' => $c->patient_id,
            'nephrologist_id' => $c->nephrologist_id,
            'rating' => $rating,
            'text' => $text,
        ]);
    }

    /** @return array<int, array{avg:?float,count:int}> keyed by doctor id */
    public static function ratings(array $doctorIds): array
    {
        $rows = NephroReview::whereIn('nephrologist_id', $doctorIds)
            ->selectRaw('nephrologist_id, AVG(rating) as avg_rating, COUNT(*) as n')
            ->groupBy('nephrologist_id')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->nephrologist_id] = ['avg' => round((float) $r->avg_rating, 1), 'count' => (int) $r->n];
        }

        return $out;
    }

    // --- Money ---------------------------------------------------------------

    /** Everything on the doctor's earnings page (IRR). */
    public function earnings(User $doctor): array
    {
        $ended = NephroConsultation::where('nephrologist_id', $doctor->id)->where('status', 'ended');
        $earned = (int) (clone $ended)->sum('doctor_amount');
        $gross = (int) (clone $ended)->sum('fee');
        $commission = (int) (clone $ended)->sum('commission_amount');
        $recent = (int) (clone $ended)->where('ended_at', '>=', now()->subDays(30))->sum('doctor_amount');
        $recentCount = (clone $ended)->where('ended_at', '>=', now()->subDays(30))->count();
        $inProgress = (int) NephroConsultation::where('nephrologist_id', $doctor->id)->whereIn('status', NephroConsultation::OPEN_STATUSES)->sum('doctor_amount');
        $paid = (int) NephroPayout::where('nephrologist_id', $doctor->id)->where('status', 'paid')->sum('amount');
        $requested = (int) NephroPayout::where('nephrologist_id', $doctor->id)->where('status', 'requested')->sum('amount');

        return [
            'commissionPercent' => (int) config('nephrology.commission_percent'),
            'minPayout' => (int) config('nephrology.min_payout'),
            'earned' => $earned,
            'gross' => $gross,
            'commission' => $commission,
            'available' => max(0, $earned - $paid - $requested),
            'inProgress' => $inProgress,
            'requested' => $requested,
            'paid' => $paid,
            'last30Days' => $recent,
            'last30DaysCount' => $recentCount,
            'completedCount' => (clone $ended)->count(),
        ];
    }

    public function requestPayout(User $doctor, int $amount): NephroPayout
    {
        return DB::transaction(function () use ($doctor, $amount) {
            $profile = NephrologistProfile::where('user_id', $doctor->id)->lockForUpdate()->first();
            if (! $profile || ! $profile->iban) {
                throw ValidationException::withMessages(['amount' => ['ابتدا شمارهٔ شبا را در تنظیمات ثبت کنید.']]);
            }
            if ($amount < (int) config('nephrology.min_payout')) {
                throw ValidationException::withMessages(['amount' => ['حداقل مبلغ درخواست تسویه '.number_format((int) config('nephrology.min_payout') / 10).' تومان است.']]);
            }
            if ($amount > $this->earnings($doctor)['available']) {
                throw ValidationException::withMessages(['amount' => ['مبلغ بیشتر از موجودی قابل برداشت است.']]);
            }

            return NephroPayout::create([
                'nephrologist_id' => $doctor->id,
                'amount' => $amount,
                'iban' => $profile->iban,
                'status' => 'requested',
                'requested_at' => now(),
            ]);
        });
    }

    // --- Internals -----------------------------------------------------------

    private function addMessage(NephroConsultation $c, ?int $senderId, string $role, string $kind, ?string $body, ?array $meta = null, ?int $fileId = null): NephroMessage
    {
        return NephroMessage::create([
            'consultation_id' => $c->id,
            'sender_id' => $senderId,
            'sender_role' => $role,
            'kind' => $kind,
            'body' => $body,
            'stored_file_id' => $fileId,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    private function broadcastLast(NephroConsultation $c): void
    {
        $last = NephroMessage::where('consultation_id', $c->id)->latest('id')->first();
        if ($last) {
            $this->safely(fn () => broadcast(new NephroMessageSent($last)));
        }
    }

    private function ping(int $userId, NephroConsultation $c, string $reason): void
    {
        $this->safely(fn () => broadcast(new NephroConsultationUpdated($userId, $c->id, $reason)));
    }

    /**
     * Realtime is a nicety: everything is already saved, and the apps poll as a fallback, so a
     * socket server that is down must never turn a successful request into a 500.
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('nephrology.broadcast_failed', ['error' => $e->getMessage()]);
        }
    }
}
