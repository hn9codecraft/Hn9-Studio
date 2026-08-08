<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('Invalid credentials', 'auth.invalid', 401);
        }

        $token = $user->createToken('api-token');

        return ApiResponse::success([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return ApiResponse::success(['message' => 'Logged out']);
    }

    public function user(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    public function profile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->fill($request->validated())->save();

        return ApiResponse::success(new UserResource($user->refresh()));
    }

    public function password(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return ApiResponse::error('Current password is incorrect', 'auth.password_incorrect', 422);
        }

        $user->password = $validated['new_password'];
        $user->save();

        return ApiResponse::success(['message' => 'Password updated']);
    }
}
