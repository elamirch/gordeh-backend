<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Send OTP
     * @unauthenticated
     */
    public function sendotp(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|regex:/^09\d{9}$/',
        ]);

        //Sending otp logic

        return response()->json([
            'message' => 'OTP was sent to your phone.'
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

    /**
     * Unified authentication endpoint.
     *
     * @unauthenticated
    */
    public function authenticate(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|regex:/^09\d{9}$/',
            'otp_code'     => 'required|integer|digits:4',
        ]);

        $user = User::where('phone_number', $validated['phone_number'])->first();

        $otp_code = 1111; //TO BE SET LATER

        if ($user) {
            if (
                !isset($validated['otp_code']) ||
                $user->otp_code !== (int) $validated['otp_code']
            ) {
                return response()->json(['error' => 'Invalid OTP'], 401);
            }

            if (!$user->refresh_token) {
                $user->refresh_token = Str::random(64);
            }
            $access_token = JWTAuth::fromUser($user);


            $user->otp_code = env('APP_DEBUG') ? $otp_code : null;
            $user->access_token = $access_token;
            $user->save();

            return response()->json([
                'message'        => 'Login successful',
                'user'           => $user,
                'access_token'   => $access_token,
                'refresh_token'  => $user->refresh_token,
            ]);
        }

        $user = User::create([
            'phone_number'  => $validated['phone_number'],
            'otp_code'      => env('APP_DEBUG') ? 1111 : $otp_code,
            'role'          => 'user',
            'refresh_token' => Str::random(64),
        ]);
        $access_token = JWTAuth::fromUser($user);

        // OTP Logic shall be added
        return response()->json([
            'message' => 'User registered.',
            'user' => $user,
            'access_token'   => $access_token,
            'refresh_token'  => $user->refresh_token,
        ], 201);
    }
}
