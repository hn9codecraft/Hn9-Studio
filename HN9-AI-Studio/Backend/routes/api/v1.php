<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandBrainController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\GeneratedAssetController;
use App\Http\Controllers\Api\V1\GeneratedContentController;
use App\Http\Controllers\Api\V1\GenerationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectInputController;
use App\Http\Controllers\Api\V1\ProjectPromptController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\TraceController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkflowRunController;
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

    // Brand Brain
    Route::get('brand-brain', [BrandBrainController::class, 'show'])->name('brand-brain.show');
    Route::patch('brand-brain', [BrandBrainController::class, 'update'])->name('brand-brain.update');
    Route::post('projects/{uuid}/brand-insights', [BrandBrainController::class, 'insights'])->name('brand-brain.insights');

    // Project prompts
    Route::get('projects/{uuid}/prompts', [ProjectPromptController::class, 'index'])->name('projects.prompts.index');
    Route::post('projects/{uuid}/prompts', [ProjectPromptController::class, 'store'])->name('projects.prompts.store');
    Route::get('projects/{uuid}/prompts/{prompt_uuid}', [ProjectPromptController::class, 'show'])->name('projects.prompts.show');
    Route::delete('projects/{uuid}/prompts/{prompt_uuid}', [ProjectPromptController::class, 'destroy'])->name('projects.prompts.destroy');

    // Generation endpoints
    Route::post('projects/{uuid}/generate', [GenerationController::class, 'generate'])->name('projects.generate');
    Route::post('projects/{uuid}/generate/preview', [GenerationController::class, 'preview'])->name('projects.generate.preview');
    Route::get('projects/{uuid}/generation-history', [GenerationController::class, 'history'])->name('projects.generation.history');

    // Generated content
    Route::get('generated-content', [GeneratedContentController::class, 'index'])->name('generated-content.index');
    Route::get('generated-content/{uuid}', [GeneratedContentController::class, 'show'])->name('generated-content.show');
    Route::patch('generated-content/{uuid}', [GeneratedContentController::class, 'update'])->name('generated-content.update');
    Route::delete('generated-content/{uuid}', [GeneratedContentController::class, 'destroy'])->name('generated-content.destroy');
    Route::post('generated-content/{uuid}/favorite', [GeneratedContentController::class, 'favorite'])->name('generated-content.favorite');
    Route::delete('generated-content/{uuid}/favorite', [GeneratedContentController::class, 'unfavorite'])->name('generated-content.unfavorite');
    Route::post('generated-content/{uuid}/approve', [GeneratedContentController::class, 'approve'])->name('generated-content.approve');
    Route::post('generated-content/{uuid}/regenerate', [GeneratedContentController::class, 'regenerate'])->name('generated-content.regenerate');

    // Generated assets
    Route::get('generated-assets', [GeneratedAssetController::class, 'index'])->name('generated-assets.index');
    Route::get('generated-assets/{uuid}', [GeneratedAssetController::class, 'show'])->name('generated-assets.show');
    Route::patch('generated-assets/{uuid}', [GeneratedAssetController::class, 'update'])->name('generated-assets.update');
    Route::delete('generated-assets/{uuid}', [GeneratedAssetController::class, 'destroy'])->name('generated-assets.destroy');
    Route::post('generated-assets/{uuid}/favorite', [GeneratedAssetController::class, 'favorite'])->name('generated-assets.favorite');
    Route::post('generated-assets/{uuid}/unfavorite', [GeneratedAssetController::class, 'unfavorite'])->name('generated-assets.unfavorite');
    Route::post('generated-assets/{uuid}/cancel', [GeneratedAssetController::class, 'cancel'])->name('generated-assets.cancel');

    // Providers
    Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
    Route::get('providers/{uuid}', [ProviderController::class, 'show'])->name('providers.show');
    Route::patch('providers/{uuid}', [ProviderController::class, 'update'])->name('providers.update');
    Route::post('providers/{uuid}/enable', [ProviderController::class, 'enable'])->name('providers.enable');
    Route::post('providers/{uuid}/disable', [ProviderController::class, 'disable'])->name('providers.disable');
    Route::post('providers/{uuid}/test', [ProviderController::class, 'test'])->name('providers.test');

    Route::get('provider-settings', [ProviderController::class, 'settingsIndex'])->name('provider-settings.index');
    Route::get('provider-settings/{uuid}', [ProviderController::class, 'showSetting'])->name('provider-settings.show');
    Route::patch('provider-settings/{uuid}', [ProviderController::class, 'updateSetting'])->name('provider-settings.update');

    // Agents
    Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
    Route::get('agents/{agent_uuid}', [AgentController::class, 'show'])->name('agents.show');
    Route::get('workflows/{uuid}/agents', [AgentController::class, 'forWorkflow'])->name('workflows.agents');
    Route::get('projects/{uuid}/agents', [AgentController::class, 'forProject'])->name('projects.agents');

    // Dashboard
    Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
    Route::get('dashboard/projects', [DashboardController::class, 'projects'])->name('dashboard.projects');
    Route::get('dashboard/usage', [DashboardController::class, 'usage'])->name('dashboard.usage');
    Route::get('dashboard/costs', [DashboardController::class, 'costs'])->name('dashboard.costs');
    Route::get('dashboard/notifications', [DashboardController::class, 'notifications'])->name('dashboard.notifications');

    // Analytics
    Route::get('analytics/usage', [AnalyticsController::class, 'usage'])->name('analytics.usage');
    Route::get('analytics/performance', [AnalyticsController::class, 'performance'])->name('analytics.performance');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('settings/notifications', [SettingsController::class, 'notifications'])->name('settings.notifications');
    Route::patch('settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');

    // Exports
    Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
    Route::post('exports', [ExportController::class, 'store'])->name('exports.store');
    Route::get('exports/{uuid}', [ExportController::class, 'show'])->name('exports.show');
    Route::get('exports/{uuid}/download', [ExportController::class, 'download'])->name('exports.download');

    // System
    Route::get('system/metrics', [SystemController::class, 'metrics'])->name('system.metrics');
    Route::get('system/activity-logs', [ActivityLogController::class, 'index'])->name('system.activity-logs');
    Route::get('system/traces', [TraceController::class, 'index'])->name('system.traces');

    // Workflow runs
    Route::get('workflow-runs', [WorkflowRunController::class, 'index'])->name('workflow-runs.index');
    Route::get('workflow-runs/{uuid}', [WorkflowRunController::class, 'show'])->name('workflow-runs.show');
    Route::get('workflow-runs/{uuid}/timeline', [WorkflowRunController::class, 'timeline'])->name('workflow-runs.timeline');
    Route::get('workflow-runs/{uuid}/logs', [WorkflowRunController::class, 'logs'])->name('workflow-runs.logs');
    Route::post('workflow-runs/{uuid}/retry', [WorkflowRunController::class, 'retry'])->name('workflow-runs.retry');
    Route::post('workflow-runs/{uuid}/cancel', [WorkflowRunController::class, 'cancel'])->name('workflow-runs.cancel');
});
