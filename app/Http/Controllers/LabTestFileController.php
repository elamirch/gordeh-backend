<?php

namespace App\Http\Controllers;

use App\Models\LabTest;
use App\Models\LabTestFile;
use App\Models\LabTestOcrError;
use App\Models\User;
use App\Services\LabTestFileService;
use App\Services\LabTestOcrErrorTracker;
use App\Services\LabTestOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LabTestFileController extends Controller
{
    private const MAX_FILES_PER_REQUEST = 5;

    public function __construct(private LabTestFileService $service)
    {
    }

    /** Owner and admins always; providers only for patients in their scope. */
    private function canAccessTest(LabTest $labTest, User $user): bool
    {
        return $labTest->user_id === $user->id
            || $user->role === 'admin'
            || ($user->role === 'provider' && $user->canViewPatient($labTest->user_id));
    }

    // POST /lab-test-files
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'files' => 'required|array|min:1|max:'.self::MAX_FILES_PER_REQUEST,
            'files.*' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $patient = auth()->user();
        $saved = [];

        foreach ($request->file('files') as $file) {
            try {
                $saved[] = $this->service->store($file, $patient, $patient);
            } catch (\Throwable $e) {
                // Failure is already logged inside the service; skip this file
                // and let the rest of the batch continue.
                continue;
            }
        }

        if (empty($saved)) {
            return response()->json(['message' => 'Failed to store file(s)'], 500);
        }

        return response()->json(
            collect($saved)->map(fn (LabTestFile $f) => $this->transform($f))->values(),
            201
        );
    }

    // POST /lab-tests/ocr — store the report images, then extract lab values from them.
    // Retrying after a failure sends `fileIds` (the already-stored files) instead of
    // `images`, so the same report isn't stored twice.
    //
    // Every failure is recorded by LabTestOcrErrorTracker and its response carries a
    // `reference` the patient can quote to support.
    public function ocr(Request $request, LabTestOcrService $ocr, LabTestOcrErrorTracker $tracker): JsonResponse
    {
        try {
            return $this->runOcr($request, $ocr, $tracker);
        } catch (ValidationException $e) {
            $reference = $tracker->record(
                $request, LabTestOcrError::SOURCE_SERVER, LabTestOcrError::STAGE_VALIDATION, 'invalid_file', 422,
                collect($e->errors())->flatten()->implode(' | '),
                ['uploads' => $tracker->describeUploads($request), 'fileIds' => $request->input('fileIds')],
            );

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                'reference' => $reference,
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            $reference = $tracker->record(
                $request, LabTestOcrError::SOURCE_SERVER, LabTestOcrError::STAGE_EXCEPTION, 'internal_error', 500,
                get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine(),
                ['uploads' => $tracker->describeUploads($request), 'fileIds' => $request->input('fileIds')],
            );

            return response()->json([
                'error' => true,
                'errorType' => 'internal_error',
                'message' => LabTestOcrService::ERROR_MESSAGES[LabTestOcrService::ERROR_INTERNAL],
                'retryable' => true,
                'reference' => $reference,
            ], 500);
        }
    }

    private function runOcr(Request $request, LabTestOcrService $ocr, LabTestOcrErrorTracker $tracker): JsonResponse
    {
        $request->validate([
            'images' => 'required_without:fileIds|array|min:1|max:'.self::MAX_FILES_PER_REQUEST,
            'images.*' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'fileIds' => 'required_without:images|prohibits:images|array|min:1|max:'.self::MAX_FILES_PER_REQUEST,
            'fileIds.*' => 'integer|distinct',
        ], [
            'images.required_without' => 'حداقل یک تصویر آزمایش لازم است.',
            'images.max' => 'حداکثر :max فایل می‌توانید ارسال کنید.',
            'images.*.mimes' => 'فرمت فایل باید JPG، PNG، WEBP یا PDF باشد.',
            'images.*.max' => 'حجم هر فایل باید کمتر از ۱۰ مگابایت باشد.',
            'images.*.file' => 'فایل ارسال‌شده معتبر نیست.',
            'images.*.uploaded' => 'آپلود فایل ناموفق بود؛ ممکن است حجم آن بیش از حد مجاز باشد.',
        ]);

        $patient = auth()->user();

        if ($request->has('fileIds')) {
            $saved = LabTestFile::whereIn('id', $request->input('fileIds'))
                ->where('user_id', $patient->id)
                ->whereNull('lab_test_id')
                ->get();

            // Someone else's file, an already-attached file or a bad id all look the
            // same from here — never reveal which.
            if ($saved->count() !== count($request->input('fileIds'))) {
                $reference = $tracker->record(
                    $request, LabTestOcrError::SOURCE_SERVER, LabTestOcrError::STAGE_LOOKUP, 'files_not_found', 404,
                    'Found '.$saved->count().' of '.count($request->input('fileIds')).' retry files',
                    ['fileIds' => $request->input('fileIds'), 'foundIds' => $saved->pluck('id')->all()],
                );

                return response()->json([
                    'message' => 'فایل‌های انتخاب‌شده یافت نشد. لطفاً دوباره آپلود کنید.',
                    'reference' => $reference,
                ], 404);
            }

            LabTestFile::whereIn('id', $saved->pluck('id'))->update(['ocr_status' => LabTestFile::OCR_PENDING]);
        } else {
            $batchId = (string) Str::uuid();
            $saved = collect();
            $storeErrors = [];

            foreach ($request->file('images') as $image) {
                try {
                    $file = $this->service->store($image, $patient, $patient);
                    $file->update(['ocr_batch_id' => $batchId, 'ocr_status' => LabTestFile::OCR_PENDING]);
                    $saved->push($file);
                } catch (\Throwable $e) {
                    // The service only logs a failed disk write; a failed DB insert or the
                    // OCR-field update (e.g. migration not run) surfaces only here.
                    $storeErrors[] = $image->getClientOriginalName().': '.$e->getMessage();
                }
            }

            if ($storeErrors) {
                // Recorded even when some pages saved — OCR then runs on fewer pages.
                $reference = $tracker->record(
                    $request,
                    LabTestOcrError::SOURCE_SERVER,
                    LabTestOcrError::STAGE_STORAGE,
                    $saved->isEmpty() ? 'storage_failed' : 'partial_storage_failed',
                    $saved->isEmpty() ? 500 : null,
                    implode(' | ', $storeErrors),
                    ['uploads' => $tracker->describeUploads($request), 'batchId' => $batchId, 'savedIds' => $saved->pluck('id')->all()],
                );
            }

            if ($saved->isEmpty()) {
                return response()->json([
                    'error' => true,
                    'errorType' => 'storage_failed',
                    'message' => 'ذخیره‌ی فایل آزمایش ناموفق بود. لطفاً دوباره تلاش کنید.',
                    'retryable' => true,
                    'reference' => $reference,
                ], 500);
            }
        }

        // The model call alone may take up to 120s (plus connect retries), well past
        // PHP's default 30s max_execution_time — which kills the request (and
        // `artisan serve` with it) without any response. Budget this request only.
        set_time_limit(LabTestOcrService::MAX_DURATION_SECONDS);

        $outcome = $ocr->process($saved, $patient->id);
        $files = $saved->map(fn (LabTestFile $f) => $this->transformWithOcr($f))->values();

        if ($outcome['error'] !== null) {
            $error = $outcome['error'];
            $status = $error === LabTestOcrService::ERROR_SERVICE_UNAVAILABLE ? 503 : ($error === LabTestOcrService::ERROR_INVALID_RESPONSE ? 502 : 500);

            $reference = $tracker->record(
                $request, LabTestOcrError::SOURCE_SERVER, LabTestOcrError::STAGE_OCR, $error, $status,
                $outcome['reason'],
                ['fileIds' => $saved->pluck('id')->all(), 'retry' => $request->has('fileIds')],
            );

            return response()->json([
                'error' => true,
                'errorType' => $error,
                'message' => LabTestOcrService::ERROR_MESSAGES[$error],
                // Files are kept, so the client can retry with fileIds.
                'retryable' => $error !== LabTestOcrService::ERROR_INTERNAL,
                'files' => $files,
                'reference' => $reference,
            ], $status);
        }

        return response()->json(['files' => $files, 'result' => $outcome['result']]);
    }

    // POST /lab-tests/ocr/errors — the app reports failures the server never saw or
    // couldn't answer (timeout, proxy error page, upload rejected by nginx, …).
    public function reportClientError(Request $request, LabTestOcrErrorTracker $tracker): JsonResponse
    {
        $data = $request->validate([
            'errorType' => 'required|string|max:40',
            'httpStatus' => 'nullable|integer|min:0|max:999',
            'detail' => 'nullable|string|max:2000',
            'context' => 'nullable|array',
        ]);

        // Bound what a client can make us store.
        $context = json_encode($data['context'] ?? []);
        $context = strlen($context) <= 4000 ? ($data['context'] ?? []) : ['truncated' => substr($context, 0, 4000)];

        $reference = $tracker->record(
            $request, LabTestOcrError::SOURCE_CLIENT, LabTestOcrError::STAGE_CLIENT,
            $data['errorType'], $data['httpStatus'] ?? null, $data['detail'] ?? null, $context,
        );

        return response()->json(['reference' => $reference], 201);
    }

    // PATCH /lab-tests/{labTest}/files
    public function attach(Request $request, LabTest $labTest): JsonResponse
    {
        $user = auth()->user();
        if (! $this->canAccessTest($labTest, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'fileIds' => 'required|array|min:1',
            'fileIds.*' => 'integer',
        ]);

        $files = LabTestFile::whereIn('id', $data['fileIds'])->get();

        if ($files->count() !== count($data['fileIds'])) {
            return response()->json(['message' => 'One or more files were not found'], 404);
        }

        if ($files->contains(fn (LabTestFile $f) => $f->user_id !== $labTest->user_id)) {
            return response()->json(['message' => 'One or more files do not belong to this patient'], 403);
        }

        LabTestFile::whereIn('id', $data['fileIds'])->update(['lab_test_id' => $labTest->id]);

        $updated = $labTest->files()->orderByDesc('created_at')->get();

        return response()->json($updated->map(fn (LabTestFile $f) => $this->transform($f))->values());
    }

    // GET /lab-tests/{labTest}/files
    public function index(LabTest $labTest): JsonResponse
    {
        $user = auth()->user();
        if (! $this->canAccessTest($labTest, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $files = $labTest->files()->orderByDesc('created_at')->get();

        return response()->json($files->map(fn (LabTestFile $f) => $this->transform($f))->values());
    }

    // GET /lab-test-files/{labTestFile}/download (signed URL only)
    public function download(LabTestFile $labTestFile)
    {
        if (! Storage::disk($labTestFile->disk)->exists($labTestFile->storage_key)) {
            abort(404);
        }

        return Storage::disk($labTestFile->disk)->response(
            $labTestFile->storage_key,
            $labTestFile->original_filename,
            ['Content-Type' => $labTestFile->mime_type]
        );
    }

    private function transformWithOcr(LabTestFile $file): array
    {
        return $this->transform($file) + [
            'ocrStatus' => $file->ocr_status,
            'batchId' => $file->ocr_batch_id,
        ];
    }

    private function transform(LabTestFile $file): array
    {
        return $this->service->transform($file);
    }
}
