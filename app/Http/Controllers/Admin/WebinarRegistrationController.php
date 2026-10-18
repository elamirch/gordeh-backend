<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WebinarRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebinarRegistrationController extends Controller
{
    // GET /admin/webinar-registrations
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'webinar' => 'nullable|string|max:60',
            'source' => ['nullable', Rule::in(WebinarRegistration::SOURCES)],
            'search' => 'nullable|string|max:100',
        ]);

        $page = max(1, intval($request->query('page', 1)));
        $limit = $request->has('limit') ? intval($request->query('limit')) : 20;
        if ($limit <= 0 || $limit > 1000) $limit = 20;

        $query = WebinarRegistration::query();

        if ($webinar = $request->query('webinar')) {
            $query->where('webinar', $webinar);
        }
        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like));
        }

        $count = $query->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (WebinarRegistration $r) => $r->present()),
            'count' => $count,
        ]);
    }

    // POST /admin/webinar-registrations — add someone manually
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

        [$registration, $created] = WebinarRegistration::register($data['webinar'], $data['name'], $data['phone'], 'admin');

        if (! $created) {
            return response()->json(['message' => 'این شماره قبلاً در این وبینار ثبت‌نام کرده است.'], 409);
        }

        AuditLog::record('webinar.registration_added', $registration);

        return response()->json($registration->present(), 201);
    }

    // DELETE /admin/webinar-registrations/{webinarRegistration}
    public function destroy(WebinarRegistration $webinarRegistration): JsonResponse
    {
        AuditLog::record('webinar.registration_removed', $webinarRegistration);
        $webinarRegistration->delete();

        return response()->json(['success' => true]);
    }
}
