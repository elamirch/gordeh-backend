<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LabTestFile;
use App\Services\LabTestFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabTestFileController extends Controller
{
    public function __construct(private LabTestFileService $service)
    {
    }

    // GET /admin/lab-test-files — every uploaded lab-test file with its OCR outcome.
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            // `none` = files uploaded outside the OCR flow (null status).
            'status' => ['nullable', Rule::in([...LabTestFile::OCR_STATUSES, 'none'])],
            'search' => 'nullable|string|max:100',
        ]);

        $page = max(1, intval($request->query('page', 1)));
        $limit = $request->has('limit') ? intval($request->query('limit')) : 20;
        if ($limit <= 0 || $limit > 100) $limit = 20;

        $query = LabTestFile::query()->with('user:id,first_name,last_name,phone_number');

        if ($status = $request->query('status')) {
            $status === 'none'
                ? $query->whereNull('ocr_status')
                : $query->where('ocr_status', $status);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('user', fn ($q) => $q
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('phone_number', 'like', $like));
        }

        $count = $query->count();
        $files = $query->orderByDesc('created_at')->orderByDesc('id')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $files->map(fn (LabTestFile $f) => $this->service->transform($f) + [
                'ocrStatus' => $f->ocr_status,
                'batchId' => $f->ocr_batch_id,
                'ocrResult' => $f->ocr_result,
                'ocrProcessedAt' => $f->ocr_processed_at?->toIso8601String(),
                'uploadedBy' => $f->uploaded_by,
                'user' => $f->user ? [
                    'id' => $f->user->id,
                    'first_name' => $f->user->first_name,
                    'last_name' => $f->user->last_name,
                    'phone_number' => $f->user->phone_number,
                ] : null,
            ])->values(),
            'count' => $count,
        ]);
    }
}
