<?php

namespace App\Http\Controllers\Nephrologist;

use App\Http\Controllers\Controller;
use App\Models\LabTest;
use App\Models\NephroConsultation;
use App\Models\NephroMessage;
use App\Models\StoredFile;
use App\Services\Nephrology\NephrologyService;
use App\Services\Nephrology\Presenter;
use App\Support\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The doctor's chat inbox: the conversation, the patient's shared record, ending with a summary. */
class ConsultationController extends Controller
{
    /** Quick actions: fixed wording, so the doctor can't be tricked into posting something else. */
    private const QUICK = [
        'lab_request' => 'پزشک درخواست آزمایش شما را ثبت کرد. نتیجه را پس از انجام در گرده ثبت کنید.',
        'education' => 'پزشک محتوای آموزشی «کاهش نمک و پروتئین مناسب در رژیم غذایی» را برای شما پیشنهاد کرد.',
        'bp_log' => 'پزشک از شما خواسته فشار خون را ۷ روز آینده هر روز اندازه بگیرید و ثبت کنید.',
        'emergency' => 'توصیهٔ پزشک: برای علائم شما همین حالا به اورژانس مراجعه کنید یا با ۱۱۵ تماس بگیرید.',
    ];

    public function __construct(private NephrologyService $service) {}

    public function index(Request $request): JsonResponse
    {
        $tab = $request->query('tab', 'open');

        $query = NephroConsultation::where('nephrologist_id', auth()->id())->where('status', '!=', 'refunded');
        if ($tab === 'open') {
            $query->whereIn('status', NephroConsultation::OPEN_STATUSES);
        } elseif ($tab === 'ended') {
            $query->where('status', 'ended');
        }

        $items = $query->with('patient')->orderByDesc('last_message_at')->orderByDesc('id')->limit(300)->get();

        $lastBodies = NephroMessage::whereIn('id', NephroMessage::whereIn('consultation_id', $items->pluck('id'))
            ->selectRaw('MAX(id) as id')->groupBy('consultation_id')->pluck('id'))->get()->keyBy('consultation_id');

        return response()->json($items->map(fn (NephroConsultation $c) => Presenter::doctorConsultation($c) + [
            'preview' => $this->preview($lastBodies[$c->id] ?? null),
        ])->values());
    }

    public function show(NephroConsultation $consultation): JsonResponse
    {
        $this->authorizeDoctor($consultation);
        $consultation->load('patient');
        $this->service->markRead($consultation, 'doctor');

        return response()->json([
            'consultation' => Presenter::doctorConsultation($consultation),
            'messages' => $this->messages($consultation),
        ]);
    }

    public function messagesIndex(Request $request, NephroConsultation $consultation): JsonResponse
    {
        $this->authorizeDoctor($consultation);
        $this->service->markRead($consultation, 'doctor');

        return response()->json([
            'status' => $consultation->status,
            'expiresAt' => $consultation->expires_at?->toIso8601String(),
            'messages' => $this->messages($consultation, (int) $request->query('after', 0)),
        ]);
    }

    public function storeMessage(Request $request, NephroConsultation $consultation): JsonResponse
    {
        $this->authorizeDoctor($consultation);
        $this->ensureApproved();
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000', 'required_without:file_id'],
            'file_id' => ['nullable', 'integer'],
        ]);

        $file = null;
        if (! empty($data['file_id'])) {
            $file = StoredFile::where('user_id', auth()->id())->where('category', NephrologyService::FILE_CATEGORY)->findOrFail($data['file_id']);
        }

        $message = $this->service->postMessage($consultation, $request->user(), 'doctor', $file ? 'file' : 'text', isset($data['body']) ? trim($data['body']) : null, $file);

        return response()->json(Presenter::message($message->load('file')), 201);
    }

    public function quickAction(Request $request, NephroConsultation $consultation): JsonResponse
    {
        $this->authorizeDoctor($consultation);
        $this->ensureApproved();
        $data = $request->validate(['action' => ['required', Rule::in(array_keys(self::QUICK))]]);

        $message = $this->service->postMessage($consultation, $request->user(), 'doctor', 'action', self::QUICK[$data['action']], null, ['action' => $data['action']]);

        return response()->json(Presenter::message($message), 201);
    }

    public function end(Request $request, NephroConsultation $consultation): JsonResponse
    {
        $this->authorizeDoctor($consultation);
        $this->ensureApproved();
        $data = $request->validate([
            'summary' => ['required', 'string', 'min:10', 'max:3000'],
            'recommendations' => ['nullable', 'array', 'max:10'],
            'recommendations.*' => ['string', 'max:300'],
            'tests' => ['nullable', 'array', 'max:10'],
            'tests.*' => ['string', 'max:100'],
            'follow_up' => ['nullable', Rule::in(['6w', '3m', '6m', 'none'])],
        ]);

        $ended = $this->service->end($consultation, [
            'summary' => trim($data['summary']),
            'recommendations' => array_values(array_filter(array_map('trim', $data['recommendations'] ?? []))),
            'tests' => $data['tests'] ?? [],
            'follow_up' => $data['follow_up'] ?? 'none',
            'written_at' => now()->toIso8601String(),
        ]);

        return response()->json(Presenter::doctorConsultation($ended->load('patient')));
    }

    /** Turn down a request nobody has answered yet; the fee goes back to the patient. */
    public function decline(NephroConsultation $consultation): JsonResponse
    {
        $this->authorizeDoctor($consultation);

        if (! $this->service->refund($consultation, 'doctor_declined')) {
            return response()->json(['message' => 'پس از پاسخ به بیمار، مشاوره قابل رد کردن نیست.'], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** The patient's shared record: what they typed, the labs they chose to share, history with this doctor. */
    public function record(NephroConsultation $consultation): JsonResponse
    {
        $this->authorizeDoctor($consultation);
        abort_unless($consultation->doctorHasAccess(), 403, 'دسترسی شما به این پرونده پایان یافته است.');

        $patient = $consultation->patient;
        $labs = LabTest::where('user_id', $consultation->patient_id)->whereIn('id', $consultation->lab_test_ids ?? [])
            ->orderBy('created_at')->get();
        $latest = $labs->last();
        $previous = $labs->count() > 1 ? $labs[$labs->count() - 2] : null;

        $files = StoredFile::whereIn('id', $consultation->file_ids ?? [])->where('user_id', $consultation->patient_id)->get()
            ->map(fn (StoredFile $f) => [
                'id' => $f->id,
                'name' => $f->originalFileName ?? $f->fileName,
                'date' => $f->created_at?->toIso8601String(),
                'url' => PrivateFiles::signedUrl($f->url, 30),
            ]);

        $history = NephroConsultation::where('patient_id', $consultation->patient_id)->where('nephrologist_id', auth()->id())
            ->where('status', '!=', 'refunded')->orderByDesc('id')->get()
            ->map(fn (NephroConsultation $c) => [
                'id' => $c->id,
                'status' => $c->status,
                'reason' => $c->reason,
                'date' => $c->created_at?->toIso8601String(),
                'summary' => $c->summary['summary'] ?? null,
            ]);

        return response()->json([
            'patient' => [
                'name' => $consultation->patient_name,
                'age' => Presenter::age($consultation->patient_birth_year) ?? ($consultation->relation === 'self' ? $patient?->age : null),
                'sex' => $consultation->patient_sex ?? ($consultation->relation === 'self' ? $patient?->gender : null),
                'weight' => $consultation->relation === 'self' ? $patient?->weight : null,
                'relation' => $consultation->relation,
                'code' => 'GR-'.str_pad((string) $consultation->patient_id, 5, '0', STR_PAD_LEFT),
            ],
            'reason' => $consultation->reason,
            'symptoms' => $consultation->symptoms ?? [],
            'symptomDuration' => $consultation->symptom_duration,
            'diagnoses' => $consultation->diagnoses ?? [],
            'medications' => $consultation->medications,
            'notes' => $consultation->notes,
            'labs' => $labs->map(fn (LabTest $t) => Presenter::lab($t))->values(),
            'kdigo' => Presenter::kdigo($latest?->gfr, $latest?->uacr),
            'previousLab' => $previous ? Presenter::lab($previous) : null,
            'files' => $files->values(),
            'history' => $history->values(),
            'accessUntil' => $consultation->isOpen() ? null : $consultation->ended_at?->copy()->addDays((int) config('nephrology.doctor_access_days'))->toIso8601String(),
        ]);
    }

    private function authorizeDoctor(NephroConsultation $c): void
    {
        abort_unless((int) $c->nephrologist_id === (int) auth()->id(), 404);
    }

    private function ensureApproved(): void
    {
        if (! auth()->user()->nephrologistProfile?->isApproved()) {
            throw ValidationException::withMessages(['status' => ['حساب شما در حال حاضر فعال نیست.']]);
        }
    }

    private function messages(NephroConsultation $c, int $after = 0): array
    {
        return NephroMessage::with('file')->where('consultation_id', $c->id)->where('id', '>', $after)
            ->orderBy('id')->limit(500)->get()->map(fn (NephroMessage $m) => Presenter::message($m))->all();
    }

    private function preview(?NephroMessage $m): string
    {
        if (! $m) {
            return '';
        }

        return match ($m->kind) {
            'file' => 'فایل پیوست',
            'lab' => 'نتایج آزمایش',
            default => mb_substr((string) $m->body, 0, 90),
        };
    }
}
