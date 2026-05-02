<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|unique:users,phone_number|regex:/^09\d{9}$/',
            'email'        => 'nullable|email|unique:users,email',
            'password'     => 'required|string|min:6',
        ]);

        $user = User::create([
            'phone_number' => $validated['phone_number'],
            'email'        => $validated['email'] ?? null,
            'password'     => Hash::make($validated['password']),
            'role'         => 'user',
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'User registered successfully',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'phone_number' => 'required|string',
            'password'     => 'required|string',
        ]);

        if (!$token = JWTAuth::attempt($credentials)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'user'    => auth()->user(),
            'token'   => $token,
        ]);
    }

    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function profile()
    {
        return response()->json(auth()->user());
    }
}
