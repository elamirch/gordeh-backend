<?php

namespace App\Http\Controllers\Webinar;

use App\Http\Controllers\Controller;
use App\Models\WebinarRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    // POST /webinar-registrations (public)
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'name' => trim(strip_tags((string) $request->input('name'))),
            'phone' => WebinarRegistration::normalizePhone((string) $request->input('phone')),
        ]);

        $data = $request->validate([
            'webinar' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'phone' => ['required', 'regex:/^09\d{9}$/'],
        ]);

        [, $created] = WebinarRegistration::register($data['webinar'], $data['name'], $data['phone'], 'website');

        // Don't echo back stored details: anyone can call this with any phone number.
        return response()->json([
            'registered' => true,
            'alreadyRegistered' => ! $created,
            'message' => $created
                ? 'ثبت‌نام شما با موفقیت انجام شد.'
                : 'این شماره قبلاً در وبینار ثبت‌نام کرده است.',
        ], $created ? 201 : 200);
    }
}
