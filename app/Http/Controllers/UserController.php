<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UserController extends Controller
{
    public function index()
    {
        return User::all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'phone_number' => 'required|string|unique:users,phone_number',
            'first_name'   => 'nullable|string',
            'last_name'    => 'nullable|string',
            'email'        => 'nullable|email|unique:users,email',
            'height'       => 'nullable|integer',
            'weight'       => 'nullable|integer',
            'ideal_weight' => 'nullable|integer',
            'BMI'          => 'nullable|numeric',
            'daily_calories' => 'nullable|integer',
            'gender'       => 'nullable|string',
            'blood_type'   => 'nullable|string',
            'age'          => 'nullable|integer',
            'profile_img_url' => 'nullable|string|unique:users,profile_img_url',
            'otp_code'     => 'nullable|integer',
            'otp_code_expiration' => 'nullable|date',
            'refresh_token' => 'nullable|string',
            'birth_date'    => 'nullable|date',
            'role'          => 'nullable|string|in:user,admin',
        ]);

        $user = User::create($data);

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return $user;
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'phone_number' => 'nullable|string|unique:users,phone_number,' . $user->id,
            'first_name'   => 'nullable|string',
            'last_name'    => 'nullable|string',
            'email'        => 'nullable|email|unique:users,email,' . $user->id,
            'height'       => 'nullable|integer',
            'weight'       => 'nullable|integer',
            'ideal_weight' => 'nullable|integer',
            'BMI'          => 'nullable|numeric',
            'daily_calories' => 'nullable|integer',
            'gender'       => 'nullable|string',
            'blood_type'   => 'nullable|string',
            'age'          => 'nullable|integer',
            'profile_img_url' => 'nullable|string|unique:users,profile_img_url,' . $user->id,
            'otp_code'     => 'nullable|integer', //May be undeeded
            'otp_code_expiration' => 'nullable|date',
            'refresh_token' => 'nullable|string', //May be undeeded as we're using sanctum
            'birth_date'    => 'nullable|date',
            'role'          => 'nullable|string|in:user,admin',
        ]);

        $user->update($data);

        return $user;
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(null, 204);
    }
}
