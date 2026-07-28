<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the "api" middleware group and the "api.v1."
| route-name prefix (see bootstrap/app.php). This file is the v1 contract;
| new versions get their own file and never mutate this one.
|
*/

// Public infrastructure endpoints.
Route::get('health', HealthController::class)->name('health');

// Authenticated endpoints (Sanctum bearer token).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('user', fn (Request $request) => $request->user())->name('user');
});
