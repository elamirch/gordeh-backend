<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuickAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickAssessmentController extends Controller
{
    /**
     * GET /admin/quick-assessments?page=&limit=&risk_level=&q=
     * Every user's quick assessment with their name and phone number, plus the split by risk.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'risk_level' => 'nullable|integer|between:0,2',
            'q' => 'nullable|string|max:60',
        ]);

        $query = QuickAssessment::with('user:id,first_name,last_name,phone_number')->latest('updated_at');

        if ($request->filled('risk_level') || $request->query('risk_level') === '0') {
            $query->where('risk_level', (int) $request->query('risk_level'));
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->whereHas('user', fn ($u) => $u
                ->where('phone_number', 'like', '%'.$q.'%')
                ->orWhere('first_name', 'like', '%'.$q.'%')
                ->orWhere('last_name', 'like', '%'.$q.'%'));
        }

        $page = $query->paginate((int) $request->query('limit', 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (QuickAssessment $a) => [
                'id' => $a->id,
                'user' => $a->user ? [
                    'id' => $a->user->id,
                    'name' => trim($a->user->first_name.' '.$a->user->last_name),
                    'phone_number' => $a->user->phone_number,
                ] : null,
                'answers' => $a->answers,
                'age' => $a->age,
                'gender' => $a->gender,
                'creatinine' => $a->creatinine,
                'gfr' => $a->gfr,
                'risk_level' => $a->risk_level,
                'source' => $a->source,
                'updated_at' => $a->updated_at?->toIso8601String(),
            ])->values(),
            'count' => $page->total(),
            'summary' => [
                'total' => QuickAssessment::count(),
                'by_risk' => [
                    'low' => QuickAssessment::where('risk_level', 0)->count(),
                    'medium' => QuickAssessment::where('risk_level', 1)->count(),
                    'high' => QuickAssessment::where('risk_level', 2)->count(),
                ],
            ],
        ]);
    }
}
