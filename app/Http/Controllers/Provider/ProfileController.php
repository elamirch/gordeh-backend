<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show()
    {
        return response()->json($this->format(auth()->user()));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string',
            'specialty' => 'nullable|string',
            'personalPhone' => 'nullable|string',
            'gordehPhone' => 'nullable|string',
            'credentials' => 'nullable|string',
            'email' => 'nullable|email',
        ]);

        $user = auth()->user();

        if (array_key_exists('name', $data)) {
            [$first, $last] = array_pad(explode(' ', trim($data['name']), 2), 2, null);
            $user->first_name = $first;
            $user->last_name = $last;
        }
        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }
        $user->save();

        $profile = ProviderProfile::updateOrCreate(
            ['user_id' => $user->id],
            array_filter([
                'specialty' => $data['specialty'] ?? null,
                'personal_phone' => $data['personalPhone'] ?? null,
                'gordeh_phone' => $data['gordehPhone'] ?? null,
                'credentials' => $data['credentials'] ?? null,
            ], fn ($v) => $v !== null)
        );

        return response()->json($this->format($user->fresh()));
    }

    private function format($user): array
    {
        $profile = $user->providerProfile;

        return [
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'specialty' => $profile->specialty ?? null,
            'personalPhone' => $profile->personal_phone ?? null,
            'gordehPhone' => $profile->gordeh_phone ?? null,
            'credentials' => $profile->credentials ?? null,
            'email' => $user->email,
        ];
    }
}
