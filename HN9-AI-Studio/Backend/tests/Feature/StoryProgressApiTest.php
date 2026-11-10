<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Models\StoryBible;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryTimeline;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ContractStoryVideoAdapter;
use Tests\TestCase;

final class StoryProgressApiTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_progress_reports_stages_a_blocked_unit_and_a_live_render(): void
    {
        $f = $this->productionFixture([10]);
        StoryBible::query()->updateOrCreate(
            ['story_workspace_id' => $f['workspace']->id],
            ['concept' => 'A paper boat'],
        );
        $before = StoryVideoGenerationJob::query()->count();

        $body = $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->url($f))
            ->assertOk()
            ->json('data');
        $this->assertIsInt($body['percent']);
        $this->assertGreaterThanOrEqual(0, $body['percent']);
        $this->assertLessThanOrEqual(100, $body['percent']);
        $this->assertSame(
            ['story', 'cast', 'style', 'plan', 'production', 'sound', 'timeline', 'render', 'review'],
            array_column($body['stages'], 'key'),
        );
        $stages = collect($body['stages'])->keyBy('key');
        $this->assertSame(1, $stages['story']['done']);
        $this->assertSame(1, $stages['plan']['done']);
        $this->assertSame($before, StoryVideoGenerationJob::query()->count());
        $encoded = json_encode($body);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('secret', $encoded);
        $this->assertStringNotContainsString('\\', $encoded);
        Http::assertNothingSent();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(StoryProgressService::class)->build($f['project']);
        $this->assertLessThanOrEqual(45, count(DB::getQueryLog()));

        \Illuminate\Support\Facades\Cache::flush();
        DB::flushQueryLog();
        $this->getJson($this->url($f))->assertOk();
        $first = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson($this->url($f))->assertOk();
        $this->assertLessThan($first, count(DB::getQueryLog()));

        [$plan, $unit] = $this->plan($f);
        app(StoryCapabilityRouterInterface::class)->register(new ContractStoryVideoAdapter);
        $this->actingAs($f['user'], 'sanctum')->postJson(
            '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid.'/generate',
            ['capability' => 'text_to_video'],
        )->assertCreated();
        StoryVideoGenerationJob::query()->orderByDesc('id')->first()?->forceFill([
            'status' => 'failed',
            'error_code' => 'UPSTREAM_ERROR',
            'failed_at' => now(),
        ])->save();
        \Illuminate\Support\Facades\Cache::flush();
        $blocked = $this->actingAs($f['user'], 'sanctum')->getJson($this->url($f))->assertOk()->json('data');
        $production = collect($blocked['stages'])->firstWhere('key', 'production');
        $this->assertSame('blocked', $production['status']);
        $this->assertStringContainsString('need a retry', (string) $production['hint']);

        $render = $this->render($f, 'rendering', now()->subSeconds(90));
        \Illuminate\Support\Facades\Cache::flush();
        $live = $this->actingAs($f['user'], 'sanctum')->getJson($this->url($f))->assertOk()->json('data');
        $this->assertSame('rendering', $live['render']['status']);
        $this->assertSame($render->uuid, $live['render']['render_uuid']);
        $this->assertGreaterThanOrEqual(80, $live['render']['elapsed_seconds']);
        $this->assertTrue($live['active']);

        $render->forceFill([
            'status' => 'failed',
            'error_message' => 'C:\\renders\\secret\\reel.mp4',
            'started_at' => now()->subMinute(),
        ])->save();
        \Illuminate\Support\Facades\Cache::flush();
        $failed = $this->actingAs($f['user'], 'sanctum')->getJson($this->url($f))->assertOk()->json('data');
        $this->assertSame('The render failed.', $failed['render']['error']);
        $this->assertStringNotContainsString('secret', json_encode($failed['render']));
        $this->assertNotNull($failed['render']['finished_at']);
    }

    public function test_progress_requires_the_project_owner(): void
    {
        $f = $this->productionFixture([10]);
        $this->getJson($this->url($f))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($this->url($f))->assertForbidden();
        $this->assertContains('throttle:120,1', Route::getRoutes()->getByName('api.v1.story.projects.progress')->gatherMiddleware());
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{0: StoryProductionPlan, 1: StoryProductionUnit}
     */
    private function plan(array $f): array
    {
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $f['user'])['plan'];
        $plan->load('scenes.units');
        $unit = $plan->scenes->sortBy('sequence')->first()->units->sortBy('sequence')->first();

        return [$plan, $unit];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function render(array $f, string $status, mixed $started): StoryFinalRender
    {
        $timelineId = StoryTimeline::query()->where('story_reel_id', $f['reel']->id)->value('id');
        if ($timelineId === null) {
            $timelineId = StoryTimeline::query()->create(['story_reel_id' => $f['reel']->id])->id;
        }

        return StoryFinalRender::query()->create([
            'story_timeline_id' => $timelineId,
            'story_reel_id' => $f['reel']->id,
            'requested_by' => $f['user']->id,
            'status' => $status,
            'queued_at' => now()->subMinutes(2),
            'started_at' => $started,
            'timeline_version' => hash('sha256', 'progress-'.$f['reel']->id),
            'timeline_snapshot' => ['clips' => []],
        ]);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function url(array $f): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/progress';
    }
}
