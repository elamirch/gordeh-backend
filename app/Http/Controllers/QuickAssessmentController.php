<?php

namespace App\Http\Controllers;

use App\Models\QuickAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The quick kidney-risk assessment (five yes/no questions, age and sex, optional creatinine),
 * stored once per account. The apps and the website ask for it only until the signed-in user
 * has one on the server — so it is asked once per phone number, not once per device.
 */
class QuickAssessmentController extends Controller
{
    // GET /quick-assessment → { completed: bool, assessment: {...}|null }
    public function show(): JsonResponse
    {
        $assessment = QuickAssessment::where('user_id', auth()->id())->first();

        return response()->json([
            'completed' => (bool) $assessment,
            'assessment' => $assessment ? $this->present($assessment) : null,
        ]);
    }

    // PUT /quick-assessment — creates the assessment, or replaces it when the user redoes it
    public function store(Request $request): JsonResponse
    {
        $answers = Rule::in(['yes', 'no', 'unsure']);

        $data = $request->validate([
            'answers' => ['required', 'array'],
            'answers.bp' => ['required', $answers],
            'answers.dm' => ['required', $answers],
            'answers.fam' => ['required', $answers],
            'answers.aki' => ['required', $answers],
            'answers.bmi' => ['required', $answers],
            'age' => ['required', 'integer', 'min:1', 'max:120'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'creatinine' => ['nullable', 'numeric', 'gt:0', 'max:30'],
            'gfr' => ['nullable', 'numeric', 'gt:0', 'max:250'],
            'risk_level' => ['required', 'integer', 'between:0,2'],
            'source' => ['nullable', Rule::in(['web', 'app'])],
        ]);

        // Only the five known questions are kept, whatever else the client sent.
        $data['answers'] = array_intersect_key($data['answers'], array_flip(QuickAssessment::QUESTIONS));

        $assessment = QuickAssessment::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'answers' => $data['answers'],
                'age' => $data['age'],
                'gender' => $data['gender'],
                'creatinine' => $data['creatinine'] ?? null,
                'gfr' => $data['gfr'] ?? null,
                'risk_level' => $data['risk_level'],
                'source' => $data['source'] ?? 'web',
            ]
        );

        return response()->json([
            'completed' => true,
            'assessment' => $this->present($assessment),
        ], $assessment->wasRecentlyCreated ? 201 : 200);
    }

    private function present(QuickAssessment $a): array
    {
        return [
            'answers' => $a->answers,
            'age' => $a->age,
            'gender' => $a->gender,
            'creatinine' => $a->creatinine,
            'gfr' => $a->gfr,
            'risk_level' => $a->risk_level,
            'source' => $a->source,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }
}
