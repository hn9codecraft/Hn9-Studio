<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function read(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $notification = $user->notifications()->whereKey($id)->first();
        if (! $notification instanceof DatabaseNotification) {
            abort(404);
        }
        $notification->markAsRead();

        return ApiResponse::success(['id' => $notification->id, 'read_at' => $notification->fresh()?->read_at?->toIso8601String()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $user->unreadNotifications->markAsRead();

        return ApiResponse::success(['unread_count' => 0]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
