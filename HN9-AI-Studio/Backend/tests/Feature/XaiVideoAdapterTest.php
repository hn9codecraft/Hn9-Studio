<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StoryCharacterReferenceStatus;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryCharacterLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ConfiguresXai;
use Tests\TestCase;

final class XaiVideoAdapterTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use ConfiguresXai;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('videos');
        Storage::fake('images');
        Http::preventStrayRequests();
        $this->enableXai([
            'story_video.routing.preferred_order' => ['video.xai'],
        ]);
    }

    public function test_a_ten_second_text_clip_is_requested_without_provider_audio(): void
    {
        $this->fakeVideo('done');
        [$f, $plan, $unit] = $this->ready();

        $id = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', [
                'capability' => 'text_to_video',
                'aspect_ratio' => '16:9',
            ])
            ->assertCreated()
            ->json('data.generation.id');

        $this->finish($f, $plan, $unit, $id);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/videos/generations')) {
                return false;
            }
            $body = $request->data();

            return ($body['model'] ?? null) === 'grok-imagine-video-1.5-lite'
                && ($body['duration'] ?? null) === 10
                && ($body['resolution'] ?? null) === '720p'
                && ($body['generate_audio'] ?? null) === false
                && ($body['aspect_ratio'] ?? null) === '16:9'
                && $request->hasHeader('Authorization', 'Bearer '.self::XAI_TEST_KEY);
        });
        $job = StoryVideoGenerationJob::query()->where('uuid', $id)->firstOrFail();
        $this->assertSame('video.xai', $job->provider_key);
        $this->assertSame('video-bytes', Storage::disk('videos')->get($job->provider_metadata['storage']['path']));
    }

    public function test_image_to_video_sends_the_locked_reference_and_keeps_provenance(): void
    {
        $this->fakeVideo('done');
        [$f, $plan, $unit] = $this->ready();
        $unit->planScene->scene->forceFill(['characters' => ['Aarav']])->save();
        $this->lock($f);

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'image_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertSame('image', $preview['locks'][0]['sent_as'] ?? null, json_encode($preview['warnings'] ?? $preview));

        $id = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', ['capability' => 'image_to_video'])
            ->assertCreated()
            ->json('data.generation.id');
        $this->finish($f, $plan, $unit, $id);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();
            $url = $body['image']['url'] ?? '';

            return str_starts_with($url, 'data:image/')
                && ($body['generate_audio'] ?? null) === false
                && ($body['duration'] ?? null) === 10
                && ! array_key_exists('aspect_ratio', $body);
        });
        $job = StoryVideoGenerationJob::query()->where('uuid', $id)->firstOrFail();
        $this->assertNotSame([], $job->provider_metadata['lock_provenance']['locks']);
    }

    public function test_a_stale_preview_hash_makes_no_job_and_no_request(): void
    {
        [$f, $plan, $unit] = $this->ready();
        Http::fake();

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', [
                'capability' => 'text_to_video',
                'preview_hash' => str_repeat('ab', 32),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_prompt_preview_stale');

        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_retry_over_budget_does_not_call_the_provider_again(): void
    {
        $this->fakeVideo('failed');
        [$f, $plan, $unit] = $this->ready();
        $id = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->assertCreated()
            ->json('data.generation.id');
        $this->finish($f, $plan, $unit, $id);
        $this->assertSame('failed', StoryVideoGenerationJob::query()->where('uuid', $id)->value('status'));

        $f['workspace']->forceFill(['budget_amount' => 0.01, 'budget_currency' => 'INR'])->save();
        Http::fake();
        $before = StoryVideoGenerationJob::query()->count();

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', ['capability' => 'text_to_video'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        $this->assertSame($before, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    private function fakeVideo(string $status): void
    {
        Http::fake(function (Request $request) use ($status) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/videos/generations')) {
                return Http::response(['request_id' => 'req-xai-1']);
            }
            if (str_contains($request->url(), '/videos/req-xai-1')) {
                if ($status === 'failed') {
                    return Http::response(['status' => 'failed']);
                }

                return Http::response([
                    'status' => 'done',
                    'video' => ['url' => 'https://cdn.x.ai.test/clip.mp4', 'duration' => 10],
                ]);
            }
            if (str_contains($request->url(), 'cdn.x.ai.test')) {
                return Http::response('video-bytes');
            }

            return Http::response('unexpected', 500);
        });
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function finish(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit, string $id): void
    {
        $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->base($f, $plan, $unit).'/generations/'.$id)
            ->assertOk();
    }

    /**
     * @return array{0: array<string, mixed>, 1: StoryProductionPlan, 2: StoryProductionUnit}
     */
    private function ready(): array
    {
        $f = $this->productionFixture([10]);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion(
            $f['project'],
            $f['plan']->uuid,
            $f['version']->uuid,
            $f['user'],
        )['plan'];
        $plan->load('scenes.units.planScene.scene');
        $unit = $plan->scenes->sortBy('sequence')->first()->units->sortBy('sequence')->first();
        $unit->load('planScene.scene');

        return [$f, $plan, $unit];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function lock(array $f): void
    {
        $character = StoryCharacter::factory()->create([
            'story_workspace_id' => $f['workspace']->id,
            'name' => 'Aarav',
            'appearance' => 'blue coat',
        ]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $reference = StoryCharacterReference::factory()->create([
            'story_character_id' => $character->id,
            'status' => StoryCharacterReferenceStatus::Approved->value,
        ]);
        Storage::disk('images')->put($reference->path, $png);
        $character->forceFill(['approved_reference_id' => $reference->id])->save();
        app(StoryCharacterLockService::class)->lock($f['project'], $character->uuid, $f['user']);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function base(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }
}
