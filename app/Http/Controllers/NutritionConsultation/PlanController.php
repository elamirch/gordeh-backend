<?php

namespace App\Http\Controllers\NutritionConsultation;

use App\Http\Controllers\Controller;
use App\Models\DietPlan;
use App\Support\JalaliDate;
use Illuminate\Support\Facades\Storage;

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
            'size' => $plan->file_url && Storage::disk('public')->exists($plan->file_url)
                ? Storage::disk('public')->size($plan->file_url)
                : null,
            'fileUrl' => $plan->file_url ? Storage::disk('public')->url($plan->file_url) : null,
        ])->values());
    }
}
