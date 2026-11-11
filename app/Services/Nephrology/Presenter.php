<?php

namespace App\Services\Nephrology;

use App\Models\LabTest;
use App\Models\NephroConsultation;
use App\Models\NephrologistProfile;
use App\Models\NephroMessage;
use App\Models\NephroPayout;
use App\Models\NephroReview;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\JalaliDate;
use App\Support\PrivateFiles;
use Illuminate\Support\Collection;

/** The JSON shapes the apps read. Money is IRR; dates are ISO-8601 (the clients format them). */
class Presenter
{
    public static function doctorName(?User $user): string
    {
        $name = trim(($user?->first_name ?? '').' '.($user?->last_name ?? ''));

        return $name === '' ? 'پزشک' : 'دکتر '.$name;
    }

    /** First letters of first and last name, for the avatar circle. */
    public static function initials(?string $first, ?string $last): string
    {
        $one = fn (?string $s) => $s ? mb_substr(trim($s), 0, 1) : '';

        return trim($one($first).'‌'.$one($last), '‌') ?: 'د';
    }

    public static function age(?int $birthYear): ?int
    {
        if (! $birthYear) {
            return null;
        }

        [$jy] = JalaliDate::toJalali(now());

        return max(0, $jy - $birthYear);
    }

    /**
     * Public card/profile of a doctor. Never includes identity numbers, the IBAN or the uploaded
     * documents; the photo is a signed URL that stays identical within the hour so it caches.
     *
     * @param  array{avg:?float,count:int}|null  $rating
     */
    public static function doctor(NephrologistProfile $p, ?array $rating = null, ?int $openCount = null): array
    {
        $user = $p->user;
        $photoId = ($p->documents ?? [])['photo'] ?? null;
        $photo = $photoId ? StoredFile::find($photoId) : null;

        return [
            'id' => $user->id,
            'name' => self::doctorName($user),
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'initials' => self::initials($user->first_name, $user->last_name),
            'photoUrl' => $photo ? PrivateFiles::signedUrl($photo->url, null) : null,
            'specialty' => $p->base_specialty === 'ped' ? 'فوق‌تخصص نفرولوژی کودکان' : 'فوق‌تخصص نفرولوژی بزرگسالان',
            'experienceYears' => $p->graduation_year ? max(0, (int) self::jalaliYear() - $p->graduation_year) : null,
            'city' => $p->city,
            'workplace' => $p->workplace,
            'university' => $p->university,
            'councilNumber' => $p->council_number,
            'focus' => $p->focus ?? [],
            'bio' => $p->bio,
            'fee' => $p->fee,
            'workDays' => $p->work_days ?? [],
            'hoursFrom' => $p->hours_from,
            'hoursTo' => $p->hours_to,
            'replyDeadlineHours' => (int) config('nephrology.reply_deadline_hours'),
            'chatOpenHours' => (int) config('nephrology.chat_open_hours'),
            'isAccepting' => $p->is_accepting && ! $p->onVacation(),
            'atCapacity' => $openCount !== null && $openCount >= $p->capacity,
            'rating' => $rating['avg'] ?? null,
            'ratingCount' => $rating['count'] ?? 0,
        ];
    }

    private static function jalaliYear(): int
    {
        return JalaliDate::toJalali(now())[0];
    }

    public static function message(NephroMessage $m): array
    {
        $file = $m->relationLoaded('file') ? $m->file : $m->file()->first();

        return [
            'id' => $m->id,
            'consultationId' => $m->consultation_id,
            'sender' => $m->sender_role, // patient | doctor | system
            'kind' => $m->kind, // text | file | action | lab
            'body' => $m->body,
            'file' => $file ? [
                'id' => $file->id,
                'name' => $file->originalFileName ?? $file->fileName,
                'url' => PrivateFiles::signedUrl($file->url, 60),
            ] : null,
            'meta' => $m->meta,
            'createdAt' => $m->created_at?->toIso8601String(),
        ];
    }

    /** What the patient sees about a consultation. */
    public static function patientConsultation(NephroConsultation $c, ?NephrologistProfile $profile = null): array
    {
        $doctor = $c->nephrologist;

        return array_merge(self::consultationCore($c), [
            'doctor' => [
                'id' => $doctor?->id,
                'name' => self::doctorName($doctor),
                'initials' => self::initials($doctor?->first_name, $doctor?->last_name),
                'specialty' => $profile && $profile->base_specialty === 'ped' ? 'فوق‌تخصص نفرولوژی کودکان' : 'فوق‌تخصص نفرولوژی بزرگسالان',
            ],
            'unread' => $c->patient_unread,
            'summary' => $c->summary,
            'review' => $c->relationLoaded('review') && $c->review ? self::review($c->review) : null,
        ]);
    }

    /** What the doctor sees about a consultation (plus the earning, never the patient's wallet). */
    public static function doctorConsultation(NephroConsultation $c): array
    {
        $patient = $c->patient;

        return array_merge(self::consultationCore($c), [
            'patient' => [
                'id' => $c->patient_id,
                'name' => $c->patient_name,
                'initials' => self::initials($c->patient_name, null),
                'age' => self::age($c->patient_birth_year) ?? ($c->relation === 'self' ? $patient?->age : null),
                'sex' => $c->patient_sex ?? ($c->relation === 'self' ? $patient?->gender : null),
                'relation' => $c->relation,
                'code' => 'GR-'.str_pad((string) $c->patient_id, 5, '0', STR_PAD_LEFT),
            ],
            'unread' => $c->doctor_unread,
            'doctorAmount' => $c->doctor_amount,
            'summary' => $c->summary,
        ]);
    }

    private static function consultationCore(NephroConsultation $c): array
    {
        return [
            'id' => $c->id,
            'status' => $c->status,
            'refundReason' => $c->refund_reason,
            'endedBy' => $c->ended_by,
            'fee' => $c->fee,
            'reason' => $c->reason,
            'symptoms' => $c->symptoms ?? [],
            'symptomDuration' => $c->symptom_duration,
            'diagnoses' => $c->diagnoses ?? [],
            'medications' => $c->medications,
            'notes' => $c->notes,
            'patientName' => $c->patient_name,
            'relation' => $c->relation,
            'replyDueAt' => $c->reply_due_at?->toIso8601String(),
            'firstRepliedAt' => $c->first_replied_at?->toIso8601String(),
            'expiresAt' => $c->expires_at?->toIso8601String(),
            'endedAt' => $c->ended_at?->toIso8601String(),
            'refundedAt' => $c->refunded_at?->toIso8601String(),
            'createdAt' => $c->created_at?->toIso8601String(),
            'lastMessageAt' => $c->last_message_at?->toIso8601String(),
        ];
    }

    public static function review(NephroReview $r, bool $withPatient = false): array
    {
        $out = [
            'id' => $r->id,
            'consultationId' => $r->consultation_id,
            'rating' => $r->rating,
            'text' => $r->text,
            'reply' => $r->reply,
            'createdAt' => $r->created_at?->toIso8601String(),
        ];

        if ($withPatient) {
            // Only the initials of the patient's name, as promised on the doctor's reviews page.
            $name = (string) ($r->relationLoaded('consultation') ? $r->consultation?->patient_name : '');
            $parts = preg_split('/\s+/u', trim($name)) ?: [];
            $out['who'] = implode('. ', array_map(fn ($p) => mb_substr($p, 0, 1), array_filter($parts))).($parts ? '.' : '');
        }

        return $out;
    }

    public static function payout(NephroPayout $p): array
    {
        return [
            'id' => $p->id,
            'amount' => $p->amount,
            'status' => $p->status,
            'iban' => self::maskIban($p->iban),
            'reference' => $p->reference,
            'note' => $p->note,
            'requestedAt' => $p->requested_at?->toIso8601String(),
            'processedAt' => $p->processed_at?->toIso8601String(),
        ];
    }

    public static function maskIban(?string $iban): ?string
    {
        if (! $iban) {
            return null;
        }

        return 'IR'.str_repeat('•', max(0, strlen($iban) - 4)).substr($iban, -4);
    }

    /** One shared lab result as the doctor reads it. */
    public static function lab(LabTest $t): array
    {
        return [
            'id' => $t->id,
            'date' => $t->created_at?->toIso8601String(),
            'gfr' => $t->gfr,
            'creatinine' => $t->creatinine,
            'uacr' => $t->uacr,
            'urineAlbumin' => $t->urine_albumin,
            'albumin' => $t->albumin,
            'calcium' => $t->calcium,
            'phosphorous' => $t->phosphorous,
            'bicarbonate' => $t->b_carbonate,
            'stage' => $t->stage,
        ];
    }

    /** KDIGO G (by eGFR) and A (by UACR) category indexes: G1..G5 → 0..5 over [G1,G2,G3a,G3b,G4,G5], A1..A3 → 0..2. */
    public static function kdigo(?float $gfr, ?float $uacr): array
    {
        $g = null;
        if ($gfr !== null) {
            $g = match (true) {
                $gfr >= 90 => 0,
                $gfr >= 60 => 1,
                $gfr >= 45 => 2,
                $gfr >= 30 => 3,
                $gfr >= 15 => 4,
                default => 5,
            };
        }
        $a = null;
        if ($uacr !== null) {
            $a = $uacr < 30 ? 0 : ($uacr <= 300 ? 1 : 2);
        }

        return ['g' => $g, 'a' => $a];
    }

    public static function collectIds(Collection $c): array
    {
        return $c->pluck('id')->values()->all();
    }
}
