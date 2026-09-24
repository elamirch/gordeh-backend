<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DietPlan;
use App\Models\User;
use App\Support\JalaliDate;
use Illuminate\Http\Request;

class DietPlanController extends Controller
{
    public function store(Request $request, User $patient)
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:20480',
            'version' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $fileUrl = $request->file('file')->store('diet-plans', 'public');

        $plan = DietPlan::create([
            'user_id' => $patient->id,
            'provider_id' => auth()->id(),
            'status' => 'sent',
            'file_url' => $fileUrl,
            'version' => $data['version'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        AuditLog::record('plan_sent', $plan);

        $provider = auth()->user();

        return response()->json([
            'd' => JalaliDate::dayMonth($plan->created_at),
            'createdAt' => $plan->created_at->toIso8601String(),
            'by' => trim(($provider->first_name ?? '') . ' ' . ($provider->last_name ?? '')),
            'st' => $plan->status,
            'v' => $plan->version,
            'f' => $plan->file_url,
            'note' => $plan->note,
        ], 201);
    }
}
