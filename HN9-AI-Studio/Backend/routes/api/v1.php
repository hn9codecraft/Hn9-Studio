<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GeneratedContentController;
use App\Http\Controllers\Api\V1\GenerationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectInputController;
use App\Http\Controllers\Api\V1\UserController;
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
    // Auth module
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/user', [AuthController::class, 'user'])->name('auth.user');
    Route::patch('auth/profile', [AuthController::class, 'profile'])->name('auth.profile');
    Route::patch('auth/password', [AuthController::class, 'password'])->name('auth.password');

    // User management
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{uuid}', [UserController::class, 'show'])->name('users.show');
    Route::patch('users/{uuid}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{uuid}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{uuid}/restore', [UserController::class, 'restore'])->name('users.restore');

    // Project management
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{uuid}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{uuid}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{uuid}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('projects/{uuid}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('projects/{uuid}/restore', [ProjectController::class, 'restore'])->name('projects.restore');

    // Project inputs
    Route::get('projects/{uuid}/inputs', [ProjectInputController::class, 'index'])->name('projects.inputs.index');
    Route::post('projects/{uuid}/inputs', [ProjectInputController::class, 'store'])->name('projects.inputs.store');

    // Generation endpoints
    Route::post('projects/{uuid}/generate', [GenerationController::class, 'generate'])->name('projects.generate');
    Route::post('projects/{uuid}/generate/preview', [GenerationController::class, 'preview'])->name('projects.generate.preview');
    Route::get('projects/{uuid}/generation-history', [GenerationController::class, 'history'])->name('projects.generation.history');

    // Generated content
    Route::get('generated-content', [GeneratedContentController::class, 'index'])->name('generated-content.index');
    Route::get('generated-content/{uuid}', [GeneratedContentController::class, 'show'])->name('generated-content.show');
    Route::delete('generated-content/{uuid}', [GeneratedContentController::class, 'destroy'])->name('generated-content.destroy');
    Route::post('generated-content/{uuid}/favorite', [GeneratedContentController::class, 'favorite'])->name('generated-content.favorite');
    Route::delete('generated-content/{uuid}/favorite', [GeneratedContentController::class, 'unfavorite'])->name('generated-content.unfavorite');
    Route::post('generated-content/{uuid}/regenerate', [GeneratedContentController::class, 'regenerate'])->name('generated-content.regenerate');
});
