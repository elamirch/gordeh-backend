<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LabTestOcrError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabTestOcrErrorController extends Controller
{
    // GET /admin/lab-test-ocr-errors — failed OCR attempts, latest first. `search` matches
    // the reference code a patient quotes, or the patient's name/phone.
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'stage' => ['nullable', Rule::in(LabTestOcrError::STAGES)],
            'search' => 'nullable|string|max:100',
        ]);

        $page = max(1, intval($request->query('page', 1)));
        $limit = $request->has('limit') ? intval($request->query('limit')) : 20;
        if ($limit <= 0 || $limit > 100) $limit = 20;

        $query = LabTestOcrError::query()->with('user:id,first_name,last_name,phone_number');

        if ($stage = $request->query('stage')) {
            $query->where('stage', $stage);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q
                ->where('reference', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('phone_number', 'like', $like)));
        }

        $count = $query->count();
        $errors = $query->orderByDesc('created_at')->orderByDesc('id')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $errors->map->present()->values(),
            'count' => $count,
        ]);
    }
}
