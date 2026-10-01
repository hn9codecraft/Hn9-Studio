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
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\ProjectActivityController;
use App\Http\Controllers\Api\V1\ProjectAssetController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectExportController;
use App\Http\Controllers\Api\V1\VideoController;
use App\Http\Controllers\Api\V1\ScriptController;
use App\Http\Controllers\Api\V1\ProjectInputController;
use App\Http\Controllers\Api\V1\ProjectPromptController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StoryBibleController;
use App\Http\Controllers\Api\V1\StoryCharacterController;
use App\Http\Controllers\Api\V1\StoryCharacterReferenceController;
use App\Http\Controllers\Api\V1\StoryController;
use App\Http\Controllers\Api\V1\StoryPlanController;
use App\Http\Controllers\Api\V1\StoryReelController;
use App\Http\Controllers\Api\V1\StoryAudioController;
use App\Http\Controllers\Api\V1\StoryReviewController;
use App\Http\Controllers\Api\V1\StoryContinuityController;
use App\Http\Controllers\Api\V1\StorySceneController;
use App\Http\Controllers\Api\V1\StoryStyleBibleController;
use App\Http\Controllers\Api\V1\StoryStyleReferenceController;
use App\Http\Controllers\Api\V1\StoryExportController;
use App\Http\Controllers\Api\V1\StoryFinalReviewController;
use App\Http\Controllers\Api\V1\StoryHistoryController;
use App\Http\Controllers\Api\V1\StoryRenderController;
use App\Http\Controllers\Api\V1\StoryTimelineController;
use App\Http\Controllers\Api\V1\StoryVideoEngineController;
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

// Public infrastructure and authentication endpoints.
Route::get('health', HealthController::class)->name('health');
Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

// Authenticated endpoints (Sanctum bearer token).
Route::middleware('auth:sanctum')->group(function (): void {
    // Auth module
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

    // Project scripts (manual drafts + AI-generated studio scripts)
    Route::get('projects/{uuid}/scripts', [ScriptController::class, 'index'])->name('projects.scripts.index');
    Route::post('projects/{uuid}/scripts', [ScriptController::class, 'store'])->name('projects.scripts.store');
    Route::post('projects/{uuid}/scripts/generate', [ScriptController::class, 'generate'])->name('projects.scripts.generate');
    Route::post('projects/{uuid}/scripts/{scriptUuid}/regenerate', [ScriptController::class, 'regenerate'])->name('projects.scripts.regenerate');
    Route::get('projects/{uuid}/scripts/{scriptUuid}', [ScriptController::class, 'show'])->name('projects.scripts.show');
    Route::patch('projects/{uuid}/scripts/{scriptUuid}', [ScriptController::class, 'update'])->name('projects.scripts.update');
    Route::delete('projects/{uuid}/scripts/{scriptUuid}', [ScriptController::class, 'destroy'])->name('projects.scripts.destroy');
    Route::post('projects/{uuid}/scripts/{scriptUuid}/submit-review', [ScriptController::class, 'submitReview'])->name('projects.scripts.submit-review');
    Route::post('projects/{uuid}/scripts/{scriptUuid}/approve', [ScriptController::class, 'approve'])->name('projects.scripts.approve');
    Route::post('projects/{uuid}/scripts/{scriptUuid}/needs-rework', [ScriptController::class, 'needsRework'])->name('projects.scripts.needs-rework');
    Route::get('projects/{uuid}/scripts/{scriptUuid}/review-history', [ScriptController::class, 'reviewHistory'])->name('projects.scripts.review-history');

    // Project image requests and real AI image generation
    Route::get('projects/{uuid}/images', [ImageController::class, 'index'])->name('projects.images.index');
    Route::post('projects/{uuid}/images', [ImageController::class, 'store'])->name('projects.images.store');
    Route::post('projects/{uuid}/images/generate', [ImageController::class, 'generate'])->name('projects.images.generate');
    Route::post('projects/{uuid}/images/{imageUuid}/regenerate', [ImageController::class, 'regenerate'])->name('projects.images.regenerate');
    Route::get('projects/{uuid}/images/{imageUuid}', [ImageController::class, 'show'])->name('projects.images.show');
    Route::get('projects/{uuid}/images/{imageUuid}/file', [ImageController::class, 'file'])->name('projects.images.file');
    Route::patch('projects/{uuid}/images/{imageUuid}', [ImageController::class, 'update'])->name('projects.images.update');
    Route::delete('projects/{uuid}/images/{imageUuid}', [ImageController::class, 'destroy'])->name('projects.images.destroy');
    Route::post('projects/{uuid}/images/{imageUuid}/submit-review', [ImageController::class, 'submitReview'])->name('projects.images.submit-review');
    Route::post('projects/{uuid}/images/{imageUuid}/approve', [ImageController::class, 'approve'])->name('projects.images.approve');
    Route::post('projects/{uuid}/images/{imageUuid}/needs-rework', [ImageController::class, 'needsRework'])->name('projects.images.needs-rework');
    Route::get('projects/{uuid}/images/{imageUuid}/review-history', [ImageController::class, 'reviewHistory'])->name('projects.images.review-history');

    // Project video requests and real AI video generation
    Route::get('projects/{uuid}/videos', [VideoController::class, 'index'])->name('projects.videos.index');
    Route::post('projects/{uuid}/videos', [VideoController::class, 'store'])->name('projects.videos.store');
    Route::post('projects/{uuid}/videos/generate', [VideoController::class, 'generate'])->name('projects.videos.generate');
    Route::post('projects/{uuid}/videos/{videoUuid}/regenerate', [VideoController::class, 'regenerate'])->name('projects.videos.regenerate');
    Route::get('projects/{uuid}/videos/{videoUuid}', [VideoController::class, 'show'])->name('projects.videos.show');
    Route::get('projects/{uuid}/videos/{videoUuid}/status', [VideoController::class, 'status'])->name('projects.videos.status');
    Route::get('projects/{uuid}/videos/{videoUuid}/file', [VideoController::class, 'file'])->name('projects.videos.file');
    Route::patch('projects/{uuid}/videos/{videoUuid}', [VideoController::class, 'update'])->name('projects.videos.update');
    Route::delete('projects/{uuid}/videos/{videoUuid}', [VideoController::class, 'destroy'])->name('projects.videos.destroy');
    Route::post('projects/{uuid}/videos/{videoUuid}/submit-review', [VideoController::class, 'submitReview'])->name('projects.videos.submit-review');
    Route::post('projects/{uuid}/videos/{videoUuid}/approve', [VideoController::class, 'approve'])->name('projects.videos.approve');
    Route::post('projects/{uuid}/videos/{videoUuid}/needs-rework', [VideoController::class, 'needsRework'])->name('projects.videos.needs-rework');
    Route::get('projects/{uuid}/videos/{videoUuid}/review-history', [VideoController::class, 'reviewHistory'])->name('projects.videos.review-history');

    // Project studio assets (catalog — not pipeline generated_assets)
    Route::get('projects/{uuid}/assets', [ProjectAssetController::class, 'index'])->name('projects.assets.index');
    Route::post('projects/{uuid}/assets', [ProjectAssetController::class, 'store'])->name('projects.assets.store');
    Route::get('projects/{uuid}/assets/{assetUuid}', [ProjectAssetController::class, 'show'])->name('projects.assets.show');
    Route::patch('projects/{uuid}/assets/{assetUuid}', [ProjectAssetController::class, 'update'])->name('projects.assets.update');
    Route::delete('projects/{uuid}/assets/{assetUuid}', [ProjectAssetController::class, 'destroy'])->name('projects.assets.destroy');

    // Project studio activity (read-only — written by real studio actions)
    Route::get('projects/{uuid}/activities', [ProjectActivityController::class, 'index'])->name('projects.activities.index');

    // Final assets, finalization, and real project export packages
    Route::get('projects/{uuid}/final-assets', [ProjectExportController::class, 'finalAssets'])->name('projects.final-assets.show');
    Route::post('projects/{uuid}/finalize', [ProjectExportController::class, 'finalize'])->name('projects.finalize');
    Route::post('projects/{uuid}/export', [ProjectExportController::class, 'store'])->name('projects.export.store');
    Route::get('projects/{uuid}/exports', [ProjectExportController::class, 'index'])->name('projects.exports.index');
    Route::get('projects/{uuid}/exports/{exportUuid}', [ProjectExportController::class, 'show'])->name('projects.exports.show');
    Route::get('projects/{uuid}/exports/{exportUuid}/download', [ProjectExportController::class, 'download'])->name('projects.exports.download');

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
    Route::get('dashboard/activity', [DashboardController::class, 'activity'])->name('dashboard.activity');
    Route::get('dashboard/analytics', [DashboardController::class, 'analytics'])->name('dashboard.analytics');
    Route::get('dashboard/projects', [DashboardController::class, 'projects'])->name('dashboard.projects');
    Route::get('dashboard/usage', [DashboardController::class, 'usage'])->name('dashboard.usage');
    Route::get('dashboard/costs', [DashboardController::class, 'costs'])->name('dashboard.costs');
    Route::get('dashboard/actions', [DashboardController::class, 'actions'])->name('dashboard.actions');
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

    // Project Story foundation (independent of M10 studio workflows)
    Route::get('story', [StoryController::class, 'entry'])->name('story.entry');
    Route::get('story/projects', [StoryController::class, 'projects'])->name('story.projects.index');
    Route::get('story/projects/{uuid}', [StoryController::class, 'show'])->name('story.projects.show');
    Route::get('story/projects/{uuid}/bible', [StoryBibleController::class, 'show'])->name('story.projects.bible.show');
    Route::post('story/projects/{uuid}/bible', [StoryBibleController::class, 'store'])->name('story.projects.bible.store');
    Route::patch('story/projects/{uuid}/bible', [StoryBibleController::class, 'update'])->name('story.projects.bible.update');
    Route::get('story/projects/{uuid}/characters', [StoryCharacterController::class, 'index'])->name('story.projects.characters.index');
    Route::post('story/projects/{uuid}/characters', [StoryCharacterController::class, 'store'])->name('story.projects.characters.store');
    Route::get('story/projects/{uuid}/characters/{characterUuid}', [StoryCharacterController::class, 'show'])->name('story.projects.characters.show');
    Route::patch('story/projects/{uuid}/characters/{characterUuid}', [StoryCharacterController::class, 'update'])->name('story.projects.characters.update');
    Route::delete('story/projects/{uuid}/characters/{characterUuid}', [StoryCharacterController::class, 'destroy'])->name('story.projects.characters.destroy');
    Route::get('story/projects/{uuid}/characters/{characterUuid}/references', [StoryCharacterReferenceController::class, 'index'])->name('story.projects.characters.references.index');
    Route::post('story/projects/{uuid}/characters/{characterUuid}/references/upload', [StoryCharacterReferenceController::class, 'upload'])->name('story.projects.characters.references.upload');
    Route::post('story/projects/{uuid}/characters/{characterUuid}/references/generate', [StoryCharacterReferenceController::class, 'generate'])->name('story.projects.characters.references.generate');
    Route::get('story/projects/{uuid}/characters/{characterUuid}/references/{referenceUuid}', [StoryCharacterReferenceController::class, 'show'])->name('story.projects.characters.references.show');
    Route::get('story/projects/{uuid}/characters/{characterUuid}/references/{referenceUuid}/file', [StoryCharacterReferenceController::class, 'file'])->name('story.projects.characters.references.file');
    Route::post('story/projects/{uuid}/characters/{characterUuid}/references/{referenceUuid}/submit-review', [StoryCharacterReferenceController::class, 'submitReview'])->name('story.projects.characters.references.submit-review');
    Route::post('story/projects/{uuid}/characters/{characterUuid}/references/{referenceUuid}/approve', [StoryCharacterReferenceController::class, 'approve'])->name('story.projects.characters.references.approve');
    Route::post('story/projects/{uuid}/characters/{characterUuid}/references/{referenceUuid}/reject', [StoryCharacterReferenceController::class, 'reject'])->name('story.projects.characters.references.reject');
    Route::post('story/projects/{uuid}/characters/{characterUuid}/references/{referenceUuid}/archive', [StoryCharacterReferenceController::class, 'archive'])->name('story.projects.characters.references.archive');
    Route::get('story/projects/{uuid}/style', [StoryStyleBibleController::class, 'show'])->name('story.projects.style.show');
    Route::post('story/projects/{uuid}/style', [StoryStyleBibleController::class, 'store'])->name('story.projects.style.store');
    Route::patch('story/projects/{uuid}/style', [StoryStyleBibleController::class, 'update'])->name('story.projects.style.update');
    Route::get('story/projects/{uuid}/style/references', [StoryStyleReferenceController::class, 'index'])->name('story.projects.style.references.index');
    Route::post('story/projects/{uuid}/style/references/upload', [StoryStyleReferenceController::class, 'upload'])->name('story.projects.style.references.upload');
    Route::post('story/projects/{uuid}/style/references/generate', [StoryStyleReferenceController::class, 'generate'])->name('story.projects.style.references.generate');
    Route::get('story/projects/{uuid}/style/references/{referenceUuid}', [StoryStyleReferenceController::class, 'show'])->name('story.projects.style.references.show');
    Route::get('story/projects/{uuid}/style/references/{referenceUuid}/file', [StoryStyleReferenceController::class, 'file'])->name('story.projects.style.references.file');
    Route::post('story/projects/{uuid}/style/references/{referenceUuid}/submit-review', [StoryStyleReferenceController::class, 'submitReview'])->name('story.projects.style.references.submit-review');
    Route::post('story/projects/{uuid}/style/references/{referenceUuid}/approve', [StoryStyleReferenceController::class, 'approve'])->name('story.projects.style.references.approve');
    Route::post('story/projects/{uuid}/style/references/{referenceUuid}/reject', [StoryStyleReferenceController::class, 'reject'])->name('story.projects.style.references.reject');
    Route::post('story/projects/{uuid}/style/references/{referenceUuid}/archive', [StoryStyleReferenceController::class, 'archive'])->name('story.projects.style.references.archive');
    Route::get('story/projects/{uuid}/plans', [StoryPlanController::class, 'index'])->name('story.projects.plans.index');
    Route::post('story/projects/{uuid}/plans', [StoryPlanController::class, 'store'])->name('story.projects.plans.store');
    Route::get('story/projects/{uuid}/plans/{planUuid}', [StoryPlanController::class, 'show'])->name('story.projects.plans.show');
    Route::post('story/projects/{uuid}/plans/{planUuid}/generate', [StoryPlanController::class, 'generate'])->name('story.projects.plans.generate');
    Route::post('story/projects/{uuid}/plans/{planUuid}/regenerate', [StoryPlanController::class, 'regenerate'])->name('story.projects.plans.regenerate');
    Route::get('story/projects/{uuid}/plans/{planUuid}/versions', [StoryPlanController::class, 'versions'])->name('story.projects.plans.versions');
    Route::post('story/projects/{uuid}/plans/{planUuid}/versions/{versionUuid}/materialize', [StoryReelController::class, 'materialize'])->name('story.projects.plans.versions.materialize');
    Route::get('story/projects/{uuid}/reels', [StoryReelController::class, 'index'])->name('story.projects.reels.index');
    Route::post('story/projects/{uuid}/reels', [StoryReelController::class, 'store'])->name('story.projects.reels.store');
    Route::post('story/projects/{uuid}/reels/reorder', [StoryReelController::class, 'reorder'])->name('story.projects.reels.reorder');
    Route::get('story/projects/{uuid}/reels/{reelUuid}', [StoryReelController::class, 'show'])->name('story.projects.reels.show');
    Route::patch('story/projects/{uuid}/reels/{reelUuid}', [StoryReelController::class, 'update'])->name('story.projects.reels.update');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/archive', [StoryReelController::class, 'archive'])->name('story.projects.reels.archive');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/versions', [StoryReviewController::class, 'reelVersions'])->name('story.projects.reels.versions');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/comments', [StoryReviewController::class, 'commentReel'])->name('story.projects.reels.comments.store');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/submit-review', [StoryReviewController::class, 'submitReel'])->name('story.projects.reels.submit-review');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/approve', [StoryReviewController::class, 'approveReel'])->name('story.projects.reels.approve');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/needs-rework', [StoryReviewController::class, 'reworkReel'])->name('story.projects.reels.needs-rework');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes', [StorySceneController::class, 'index'])->name('story.projects.reels.scenes.index');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes', [StorySceneController::class, 'store'])->name('story.projects.reels.scenes.store');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/reorder', [StorySceneController::class, 'reorder'])->name('story.projects.reels.scenes.reorder');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}', [StorySceneController::class, 'show'])->name('story.projects.reels.scenes.show');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/continuity', [StoryContinuityController::class, 'show'])->name('story.projects.reels.scenes.continuity');
    Route::patch('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}', [StorySceneController::class, 'update'])->name('story.projects.reels.scenes.update');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/duplicate', [StorySceneController::class, 'duplicate'])->name('story.projects.reels.scenes.duplicate');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/archive', [StorySceneController::class, 'archive'])->name('story.projects.reels.scenes.archive');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/versions', [StoryReviewController::class, 'sceneVersions'])->name('story.projects.reels.scenes.versions');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/preview', [StoryReviewController::class, 'scenePreview'])->name('story.projects.reels.scenes.preview');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/file', [StoryReviewController::class, 'sceneFile'])->name('story.projects.reels.scenes.file');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/comments', [StoryReviewController::class, 'commentScene'])->name('story.projects.reels.scenes.comments.store');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/submit-review', [StoryReviewController::class, 'submitScene'])->name('story.projects.reels.scenes.submit-review');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/approve', [StoryReviewController::class, 'approveScene'])->name('story.projects.reels.scenes.approve');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/needs-rework', [StoryReviewController::class, 'reworkScene'])->name('story.projects.reels.scenes.needs-rework');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/regenerate', [StoryReviewController::class, 'regenerateScene'])->name('story.projects.reels.scenes.regenerate');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/versions/{versionUuid}/edit', [StoryReviewController::class, 'editScene'])->name('story.projects.reels.scenes.versions.edit');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/versions/{versionUuid}/extend', [StoryReviewController::class, 'extendScene'])->name('story.projects.reels.scenes.versions.extend');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/versions/{versionUuid}', [StoryReviewController::class, 'versionStatus'])->name('story.projects.reels.scenes.versions.show');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/versions/{versionUuid}/file', [StoryReviewController::class, 'versionFile'])->name('story.projects.reels.scenes.versions.file');
    Route::get('story/audio/roles', [StoryAudioController::class, 'roles'])->name('story.audio.roles');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/audio', [StoryAudioController::class, 'index'])->name('story.projects.reels.scenes.audio.index');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/audio', [StoryAudioController::class, 'store'])->name('story.projects.reels.scenes.audio.store');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/audio/{audioUuid}', [StoryAudioController::class, 'show'])->name('story.projects.reels.scenes.audio.show');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/scenes/{sceneUuid}/audio/{audioUuid}/file', [StoryAudioController::class, 'file'])->name('story.projects.reels.scenes.audio.file');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/timeline', [StoryTimelineController::class, 'show'])->name('story.projects.reels.timeline.show');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/timeline/clips', [StoryTimelineController::class, 'place'])->name('story.projects.reels.timeline.clips.store');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/timeline/reorder', [StoryTimelineController::class, 'reorder'])->name('story.projects.reels.timeline.reorder');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/timeline/transitions', [StoryTimelineController::class, 'transition'])->name('story.projects.reels.timeline.transitions.store');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/timeline/clips/{clipUuid}/trim', [StoryTimelineController::class, 'trim'])->name('story.projects.reels.timeline.clips.trim');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/timeline/clips/{clipUuid}/split', [StoryTimelineController::class, 'split'])->name('story.projects.reels.timeline.clips.split');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/timeline/clips/{clipUuid}/replace', [StoryTimelineController::class, 'replace'])->name('story.projects.reels.timeline.clips.replace');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/timeline/clips/{clipUuid}/duplicate', [StoryTimelineController::class, 'duplicate'])->name('story.projects.reels.timeline.clips.duplicate');
    Route::delete('story/projects/{uuid}/reels/{reelUuid}/timeline/clips/{clipUuid}', [StoryTimelineController::class, 'destroy'])->name('story.projects.reels.timeline.clips.destroy');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/renders', [StoryRenderController::class, 'store'])->name('story.projects.reels.renders.store');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/renders/{renderUuid}', [StoryRenderController::class, 'show'])->name('story.projects.reels.renders.show');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/renders/{renderUuid}/file', [StoryRenderController::class, 'file'])->name('story.projects.reels.renders.file');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/renders/{renderUuid}/reviews', [StoryFinalReviewController::class, 'show'])->name('story.projects.reels.renders.reviews.show');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/renders/{renderUuid}/submit-review', [StoryFinalReviewController::class, 'submit'])->name('story.projects.reels.renders.submit-review');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/renders/{renderUuid}/approve', [StoryFinalReviewController::class, 'approve'])->name('story.projects.reels.renders.approve');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/renders/{renderUuid}/needs-rework', [StoryFinalReviewController::class, 'rework'])->name('story.projects.reels.renders.needs-rework');
    Route::post('story/projects/{uuid}/reels/{reelUuid}/renders/{renderUuid}/exports', [StoryExportController::class, 'store'])->name('story.projects.reels.renders.exports.store');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/exports/{exportUuid}', [StoryExportController::class, 'show'])->name('story.projects.reels.exports.show');
    Route::get('story/projects/{uuid}/reels/{reelUuid}/exports/{exportUuid}/download', [StoryExportController::class, 'download'])->name('story.projects.reels.exports.download');
    Route::get('story/projects/{uuid}/history', [StoryHistoryController::class, 'index'])->name('story.projects.history.index');
    Route::get('story/capabilities', [StoryController::class, 'capabilities'])->name('story.capabilities.index');
    Route::get('story/video/capabilities', [StoryVideoEngineController::class, 'capabilities'])->name('story.video.capabilities');
    Route::get('story/video/providers', [StoryVideoEngineController::class, 'providers'])->name('story.video.providers');
    Route::post('story/projects/{uuid}/video/validate', [StoryVideoEngineController::class, 'validateCompatibility'])->name('story.projects.video.validate');
    Route::post('story/projects/{uuid}/video/jobs', [StoryVideoEngineController::class, 'prepareJob'])->name('story.projects.video.jobs.store');
    Route::post('story/projects/{uuid}/video/generate', [StoryVideoEngineController::class, 'generate'])->name('story.projects.video.generate');
    Route::get('story/projects/{uuid}/video/jobs/{jobUuid}', [StoryVideoEngineController::class, 'showJob'])->name('story.projects.video.jobs.show');
    Route::get('story/projects/{uuid}/video/jobs/{jobUuid}/file', [StoryVideoEngineController::class, 'file'])->name('story.projects.video.jobs.file');

    // Workflow runs
    Route::get('workflow-runs', [WorkflowRunController::class, 'index'])->name('workflow-runs.index');
    Route::get('workflow-runs/{uuid}', [WorkflowRunController::class, 'show'])->name('workflow-runs.show');
    Route::get('workflow-runs/{uuid}/timeline', [WorkflowRunController::class, 'timeline'])->name('workflow-runs.timeline');
    Route::get('workflow-runs/{uuid}/logs', [WorkflowRunController::class, 'logs'])->name('workflow-runs.logs');
    Route::post('workflow-runs/{uuid}/retry', [WorkflowRunController::class, 'retry'])->name('workflow-runs.retry');
    Route::post('workflow-runs/{uuid}/cancel', [WorkflowRunController::class, 'cancel'])->name('workflow-runs.cancel');
});
