<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StoryCharacterReferenceStatus;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryCharacterLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ContractStoryVideoAdapter;
use Tests\TestCase;

final class StoryLockedGenerationTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use RefreshDatabase;

    private ContractStoryVideoAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('images');
        $this->adapter = new ContractStoryVideoAdapter;
        app(StoryCapabilityRouterInterface::class)->register($this->adapter);
    }

    public function test_a_request_without_a_lock_or_edit_keeps_the_old_prompt(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $scene = $unit->planScene->scene;
        $expected = trim(implode("\n\n", array_filter([
            $scene->visual_prompt,
            $scene->motion_prompt,
            $scene->story,
        ])));

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->assertCreated();

        $job = StoryVideoGenerationJob::query()->sole();
        $this->assertSame($expected, $job->request_payload['prompt']);
        $this->assertStringNotContainsString('Keep these characters', (string) $job->request_payload['prompt']);
        $this->assertSame([], $job->provider_metadata['lock_provenance']['locks']);
        Http::assertNothingSent();
    }

    public function test_a_lock_is_appended_and_trimmed_to_the_provider_limit(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $this->lockAarav($f);
        $this->adapter->maxPrompt = 160;
        $scene = $unit->planScene->scene;
        $scene->forceFill([
            'story' => str_repeat('story words ', 30),
            'motion_prompt' => str_repeat('motion words ', 20),
            'visual_prompt' => str_repeat('visual words ', 20),
        ])->save();

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('Keep these characters exactly the same in every shot:', $preview['prompt']['final']);
        $this->assertStringContainsString('blue coat', $preview['prompt']['lock_block']);
        $this->assertLessThanOrEqual(160, $preview['prompt']['length']);
        $this->assertContains('prompt_trimmed', $preview['warnings']);
        $this->assertContains('references_not_supported_by_mode', $preview['warnings']);
        $this->assertSame('text', $preview['locks'][0]['sent_as']);

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', [
                'capability' => 'text_to_video',
                'preview_hash' => $preview['preview_hash'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.generation.lock_status', 'applied');

        $this->actingAs($f['user'], 'sanctum')
            ->deleteJson('/api/v1/story/projects/'.$f['project']->uuid.'/characters/'.$preview['locks'][0]['character_uuid'].'/lock')
            ->assertOk();

        $job = StoryVideoGenerationJob::query()->sole();
        $this->assertSame('changed_since', (new \App\Http\Resources\StoryProductionUnitGenerationResource($job->fresh()))->resolve()['lock_status']);
    }

    public function test_image_mode_sends_the_locked_reference_when_the_user_gave_none(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $reference = $this->lockAarav($f);
        $this->adapter->referenceLimit = 1;

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'image_to_video'])
            ->assertOk()
            ->json('data');

        $this->assertSame('image', $preview['locks'][0]['sent_as']);
        $this->assertSame($reference->uuid, $preview['inputs'][0]['asset_id']);
    }

    public function test_extra_locks_are_listed_and_reference_mode_stops_at_the_adapter_limit(): void
    {
        config(['story_character_lock.max_locked_per_scene' => 1]);
        [$f, $plan, $unit] = $this->ready();
        $scene = $unit->planScene->scene;
        $scene->forceFill(['characters' => ['aarav', 'Maya']])->save();
        $aarav = $this->lockNamed($f, 'Aarav', 'blue coat');
        $maya = $this->lockNamed($f, 'Maya', 'red scarf');

        $capped = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertSame('text', $capped['locks'][0]['sent_as']);
        $this->assertSame($aarav->uuid, $capped['locks'][0]['reference_uuid']);
        $this->assertSame('not_sent', $capped['locks'][1]['sent_as']);
        $this->assertSame('too_many', $capped['locks'][1]['reason']);
        $this->assertSame($maya->uuid, $capped['locks'][1]['reference_uuid']);

        config(['story_character_lock.max_locked_per_scene' => 4]);
        $this->adapter->referenceLimit = 1;
        $one = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'reference_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $one['inputs']);
        $this->assertSame('reference_image', $one['inputs'][0]['type']);
        $this->assertSame($aarav->uuid, $one['inputs'][0]['asset_id']);
        $this->assertSame('reference_image', $one['locks'][0]['sent_as']);

        $this->adapter->referenceLimit = 2;
        $both = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'reference_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $both['inputs']);
        $this->assertSame($maya->uuid, $both['inputs'][1]['asset_id']);
        $this->assertSame('reference_image', $both['locks'][1]['sent_as']);
    }

    public function test_a_lock_block_longer_than_the_limit_is_shortened_without_dropping_the_character(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $this->adapter->maxPrompt = 80;
        $this->lockNamed($f, 'Aarav', str_repeat('indigo coat ', 30));

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');

        $this->assertContains('lock_too_long', $preview['warnings']);
        $this->assertLessThanOrEqual(80, $preview['prompt']['length']);
        $this->assertStringContainsString('Aarav', $preview['prompt']['lock_block']);
        $this->assertStringContainsString('Keep these characters exactly the same in every shot:', $preview['prompt']['final']);
    }

    public function test_a_user_image_is_kept_and_a_foreign_reference_is_rejected(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $locked = $this->lockNamed($f, 'Aarav', 'blue coat');
        $picture = $this->lockNamed($f, 'Leela', 'green hat');
        $this->adapter->referenceLimit = 1;

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', [
                'capability' => 'image_to_video',
                'inputs' => [['type' => 'image', 'asset_id' => $picture->uuid]],
            ])
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $preview['inputs']);
        $this->assertSame($picture->uuid, $preview['inputs'][0]['asset_id']);
        $this->assertNotSame($locked->uuid, $preview['inputs'][0]['asset_id']);

        $other = $this->productionFixture([10]);
        $foreign = $this->lockNamed($other, 'Nila', 'gold ring');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', [
                'capability' => 'image_to_video',
                'inputs' => [['type' => 'image', 'asset_id' => $foreign->uuid]],
            ])
            ->assertStatus(422);
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_scene_regenerate_preview_is_free_and_stores_lock_provenance(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $this->lockNamed($f, 'Aarav', 'blue coat');
        $scene = $unit->planScene->scene;
        $url = '/api/v1/story/projects/'.$f['project']->uuid.'/reels/'.$f['reel']->uuid.'/scenes/'.$scene->uuid;
        $versions = StorySceneVersion::query()->count();

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($url.'/regenerate/prompt-preview', [
                'mode' => 'text',
                'prompt' => 'A paper boat at dusk',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
        $this->assertSame($versions, StorySceneVersion::query()->count());
        Http::assertNothingSent();
        $this->assertSame('A paper boat at dusk', $preview['prompt']['base']);
        $this->assertStringContainsString('Keep these characters exactly the same in every shot:', $preview['prompt']['final']);
        $this->assertStringContainsString('blue coat', $preview['prompt']['lock_block']);

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($url.'/regenerate', [
                'mode' => 'text',
                'prompt' => 'A paper boat at dusk',
                'preview_hash' => $preview['preview_hash'],
            ])
            ->assertCreated();

        $job = StoryVideoGenerationJob::query()->sole();
        $this->assertStringContainsString('A paper boat at dusk', (string) $job->request_payload['prompt']);
        $this->assertStringContainsString('Keep these characters exactly the same in every shot:', (string) $job->request_payload['prompt']);
        $this->assertNotSame([], $job->provider_metadata['lock_provenance']['locks']);
        Http::assertNothingSent();

        $after = StorySceneVersion::query()->count();
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($url.'/regenerate', [
                'mode' => 'text',
                'prompt' => 'A paper boat at dusk',
                'preview_hash' => str_repeat('cd', 32),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_prompt_preview_stale');
        $this->assertSame($after, StorySceneVersion::query()->count());
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
    }

    /**
     * @return array{0: array<string, mixed>, 1: StoryProductionPlan, 2: StoryProductionUnit}
     */
    private function ready(): array
    {
        $f = $this->productionFixture([10]);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $f['user'])['plan'];
        $plan->load('scenes.units.planScene.scene');
        $unit = $plan->scenes->sortBy('sequence')->first()->units->sortBy('sequence')->first();
        $unit->load('planScene.scene');

        return [$f, $plan, $unit];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function lockAarav(array $f): StoryCharacterReference
    {
        return $this->lockNamed($f, 'Aarav', 'blue coat');
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function lockNamed(array $f, string $name, string $appearance): StoryCharacterReference
    {
        $character = StoryCharacter::factory()->create([
            'story_workspace_id' => $f['workspace']->id,
            'name' => $name,
            'appearance' => $appearance,
        ]);
        $reference = StoryCharacterReference::factory()->create([
            'story_character_id' => $character->id,
            'status' => StoryCharacterReferenceStatus::Approved->value,
        ]);
        Storage::disk('images')->put($reference->path, 'image-bytes');
        $character->forceFill(['approved_reference_id' => $reference->id])->save();
        app(StoryCharacterLockService::class)->lock($f['project'], $character->uuid, $f['user']);

        return $reference;
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function base(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }
}
