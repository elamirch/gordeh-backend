<?php

namespace App\Http\Controllers;

use App\Models\LabTest;
use App\Models\LabTestFile;
use App\Services\LabTestFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LabTestFileController extends Controller
{
    private const MAX_FILES_PER_REQUEST = 5;

    public function __construct(private LabTestFileService $service)
    {
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

    // PATCH /lab-tests/{labTest}/files
    public function attach(Request $request, LabTest $labTest): JsonResponse
    {
        $user = auth()->user();
        if ($labTest->user_id !== $user->id && ! in_array($user->role, ['admin', 'provider'], true)) {
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
        if ($labTest->user_id !== $user->id && ! in_array($user->role, ['admin', 'provider'], true)) {
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

    private function transform(LabTestFile $file): array
    {
        return [
            'id' => $file->id,
            'patientId' => $file->user_id,
            'testId' => $file->lab_test_id,
            'url' => $this->service->signedUrl($file),
            'filename' => $file->original_filename,
            'mimeType' => $file->mime_type,
            'size' => $file->size,
            'createdAt' => $file->created_at->toIso8601String(),
        ];
    }
}
