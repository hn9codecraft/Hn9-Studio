<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AiProvider;
use App\Models\User;
use App\Policies\AiProviderPolicy;
use App\Policies\ExportPolicy;
use App\Policies\StoryBiblePolicy;
use App\Policies\StoryCharacterPolicy;
use App\Policies\StoryCharacterReferencePolicy;
use App\Policies\StoryPlanPolicy;
use App\Policies\StoryProductionPlanPolicy;
use App\Policies\StoryReelPolicy;
use App\Policies\StoryScenePolicy;
use App\Policies\StoryStyleBiblePolicy;
use App\Policies\StoryStyleReferencePolicy;
use App\Policies\StoryWorkspacePolicy;
use App\Policies\UserPolicy;
use App\Story\Models\StoryBible;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryExport;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        AiProvider::class => AiProviderPolicy::class,
        User::class => UserPolicy::class,
        StoryWorkspace::class => StoryWorkspacePolicy::class,
        StoryBible::class => StoryBiblePolicy::class,
        StoryCharacter::class => StoryCharacterPolicy::class,
        StoryCharacterReference::class => StoryCharacterReferencePolicy::class,
        StoryStyleBible::class => StoryStyleBiblePolicy::class,
        StoryStyleReference::class => StoryStyleReferencePolicy::class,
        StoryPlan::class => StoryPlanPolicy::class,
        StoryProductionPlan::class => StoryProductionPlanPolicy::class,
        StoryReel::class => StoryReelPolicy::class,
        StoryScene::class => StoryScenePolicy::class,
        StoryExport::class => ExportPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
