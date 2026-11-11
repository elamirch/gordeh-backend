<?php

namespace App\Http\Controllers\Nephrologist;

use App\Http\Controllers\Controller;
use App\Models\LabTest;
use App\Models\NephroConsultation;
use App\Models\NephroPayout;
use App\Models\NephroReview;
use App\Services\Nephrology\NephrologyService;
use App\Services\Nephrology\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The doctor's own panel: profile & availability, dashboard, patients, earnings, reviews. */
class PanelController extends Controller
{
    public function __construct(private NephrologyService $service) {}

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $p = $user->nephrologistProfile;

        return response()->json([
            'id' => $user->id,
            'name' => Presenter::doctorName($user),
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'initials' => Presenter::initials($user->first_name, $user->last_name),
            'status' => $p->status,
            'isAccepting' => $p->is_accepting && ! $p->onVacation(),
            'fee' => $p->fee,
            'workDays' => $p->work_days ?? [],
            'hoursFrom' => $p->hours_from,
            'hoursTo' => $p->hours_to,
            'capacity' => $p->capacity,
            'openCount' => NephrologyService::openCount($user->id),
            'unread' => (int) NephroConsultation::where('nephrologist_id', $user->id)->whereIn('status', NephroConsultation::OPEN_STATUSES)->sum('doctor_unread'),
            'vacationFrom' => $p->vacation_from?->toDateString(),
            'vacationTo' => $p->vacation_to?->toDateString(),
            'bio' => $p->bio,
            'focus' => $p->focus ?? [],
            'city' => $p->city,
            'iban' => Presenter::maskIban($p->iban),
            'hasIban' => (bool) $p->iban,
            'councilNumber' => $p->council_number,
            'commissionPercent' => (int) config('nephrology.commission_percent'),
            'feeLimits' => config('nephrology.fee'),
            'replyDeadlineHours' => (int) config('nephrology.reply_deadline_hours'),
            'focusOptions' => config('nephrology.focus'),
        ]);
    }

    /** Fee, hours, capacity, the on/off switch, leave, bio, IBAN. A new fee applies to new consultations only. */
    public function updateProfile(Request $request): JsonResponse
    {
        $profile = $request->user()->nephrologistProfile;

        $data = $request->validate([
            'fee' => ['sometimes', 'integer', 'min:'.config('nephrology.fee.min'), 'max:'.config('nephrology.fee.max')],
            'work_days' => ['sometimes', 'array'],
            'work_days.*' => ['integer', 'between:0,6'],
            'hours_from' => ['sometimes', 'date_format:H:i'],
            'hours_to' => ['sometimes', 'date_format:H:i'],
            'capacity' => ['sometimes', 'integer', 'between:1,50'],
            'is_accepting' => ['sometimes', 'boolean'],
            'vacation_from' => ['sometimes', 'nullable', 'date'],
            'vacation_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:vacation_from'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:400'],
            'focus' => ['sometimes', 'array', 'min:1', 'max:12'],
            'focus.*' => ['string', 'in:'.implode(',', config('nephrology.focus'))],
            'iban' => ['sometimes', 'regex:/^\d{24}$/'],
        ]);

        if (! $profile->isApproved() && isset($data['is_accepting']) && $data['is_accepting']) {
            throw ValidationException::withMessages(['is_accepting' => ['حساب شما فعال نیست.']]);
        }

        $profile->update($data);

        return $this->me($request);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $id = $request->user()->id;

        $open = NephroConsultation::with('patient')->where('nephrologist_id', $id)->whereIn('status', NephroConsultation::OPEN_STATUSES)
            ->orderByRaw("CASE status WHEN 'awaiting_reply' THEN 0 ELSE 1 END")->orderBy('reply_due_at')->orderByDesc('last_message_at')->limit(20)->get();

        $awaiting = $open->where('status', 'awaiting_reply');
        $rating = NephrologyService::ratings([$id])[$id] ?? ['avg' => null, 'count' => 0];
        $earnings = $this->service->earnings($request->user());

        // Shared lab results of open consultations that are outside the usual range.
        $alerts = [];
        foreach ($open as $c) {
            $lab = LabTest::where('user_id', $c->patient_id)->whereIn('id', $c->lab_test_ids ?? [])->orderByDesc('created_at')->first();
            if (! $lab) {
                continue;
            }
            if ($lab->gfr !== null && $lab->gfr < 60) {
                $alerts[] = ['consultationId' => $c->id, 'name' => $c->patient_name, 'what' => 'eGFR '.round($lab->gfr), 'level' => $lab->gfr < 30 ? 'high' : 'mid', 'date' => $lab->created_at?->toIso8601String()];
            }
            if ($lab->uacr !== null && $lab->uacr >= 30) {
                $alerts[] = ['consultationId' => $c->id, 'name' => $c->patient_name, 'what' => 'UACR '.round($lab->uacr), 'level' => $lab->uacr > 300 ? 'high' : 'mid', 'date' => $lab->created_at?->toIso8601String()];
            }
        }

        return response()->json([
            'kpis' => [
                'openCount' => $open->count(),
                'awaitingCount' => $awaiting->count(),
                'nearDeadlineCount' => $awaiting->filter(fn ($c) => $c->reply_due_at && $c->reply_due_at->lt(now()->addHours(3)))->count(),
                'completedLast30Days' => $earnings['last30DaysCount'],
                'rating' => $rating['avg'],
                'ratingCount' => $rating['count'],
                'available' => $earnings['available'],
                'inProgress' => $earnings['inProgress'],
            ],
            'queue' => $open->map(fn (NephroConsultation $c) => Presenter::doctorConsultation($c))->values(),
            'alerts' => $alerts,
        ]);
    }

    /** Everyone this doctor has (or had) a paid consultation with, newest first. */
    public function patients(Request $request): JsonResponse
    {
        $rows = NephroConsultation::where('nephrologist_id', $request->user()->id)->where('status', '!=', 'refunded')
            ->orderByDesc('id')->get()->groupBy('patient_id');

        $out = $rows->map(function ($group) {
            $latest = $group->first();
            $labIds = $group->pluck('lab_test_ids')->flatten()->filter()->unique()->all();
            $labs = $labIds ? LabTest::where('user_id', $latest->patient_id)->whereIn('id', $labIds)->orderByDesc('created_at')->limit(2)->get() : collect();
            $lab = $labs->first();
            $prev = $labs->get(1);
            $trend = ($lab?->gfr !== null && $prev?->gfr !== null) ? round($lab->gfr - $prev->gfr) : null;

            return [
                'patientId' => $latest->patient_id,
                'name' => $latest->patient_name,
                'consultationId' => $latest->id,
                'consultationCount' => $group->count(),
                'status' => $latest->status,
                'lastAt' => ($latest->last_message_at ?? $latest->created_at)?->toIso8601String(),
                'gfr' => $lab?->gfr !== null ? round($lab->gfr) : null,
                'trend' => $trend,
                'kdigo' => Presenter::kdigo($lab?->gfr, $lab?->uacr),
                'hasAccess' => $latest->doctorHasAccess(),
            ];
        })->values();

        return response()->json($out);
    }

    public function earnings(Request $request): JsonResponse
    {
        $user = $request->user();
        $payouts = NephroPayout::where('nephrologist_id', $user->id)->orderByDesc('id')->limit(50)->get();
        $recent = NephroConsultation::where('nephrologist_id', $user->id)->where('status', 'ended')->orderByDesc('ended_at')->limit(30)->get()
            ->map(fn (NephroConsultation $c) => [
                'id' => $c->id,
                'patientName' => $c->patient_name,
                'endedAt' => $c->ended_at?->toIso8601String(),
                'fee' => $c->fee,
                'commission' => $c->commission_amount,
                'net' => $c->doctor_amount,
            ]);

        return response()->json([
            'summary' => $this->service->earnings($user),
            'payouts' => $payouts->map(fn (NephroPayout $p) => Presenter::payout($p))->values(),
            'recent' => $recent,
            'iban' => Presenter::maskIban($user->nephrologistProfile->iban),
        ]);
    }

    public function requestPayout(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        return response()->json(Presenter::payout($this->service->requestPayout($request->user(), (int) $data['amount'])), 201);
    }

    public function reviews(Request $request): JsonResponse
    {
        $id = $request->user()->id;
        $rating = NephrologyService::ratings([$id])[$id] ?? ['avg' => null, 'count' => 0];
        $dist = NephroReview::where('nephrologist_id', $id)->selectRaw('rating, COUNT(*) as n')->groupBy('rating')->pluck('n', 'rating');

        return response()->json([
            'average' => $rating['avg'],
            'count' => $rating['count'],
            'distribution' => collect([5, 4, 3, 2, 1])->map(fn ($r) => ['rating' => $r, 'count' => (int) ($dist[$r] ?? 0)])->values(),
            'items' => NephroReview::with('consultation')->where('nephrologist_id', $id)->latest()->limit(50)->get()
                ->map(fn (NephroReview $r) => Presenter::review($r, true))->values(),
        ]);
    }

    public function replyReview(Request $request, NephroReview $review): JsonResponse
    {
        abort_unless((int) $review->nephrologist_id === (int) $request->user()->id, 404);
        $data = $request->validate(['reply' => ['required', 'string', 'min:2', 'max:500']]);

        if ($review->reply) {
            throw ValidationException::withMessages(['reply' => ['به این نظر قبلاً پاسخ داده‌اید.']]);
        }
        $review->update(['reply' => trim($data['reply']), 'replied_at' => now()]);

        return response()->json(Presenter::review($review->load('consultation'), true));
    }
}
