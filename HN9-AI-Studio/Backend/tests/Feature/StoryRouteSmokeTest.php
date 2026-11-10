<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class StoryRouteSmokeTest extends TestCase
{
    public function test_m13_through_m19_routes_require_auth_and_the_expected_throttle(): void
    {
        $sample = '00000000-0000-4000-8000-000000000001';
        $routes = [
            'api.v1.story.projects.cost.estimate' => 'throttle:60,1',
            'api.v1.story.projects.cost.summary' => null,
            'api.v1.story.projects.budget.update' => null,
            'api.v1.providers.index' => null,
            'api.v1.provider-credentials.update' => 'throttle:10,1',
            'api.v1.provider-credentials.test' => 'throttle:generation',
            'api.v1.story.templates.index' => null,
            'api.v1.story.projects.quick-start.estimate' => 'throttle:60,1',
            'api.v1.story.projects.quick-start.store' => 'throttle:generation',
            'api.v1.story.projects.production-plans.voice-timing.show' => 'throttle:60,1',
            'api.v1.story.projects.production-plans.voice-timing.apply' => 'throttle:30,1',
            'api.v1.story.projects.reels.renders.subtitles.index' => 'throttle:60,1',
            'api.v1.story.projects.reels.renders.subtitles.store' => 'throttle:30,1',
            'api.v1.story.projects.reels.renders.subtitles.file' => 'throttle:60,1',
            'api.v1.story.projects.reels.renders.subtitles.burn' => 'throttle:generation',
            'api.v1.story.projects.characters.lock' => 'throttle:30,1',
            'api.v1.story.projects.characters.unlock' => 'throttle:30,1',
            'api.v1.story.projects.character-locks.index' => 'throttle:60,1',
            'api.v1.story.projects.production-plans.units.prompt-preview' => 'throttle:60,1',
            'api.v1.story.projects.production-plans.units.prompt.update' => 'throttle:30,1',
            'api.v1.story.projects.production-plans.units.prompt.reset' => 'throttle:30,1',
            'api.v1.story.projects.reels.scenes.regenerate.prompt-preview' => 'throttle:60,1',
            'api.v1.story.projects.production-plans.units.attempts' => 'throttle:60,1',
            'api.v1.story.projects.production-plans.units.retry' => 'throttle:generation',
            'api.v1.story.projects.production-plans.units.retry.prompt-preview' => 'throttle:60,1',
            'api.v1.story.projects.bulk-approve.preview' => 'throttle:60,1',
            'api.v1.story.projects.bulk-approve.store' => 'throttle:30,1',
            'api.v1.story.projects.progress' => 'throttle:120,1',
            'api.v1.story.projects.production-plans.scenes.versions.index' => 'throttle:60,1',
            'api.v1.story.projects.production-plans.scenes.assemblies.restore' => 'throttle:60,1',
            'api.v1.story.projects.reels.scenes.versions.restore' => 'throttle:60,1',
            'api.v1.story.voice-languages' => null,
            'api.v1.notifications.read' => null,
            'api.v1.notifications.read-all' => null,
        ];

        foreach ($routes as $name => $throttle) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $name);
            if ($throttle !== null) {
                $this->assertContains($throttle, $middleware, $name);
            }
            if (! str_ends_with($name, '.retry') && ! str_contains($name, 'quick-start.store') && ! str_contains($name, 'subtitles.burn') && $name !== 'api.v1.provider-credentials.test') {
                $this->assertNotContains('throttle:generation', $middleware, $name);
            }
        }

        $this->postJson('/api/v1/story/projects/'.$sample.'/cost/estimate', [])->assertUnauthorized();
        $this->getJson('/api/v1/story/projects/'.$sample.'/progress')->assertUnauthorized();
        $this->postJson('/api/v1/story/projects/'.$sample.'/production-plans/'.$sample.'/units/'.$sample.'/retry', [])->assertUnauthorized();
        $this->getJson('/api/v1/story/voice-languages')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    }
}
