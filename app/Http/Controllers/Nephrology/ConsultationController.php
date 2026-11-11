<?php

namespace App\Http\Controllers\Nephrology;

use App\Http\Controllers\Controller;
use App\Models\NephroConsultation;
use App\Models\NephrologistProfile;
use App\Models\NephroMessage;
use App\Models\StoredFile;
use App\Services\Nephrology\NephrologyService;
use App\Services\Nephrology\Presenter;
use App\Support\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The patient's side: booking (pay + describe the problem), the conversation, cancel, review. */
class ConsultationController extends Controller
{
    public function __construct(private NephrologyService $service) {}

    public function index(): JsonResponse
    {
        $items = NephroConsultation::with(['nephrologist', 'review'])
            ->where('patient_id', auth()->id())
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->get();

        $profiles = NephrologistProfile::whereIn('user_id', $items->pluck('nephrologist_id'))->get()->keyBy('user_id');

        return response()->json($items->map(fn (NephroConsultation $c) => Presenter::patientConsultation($c, $profiles[$c->nephrologist_id] ?? null))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'integer'],
            'expected_fee' => ['nullable', 'integer'],
            'consent' => ['required', 'accepted'],
            'patient_name' => ['required', 'string', 'min:2', 'max:100'],
            'patient_birth_year' => ['nullable', 'integer', 'between:1250,1500'],
            'patient_sex' => ['nullable', Rule::in(['m', 'f'])],
            'relation' => ['nullable', Rule::in(['self', 'parent', 'spouse', 'child', 'other'])],
            'reason' => ['required', 'string', 'max:200'],
            'symptoms' => ['nullable', 'array', 'max:12'],
            'symptoms.*' => ['string', 'max:100'],
            'symptom_duration' => ['nullable', 'string', 'max:60'],
            'diagnoses' => ['nullable', 'array', 'max:12'],
            'diagnoses.*' => ['string', 'max:100'],
            'medications' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lab_test_ids' => ['nullable', 'array', 'max:20'],
            'lab_test_ids.*' => ['integer'],
            'file_ids' => ['nullable', 'array', 'max:10'],
            'file_ids.*' => ['integer'],
        ]);

        $c = $this->service->book($request->user(), (int) $data['doctor_id'], $data);

        return response()->json(Presenter::patientConsultation($c->load('nephrologist')), 201);
    }

    public function show(NephroConsultation $consultation): JsonResponse
    {
        $this->authorizePatient($consultation);
        $consultation->load(['nephrologist', 'review']);
        $this->service->markRead($consultation, 'patient');

        $profile = NephrologistProfile::where('user_id', $consultation->nephrologist_id)->first();

        return response()->json([
            'consultation' => Presenter::patientConsultation($consultation, $profile),
            'messages' => $this->messages($consultation),
        ]);
    }

    /** GET …/messages?after=ID — the polling fallback when the socket is down. */
    public function messagesIndex(Request $request, NephroConsultation $consultation): JsonResponse
    {
        $this->authorizePatient($consultation);
        $this->service->markRead($consultation, 'patient');

        return response()->json([
            'status' => $consultation->status,
            'expiresAt' => $consultation->expires_at?->toIso8601String(),
            'messages' => $this->messages($consultation, (int) $request->query('after', 0)),
        ]);
    }

    public function storeMessage(Request $request, NephroConsultation $consultation): JsonResponse
    {
        $this->authorizePatient($consultation);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000', 'required_without:file_id'],
            'file_id' => ['nullable', 'integer'],
        ]);

        $file = null;
        if (! empty($data['file_id'])) {
            $file = StoredFile::where('user_id', auth()->id())->where('category', NephrologyService::FILE_CATEGORY)->findOrFail($data['file_id']);
        }

        $message = $this->service->postMessage($consultation, $request->user(), 'patient', $file ? 'file' : 'text', isset($data['body']) ? trim($data['body']) : null, $file);

        return response()->json(Presenter::message($message->load('file')), 201);
    }

    /** POST /nephrology/uploads — an attachment for the booking form or a chat message. */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240']]);

        $uploaded = $request->file('file');
        $file = StoredFile::create([
            'url' => PrivateFiles::store($uploaded, 'uploads'),
            'fileName' => $uploaded->hashName(),
            'originalFileName' => $uploaded->getClientOriginalName(),
            'user_id' => auth()->id(),
            'category' => NephrologyService::FILE_CATEGORY,
        ]);

        return response()->json([
            'id' => $file->id,
            'name' => $file->originalFileName,
            'url' => PrivateFiles::signedUrl($file->url, 60),
        ], 201);
    }

    /** Cancel before the doctor has written anything: the whole fee goes back to the wallet. */
    public function cancel(NephroConsultation $consultation): JsonResponse
    {
        $this->authorizePatient($consultation);

        if (! $this->service->refund($consultation, 'patient_cancelled')) {
            return response()->json(['message' => 'پس از پاسخ پزشک، مشاوره قابل لغو نیست.'], 422);
        }

        return response()->json(Presenter::patientConsultation($consultation->fresh(['nephrologist', 'review'])));
    }

    public function review(Request $request, NephroConsultation $consultation): JsonResponse
    {
        $this->authorizePatient($consultation);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'text' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = $this->service->review($consultation, (int) $data['rating'], isset($data['text']) ? trim($data['text']) : null);

        return response()->json(Presenter::review($review), 201);
    }

    private function authorizePatient(NephroConsultation $c): void
    {
        abort_unless((int) $c->patient_id === (int) auth()->id(), 404);
    }

    private function messages(NephroConsultation $c, int $after = 0): array
    {
        return NephroMessage::with('file')->where('consultation_id', $c->id)->where('id', '>', $after)
            ->orderBy('id')->limit(500)->get()->map(fn (NephroMessage $m) => Presenter::message($m))->all();
    }
}
