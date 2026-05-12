<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Register user
     * @unauthenticated
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|unique:users,phone_number|regex:/^09\d{9}$/',
        ]);

        $user = User::create([
            'phone_number' => $validated['phone_number'],
            'otp_code' => 1111,
            'role' => 'user',
            'refresh_token' => Str::random(64)
        ]);

        $access_token = JWTAuth::fromUser($user);
        
        $user->access_token = $access_token;
        $user->save();

        return response()->json([
            'message' => 'User registered successfully',
            'user'    => $user,
            'access_token'   => $access_token,
            'refresh_token'  => $user->refresh_token
        ]);
    }

    /**
     * Login user
     * @unauthenticated
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'phone_number' => 'required|string',
            'otp_code'     => 'required|integer|digits:4',
        ]);

        // Retrieve the user by phone number
        $user = User::where('phone_number', $credentials['phone_number'])->first();

        // Check if user exists and OTP is valid
        if (!$user || $user->otp_code !== $credentials['otp_code']) {
            return response()->json(['error' => 'Invalid credentials or OTP'], 401);
        }

        if (!$user->refresh_token) {
            $user->refresh_token = Str::random(64);
        }

        $access_token = JWTAuth::fromUser($user);

        // Invalidate the OTP after successful login, also assign the token
        $user->otp_code = null;
        $user->access_token = $access_token;
        $user->save();

        return response()->json([
            'message' => 'Login successful',
            'user'    => $user,
            'access_token'  => $access_token,
            'refresh_token' => $user->refresh_token,
        ]);
    }

    /**
     * Logout user
    */
    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());
        return response()->json(['message' => 'Logged out successfully']);
    }
    
    /**
     * Get authenticated user profile
    */
    public function profile()
    {
        return response()->json(auth()->user());
    }

    /**
     * Refresh tokens
    */
    public function refreshTokens(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required|string',
        ]);

        $user = User::where('refresh_token', $request->refresh_token)->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid refresh token'], 401);
        }

        // Optional: rotate refresh token (recommended for security)
        $newRefreshToken = Str::random(64);
        $user->refresh_token = $newRefreshToken;
        $user->save();

        // Generate new access token
        $newAccessToken = JWTAuth::fromUser($user);

        return response()->json([
            'access_token'  => $newAccessToken,
            'refresh_token' => $newRefreshToken,
        ]);
    }
    
    /**
     * Log out of all devices
    */
    public function logoutAllDevices()
    {
        $user = auth()->user();
        $user->refresh_token = null;
        $user->save();

        // Invalidate current JWT
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json(['message' => 'Logged out from all devices']);
    }
}
