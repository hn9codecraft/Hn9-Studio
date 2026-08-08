<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class SettingsController extends Controller
{
    public function index(Request $request)
    {
        if (class_exists('App\\Services\\SettingsService')) {
            $svc = app('App\\Services\\SettingsService');
            if (method_exists($svc, 'get')) {
                return ApiResponse::success($svc->get($request->user()));
            }
            if (method_exists($svc, 'index')) {
                return ApiResponse::success($svc->index($request->user()));
            }
        }

        // No SettingsService and no `users.settings` column exist, so there is
        // nothing to read back yet.
        return ApiResponse::success([]);
    }

    public function update(Request $request)
    {
        $payload = $request->all();

        if (class_exists('App\\Services\\SettingsService')) {
            $svc = app('App\\Services\\SettingsService');
            if (method_exists($svc, 'update')) {
                return ApiResponse::success($svc->update($request->user(), $payload));
            }
        }

        // There is no `users.settings` column, so a write here could only fail
        // and be swallowed. Nothing is persisted until a SettingsService and its
        // storage exist.
        return ApiResponse::success([]);
    }

    public function notifications(Request $request)
    {
        if (class_exists('App\\Services\\SettingsService')) {
            $svc = app('App\\Services\\SettingsService');
            if (method_exists($svc, 'notifications')) {
                return ApiResponse::success($svc->notifications($request->user()));
            }
        }

        // No `users.notification_settings` column exists to read from.
        return ApiResponse::success([]);
    }

    public function updateNotifications(Request $request)
    {
        $payload = $request->all();

        if (class_exists('App\\Services\\SettingsService')) {
            $svc = app('App\\Services\\SettingsService');
            if (method_exists($svc, 'updateNotifications')) {
                return ApiResponse::success($svc->updateNotifications($request->user(), $payload));
            }
            if (method_exists($svc, 'update')) {
                return ApiResponse::success($svc->update($request->user(), ['notifications' => $payload]));
            }
        }

        // As above: no column to persist to, so nothing is written.
        return ApiResponse::success([]);
    }
}
