<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NephroConsultation;
use App\Models\NephrologistProfile;
use App\Models\NephroPayout;
use App\Models\StoredFile;
use App\Services\Nephrology\NephrologyNotifier;
use App\Services\Nephrology\NephrologyService;
use App\Services\Nephrology\Presenter;
use App\Support\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin: review doctor applications, suspend doctors, pay out their earnings, see the numbers. */
class NephrologistController extends Controller
{
    public function overview(): JsonResponse
    {
        $ended = NephroConsultation::where('status', 'ended');

        return response()->json([
            'commissionPercent' => (int) config('nephrology.commission_percent'),
            'pendingApplications' => NephrologistProfile::where('status', 'pending')->count(),
            'approvedDoctors' => NephrologistProfile::where('status', 'approved')->count(),
            'openConsultations' => NephroConsultation::whereIn('status', NephroConsultation::OPEN_STATUSES)->count(),
            'refundedConsultations' => NephroConsultation::where('status', 'refunded')->count(),
            'endedConsultations' => (clone $ended)->count(),
            'gross' => (int) (clone $ended)->sum('fee'),
            'commission' => (int) (clone $ended)->sum('commission_amount'),
            'doctorEarnings' => (int) (clone $ended)->sum('doctor_amount'),
            'requestedPayouts' => (int) NephroPayout::where('status', 'requested')->sum('amount'),
            'paidPayouts' => (int) NephroPayout::where('status', 'paid')->sum('amount'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $query = NephrologistProfile::with('user')->where('status', '!=', 'draft')->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'needs_fix' THEN 1 ELSE 2 END")->orderByDesc('submitted_at');
        if ($status && in_array($status, NephrologistProfile::STATUSES, true)) {
            $query->where('status', $status);
        }

        $profiles = $query->get();
        $counts = NephroConsultation::whereIn('nephrologist_id', $profiles->pluck('user_id'))->where('status', 'ended')
            ->selectRaw('nephrologist_id, COUNT(*) as n')->groupBy('nephrologist_id')->pluck('n', 'nephrologist_id');

        return response()->json($profiles->map(fn (NephrologistProfile $p) => [
            'id' => $p->id,
            'userId' => $p->user_id,
            'name' => Presenter::doctorName($p->user),
            'phone' => $p->user?->phone_number,
            'status' => $p->status,
            'councilNumber' => $p->council_number,
            'city' => $p->city,
            'fee' => $p->fee,
            'submittedAt' => $p->submitted_at?->toIso8601String(),
            'approvedAt' => $p->approved_at?->toIso8601String(),
            'completed' => (int) ($counts[$p->user_id] ?? 0),
        ])->values());
    }

    public function show(NephrologistProfile $profile): JsonResponse
    {
        $profile->load('user');
        $stored = StoredFile::whereIn('id', array_values($profile->documents ?? []))->get()->keyBy('id');
        $docs = [];
        foreach (config('nephrology.documents') as $key => $required) {
            $file = $stored[($profile->documents ?? [])[$key] ?? 0] ?? null;
            $docs[] = [
                'key' => $key,
                'required' => $required,
                'name' => $file?->originalFileName,
                'url' => $file ? PrivateFiles::signedUrl($file->url, 15) : null,
            ];
        }

        return response()->json([
            'id' => $profile->id,
            'userId' => $profile->user_id,
            'status' => $profile->status,
            'reviewNote' => $profile->review_note,
            'reviewDoc' => $profile->review_doc,
            'name' => Presenter::doctorName($profile->user),
            'phone' => $profile->user?->phone_number,
            'email' => $profile->user?->email,
            'nationalId' => $profile->national_id,
            'birthYear' => $profile->birth_year,
            'councilNumber' => $profile->council_number,
            'baseSpecialty' => $profile->base_specialty,
            'university' => $profile->university,
            'graduationYear' => $profile->graduation_year,
            'city' => $profile->city,
            'workplace' => $profile->workplace,
            'focus' => $profile->focus ?? [],
            'bio' => $profile->bio,
            'fee' => $profile->fee,
            'iban' => $profile->iban,
            'documents' => $docs,
            'submittedAt' => $profile->submitted_at?->toIso8601String(),
        ]);
    }

    public function review(Request $request, NephrologistProfile $profile): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'needs_fix', 'reject', 'suspend', 'reinstate'])],
            'note' => ['nullable', 'string', 'max:500'],
            'doc' => ['nullable', 'string', Rule::in(array_keys(config('nephrology.documents')))],
        ]);

        if (in_array($data['decision'], ['needs_fix', 'reject'], true) && empty($data['note'])) {
            throw ValidationException::withMessages(['note' => ['علت را برای پزشک بنویسید.']]);
        }

        $allowedFrom = [
            'approve' => ['pending', 'needs_fix'],
            'needs_fix' => ['pending'],
            'reject' => ['pending', 'needs_fix'],
            'suspend' => ['approved'],
            'reinstate' => ['suspended'],
        ];
        if (! in_array($profile->status, $allowedFrom[$data['decision']], true)) {
            throw ValidationException::withMessages(['decision' => ['این اقدام برای وضعیت فعلی درخواست ممکن نیست.']]);
        }

        $user = $profile->user;
        $notifier = new NephrologyNotifier;

        DB::transaction(function () use ($data, $profile, $user) {
            $base = ['reviewed_by' => auth()->id()];
            switch ($data['decision']) {
                case 'approve':
                    $profile->update($base + ['status' => 'approved', 'approved_at' => now(), 'review_note' => null, 'review_doc' => null]);
                    if ($user->role === 'user') {
                        $user->update(['role' => 'nephrologist']);
                    }
                    break;
                case 'needs_fix':
                    $profile->update($base + ['status' => 'needs_fix', 'review_note' => $data['note'], 'review_doc' => $data['doc'] ?? null]);
                    break;
                case 'reject':
                    $profile->update($base + ['status' => 'rejected', 'review_note' => $data['note']]);
                    if ($user->role === 'nephrologist') {
                        $user->update(['role' => 'user']);
                    }
                    break;
                case 'suspend':
                    $profile->update($base + ['status' => 'suspended', 'is_accepting' => false, 'review_note' => $data['note'] ?? null]);
                    break;
                case 'reinstate':
                    $profile->update($base + ['status' => 'approved', 'review_note' => null]);
                    break;
            }
            AuditLog::record('nephrologist.'.$data['decision'], $profile);
        });

        if ($data['decision'] === 'approve') {
            $notifier->applicationApproved($user);
        } elseif ($data['decision'] === 'needs_fix') {
            $notifier->applicationNeedsFix($user);
        }

        return $this->show($profile->fresh('user'));
    }

    public function payouts(Request $request): JsonResponse
    {
        $query = NephroPayout::with('nephrologist')->orderByRaw("CASE status WHEN 'requested' THEN 0 ELSE 1 END")->orderByDesc('id');
        if (in_array($request->query('status'), ['requested', 'paid', 'rejected'], true)) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->limit(200)->get()->map(fn (NephroPayout $p) => [
            'id' => $p->id,
            'doctor' => Presenter::doctorName($p->nephrologist),
            'phone' => $p->nephrologist?->phone_number,
            'amount' => $p->amount,
            'iban' => $p->iban, // full: the admin has to send the money there
            'status' => $p->status,
            'reference' => $p->reference,
            'note' => $p->note,
            'requestedAt' => $p->requested_at?->toIso8601String(),
            'processedAt' => $p->processed_at?->toIso8601String(),
        ])->values());
    }

    public function processPayout(Request $request, NephroPayout $payout): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['paid', 'rejected'])],
            'reference' => ['nullable', 'string', 'max:60', 'required_if:decision,paid'],
            'note' => ['nullable', 'string', 'max:300', 'required_if:decision,rejected'],
        ]);

        DB::transaction(function () use ($payout, $data) {
            $locked = NephroPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'requested') {
                throw ValidationException::withMessages(['decision' => ['این درخواست قبلاً بررسی شده است.']]);
            }
            $locked->update([
                'status' => $data['decision'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'processed_at' => now(),
                'processed_by' => auth()->id(),
            ]);
            AuditLog::record('nephro_payout.'.$data['decision'], $locked);
        });

        if ($data['decision'] === 'paid') {
            (new NephrologyNotifier)->payoutPaid($payout->nephrologist, $payout->amount);
        }

        return response()->json(Presenter::payout($payout->fresh()));
    }
}
