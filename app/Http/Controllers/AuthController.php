<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
    }

    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', // 至少包含大小寫字母和數字
            ], [
                'password.min' => __('auth.password_min'),
                'password.regex' => __('auth.password_complexity'),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json(compact('user', 'token'), 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (!$token = JWTAuth::attempt($credentials)) {
            Log::warning('Login failed: Invalid credentials', ['email' => $request->email]);
            return response()->json(['error' => 'Unauthorized', 'message' => __('auth.invalid_credentials')], 401);
        }

        return $this->respondWithToken($token);
    }

    public function me()
    {
        return response()->json(auth()->user());
    }

    public function logout()
    {
        auth()->logout();
        return response()->json(['message' => __('auth.logout_success')]);
    }

    public function refresh()
    {
        try {
            return $this->respondWithToken(JWTAuth::refresh());
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            Log::error('Token refresh failed: Invalid token', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid Token', 'message' => __('auth.token_invalid')], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            Log::error('Token refresh failed: Token expired', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Token Expired', 'message' => __('auth.token_expired')], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            Log::error('Token refresh failed: JWT error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not refresh token', 'message' => __('auth.token_refresh_failed')], 500);
        }
    }

    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
        ]);
    }
}