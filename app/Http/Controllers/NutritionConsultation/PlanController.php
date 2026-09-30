<?php

namespace App\Http\Controllers\NutritionConsultation;

use App\Http\Controllers\Controller;
use App\Models\DietPlan;
use App\Support\JalaliDate;
use App\Support\PrivateFiles;

class PlanController extends Controller
{
    public function index()
    {
        $plans = DietPlan::where('user_id', auth()->id())
            ->whereNotNull('file_url')
            ->orderByDesc('created_at')
            ->get();

        $latestSentId = $plans->firstWhere('status', 'sent')?->id;

        return response()->json($plans->map(fn (DietPlan $plan) => [
            'id' => $plan->id,
            'title' => 'برنامه غذایی' . ($plan->version ? ' - نسخه ' . $plan->version : ''),
            'receivedAt' => JalaliDate::dayMonth($plan->created_at),
            'tag' => $plan->id === $latestSentId ? 'active' : 'archived',
            'size' => PrivateFiles::size($plan->file_url),
            'fileUrl' => PrivateFiles::signedUrl($plan->file_url),
        ])->values());
    }
}
