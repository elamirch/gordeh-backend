<?php

namespace App\Http\Controllers;

use App\Models\Insurance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class InsuranceController extends Controller
{
    /**
     * List all insurance entries (paginated)
     */
    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = $request->has('limit') ? (int) $request->query('limit') : 10;
        if ($limit <= 0 || $limit > 100) $limit = 10;

        $query = Insurance::orderBy('created_at', 'desc');

        $count = $query->count();
        $data = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return response()->json([
            'data' => $data,
            'count' => $count,
        ]);
    }

    /**
     * List authenticated user's insurance entries (paginated)
     */
    public function indexMe(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = $request->has('limit') ? (int) $request->query('limit') : 10;
        if ($limit <= 0 || $limit > 100) $limit = 10;

        $query = Insurance::where('user_id', $user->id)->orderBy('created_at', 'desc');

        $count = $query->count();
        $data = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return response()->json([
            'data' => $data,
            'count' => $count,
        ]);
    }

    /**
     * Create new insurance
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'national_code'  => ['required','string','size:10'],
            'insurance_type' => ['required','string'],
            'first_name'     => ['nullable','string'],
            'last_name'      => ['nullable','string'],
        ]);

        try {
            $data['user_id'] = auth()->id();
            $data['status'] = 'created';

            $insurance = Insurance::create($data);

            return response()->json($insurance, 201);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    /**
     * Show single insurance
     */
    public function show($id)
    {
        $insurance = Insurance::with('creator')->find($id);
        if (! $insurance) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json($insurance);
    }

    /**
     * Update insurance (currently only status is updated in original service)
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['created','checked'])],
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'insurance_type' => 'nullable|string',
            'national_code' => 'nullable|string|size:10',
        ]);

        $insurance = Insurance::find($id);
        if (! $insurance) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Optionally add permission checks here (owner/admin)
        try {
            if (isset($data['status'])) $insurance->status = $data['status'];
            if (isset($data['first_name'])) $insurance->first_name = $data['first_name'];
            if (isset($data['last_name'])) $insurance->last_name = $data['last_name'];
            if (isset($data['insurance_type'])) $insurance->insurance_type = $data['insurance_type'];
            if (isset($data['national_code'])) $insurance->national_code = $data['national_code'];

            $insurance->save();

            return response()->json($insurance);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    /**
     * Optional: delete insurance
     */
    public function destroy($id)
    {
        $insurance = Insurance::find($id);
        if (! $insurance) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Optionally add permission checks here (owner/admin)
        $insurance->delete();

        return response()->json(null, 204);
    }
}
