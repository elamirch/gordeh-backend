<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DietPlan;
use App\Models\User;
use App\Services\NutritionConsultation\ConsultationSms;
use App\Support\JalaliDate;
use App\Support\PrivateFiles;
use Illuminate\Http\Request;

class DietPlanController extends Controller
{
    public function store(Request $request, User $patient)
    {
        abort_unless(User::visibleToProvider(auth()->user())->whereKey($patient->id)->exists(), 404);

        $data = $request->validate([
            'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:20480',
            'version' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $fileUrl = PrivateFiles::store($request->file('file'), 'diet-plans');

        $plan = DietPlan::create([
            'user_id' => $patient->id,
            'provider_id' => auth()->id(),
            'status' => 'sent',
            'file_url' => $fileUrl,
            'version' => $data['version'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        AuditLog::record('plan_sent', $plan);

        (new ConsultationSms)->planSent($plan, $patient);

        $provider = auth()->user();

        return response()->json([
            'd' => JalaliDate::dayMonth($plan->created_at),
            'createdAt' => $plan->created_at->toIso8601String(),
            'by' => trim(($provider->first_name ?? '') . ' ' . ($provider->last_name ?? '')),
            'st' => $plan->status,
            'v' => $plan->version,
            'f' => PrivateFiles::signedUrl($plan->file_url),
            'note' => $plan->note,
        ], 201);
    }
}
