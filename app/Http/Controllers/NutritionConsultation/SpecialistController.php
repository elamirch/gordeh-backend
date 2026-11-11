<?php

namespace App\Http\Controllers\NutritionConsultation;

use App\Http\Controllers\Controller;
use App\Models\User;

class SpecialistController extends Controller
{
    /** Active specialists the patient can pick when booking. */
    public function index()
    {
        $specialists = User::activeProvider()->with('providerProfile')->orderBy('first_name')->get();

        return response()->json($specialists->map(fn (User $u) => [
            'id' => $u->id,
            'name' => trim(($u->first_name ?? '').' '.($u->last_name ?? '')),
            'specialty' => $u->providerProfile?->specialty,
            'avatar' => $u->profile_img_signed_url,
        ])->values());
    }
}
