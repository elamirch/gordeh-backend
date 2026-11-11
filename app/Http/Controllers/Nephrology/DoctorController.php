<?php

namespace App\Http\Controllers\Nephrology;

use App\Http\Controllers\Controller;
use App\Models\NephrologistProfile;
use App\Models\NephroConsultation;
use App\Models\NephroReview;
use App\Services\Nephrology\NephrologyService;
use App\Services\Nephrology\Presenter;
use Illuminate\Http\JsonResponse;

/** Public: the approved nephrologists patients can pick (no sign-in needed to browse). */
class DoctorController extends Controller
{
    public function index(): JsonResponse
    {
        $profiles = NephrologistProfile::with('user')->where('status', 'approved')->get();

        return response()->json($this->present($profiles)->values());
    }

    public function show(int $id): JsonResponse
    {
        $profile = NephrologistProfile::with('user')->where('status', 'approved')->where('user_id', $id)->firstOrFail();

        $reviews = NephroReview::with('consultation')->where('nephrologist_id', $id)->latest()->limit(10)->get()
            ->map(fn (NephroReview $r) => Presenter::review($r, true));

        return response()->json([
            'doctor' => $this->present(collect([$profile]))->first(),
            'reviews' => $reviews,
        ]);
    }

    private function present($profiles)
    {
        $ids = $profiles->pluck('user_id')->all();
        $ratings = NephrologyService::ratings($ids);
        $open = NephroConsultation::whereIn('nephrologist_id', $ids)->whereIn('status', NephroConsultation::OPEN_STATUSES)
            ->selectRaw('nephrologist_id, COUNT(*) as n')->groupBy('nephrologist_id')->pluck('n', 'nephrologist_id');

        return $profiles->map(fn (NephrologistProfile $p) => Presenter::doctor($p, $ratings[$p->user_id] ?? null, (int) ($open[$p->user_id] ?? 0)));
    }
}
