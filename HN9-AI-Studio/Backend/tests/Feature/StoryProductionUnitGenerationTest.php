<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryProductionUnitGenerationServiceInterface;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryProductionUnitGenerationService;
use App\Story\Video\StoryCapabilityRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ContractStoryVideoAdapter;
use Tests\TestCase;

final class StoryProductionUnitGenerationTest extends TestCase
{
    use BuildsProductionPlanFixtures, RefreshDatabase;

    private ContractStoryVideoAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('videos');
        Storage::fake('images');
        $this->adapter = new ContractStoryVideoAdapter;
        app(StoryCapabilityRouterInterface::class)->register($this->adapter);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_one_unit_is_submitted_at_its_own_length_and_the_scene_is_not_one_job(): void
    {
        [$f, $plan] = $this->planned([17]);
        $units = $this->units($plan);
        $this->assertSame([10, 7], array_map(static fn (StoryProductionUnit $unit): int => $unit->duration_seconds, $units));

        $this->adapter->completion = 'sync';
        $first = $this->generate($f, $plan, $units[0])->assertCreated()->json('data');
        $this->refreshJob($f, $plan, $units[0], $first['generation']['id'])->assertOk()->assertJsonPath('data.generation.output_available', true);

        $this->assertSame([10], $this->adapter->submittedDurations);
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
        $job = StoryVideoGenerationJob::query()->sole();
        $this->assertSame($units[0]->id, $job->story_production_unit_id);
        $this->assertSame(10, $job->request_payload['duration_seconds']);
        $this->assertSame(10, $job->request_payload['metadata']['context']['requested_duration_seconds']);
        $this->assertNull($job->request_payload['metadata']['context']['continuity']['previous_unit']);
        $this->assertTrue(Storage::disk('videos')->exists($job->provider_metadata['storage']['path']));
        $this->assertSame('videos', $job->provider_metadata['storage']['disk']);
    }

    public function test_a_remainder_unit_stays_seven_seconds(): void
    {
        [$f, $plan] = $this->planned([17]);
        $units = $this->units($plan);
        $this->adapter->completion = 'sync';

        $created = $this->generate($f, $plan, $units[1], ['duration_seconds' => 30, 'prompt' => 'ignore this prompt'])->assertCreated();
        $this->refreshJob($f, $plan, $units[1], $created->json('data.generation.id'))->assertJsonPath('data.generation.output_available', true);

        $this->assertSame([7], $this->adapter->submittedDurations);
        $this->assertSame(7, StoryVideoGenerationJob::query()->sole()->request_payload['duration_seconds']);
        $this->assertStringNotContainsString('ignore this prompt', json_encode(StoryVideoGenerationJob::query()->sole()->request_payload));
    }

    public function test_polling_callback_and_sync_completion_use_the_same_engine(): void
    {
        [$f, $plan] = $this->planned([10, 10, 10]);
        $units = $this->units($plan);

        $this->adapter->completion = 'poll';
        $poll = $this->generate($f, $plan, $units[0])->json('data.generation.id');
        $this->refreshJob($f, $plan, $units[0], $poll)->assertJsonPath('data.generation.status', 'processing')->assertJsonPath('data.generation.output_available', false);
        $this->refreshJob($f, $plan, $units[0], $poll)->assertJsonPath('data.generation.status', 'completed')->assertJsonPath('data.generation.output_available', true);

        $this->adapter->completion = 'callback';
        $callback = $this->generate($f, $plan, $units[1])->json('data.generation.id');
        $this->refreshJob($f, $plan, $units[1], $callback)->assertJsonPath('data.generation.status', 'processing');
        $this->adapter->callbackReceived = true;
        $this->refreshJob($f, $plan, $units[1], $callback)->assertJsonPath('data.generation.output_available', true);

        $this->adapter->completion = 'sync';
        $sync = $this->generate($f, $plan, $units[2])->json('data.generation.id');
        $this->refreshJob($f, $plan, $units[2], $sync)->assertJsonPath('data.generation.output_available', true);

        $this->assertSame([10, 10, 10], $this->adapter->submittedDurations);
        $this->assertSame(3, StoryVideoGenerationJob::query()->count());
    }

    public function test_the_same_intent_is_reused_and_a_new_intent_is_a_new_attempt(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];

        $first = $this->generate($f, $plan, $unit, ['intent' => 'take-1'])->assertCreated()->json('data.generation.id');
        $again = $this->generate($f, $plan, $unit, ['intent' => 'take-1'])->assertOk()->json('data');
        $second = $this->generate($f, $plan, $unit, ['intent' => 'take-2'])->assertCreated()->json('data.generation.id');

        $this->assertFalse($again['created']);
        $this->assertSame($first, $again['generation']['id']);
        $this->assertNotSame($first, $second);
        $this->assertSame(2, $this->adapter->submits);
        $this->assertSame(2, StoryVideoGenerationJob::query()->count());
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $unit).'/generations')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_repeated_status_read_does_not_submit_again(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $id = $this->generate($f, $plan, $unit)->json('data.generation.id');

        $this->refreshJob($f, $plan, $unit, $id);
        $this->refreshJob($f, $plan, $unit, $id);
        $this->refreshJob($f, $plan, $unit, $id);

        $this->assertSame(1, $this->adapter->submits);
    }

    public function test_a_lost_create_race_returns_the_job_that_won(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $key = 'production-unit:'.$unit->uuid.':initial';
        $winner = StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $f['workspace']->id,
            'story_reel_id' => $f['reel']->id,
            'story_scene_id' => $f['scenes'][0]->id,
            'capability' => 'text_to_video',
            'provider_key' => 'video.contract',
            'status' => 'submitted',
            'operation_id' => 'contract-op-existing',
            'idempotency_key' => $key,
            'request_payload' => ['duration_seconds' => 10, 'capability' => 'text_to_video', 'metadata' => []],
        ]);
        $winner->forceFill(['story_production_unit_id' => $unit->id])->save();

        $result = $this->generate($f, $plan, $unit)->assertOk()->json('data');

        $this->assertFalse($result['created']);
        $this->assertSame($winner->uuid, $result['generation']['id']);
        $this->assertSame(0, $this->adapter->submits);
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
    }

    public function test_validation_failures_do_not_call_the_provider(): void
    {
        [$f, $plan] = $this->planned([17]);
        $units = $this->units($plan);
        $scene = $f['scenes'][0];

        $this->adapter->durations = [10];
        $this->generate($f, $plan, $units[1])->assertStatus(422)->assertJsonPath('error_code', 'VIDEO_CAPABILITY_NOT_AVAILABLE');

        $this->adapter->durations = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $scene->forceFill(['visual_prompt' => '', 'story' => ''])->save();
        $this->generate($f, $plan, $units[0])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_INPUT');
        $scene->forceFill(['visual_prompt' => 'Wide shot', 'story' => 'A story'])->save();

        $f['version']->forceFill(['approved_at' => null, 'approved_by' => null])->save();
        $this->generate($f, $plan, $units[0])->assertStatus(422);
        $f['version']->forceFill(['approved_at' => now(), 'approved_by' => $f['user']->id])->save();

        $scene->forceFill(['status' => 'archived'])->save();
        $this->generate($f, $plan, $units[0])->assertStatus(422);
        $scene->forceFill(['status' => 'active'])->save();

        $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $units[0]).'/generate', ['capability' => 'video_edit'])->assertStatus(422);
        $this->generate($f, $plan, $units[0], ['aspect_ratio' => '21:9'])->assertStatus(422);

        $this->assertSame(0, $this->adapter->submits);
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_a_reference_from_another_project_is_rejected_before_submission(): void
    {
        [$f, $plan] = $this->planned([10]);
        $other = $this->productionFixture([10]);
        $reference = $this->referenceFor($other['workspace']->id);

        $this->generate($f, $plan, $this->units($plan)[0], [
            'capability' => 'image_to_video',
            'inputs' => [['type' => 'image', 'asset_id' => $reference->uuid]],
        ])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_INPUT');

        $this->assertSame(0, $this->adapter->submits);
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_an_owned_reference_is_accepted_for_image_and_reference_modes(): void
    {
        [$f, $plan] = $this->planned([10]);
        $reference = $this->referenceFor($f['workspace']->id);
        $unit = $this->units($plan)[0];
        $this->adapter->completion = 'sync';

        $image = $this->generate($f, $plan, $unit, [
            'capability' => 'image_to_video',
            'intent' => 'image',
            'inputs' => [['type' => 'image', 'asset_id' => $reference->uuid]],
        ])->assertCreated()->json('data.generation.id');
        $referenceMode = $this->generate($f, $plan, $unit, [
            'capability' => 'reference_to_video',
            'intent' => 'reference',
            'inputs' => [['type' => 'reference_image', 'asset_id' => $reference->uuid]],
        ])->assertCreated()->json('data.generation.id');

        $this->refreshJob($f, $plan, $unit, $image)->assertJsonPath('data.generation.output_available', true);
        $this->refreshJob($f, $plan, $unit, $referenceMode)->assertJsonPath('data.generation.output_available', true);
        $this->assertSame(
            ['image_to_video', 'reference_to_video'],
            StoryVideoGenerationJob::query()->orderBy('id')->pluck('capability')->all(),
        );
        $this->assertSame([10, 10], $this->adapter->submittedDurations);
    }

    public function test_bad_provider_results_fail_without_accepting_the_file(): void
    {
        [$f, $plan] = $this->planned([10, 10, 10, 10, 10]);
        $units = $this->units($plan);
        $cases = ['missing', 'wrong_duration', 'bad_mime', 'empty', 'download_failed'];
        foreach ($cases as $index => $output) {
            $this->adapter->completion = 'sync';
            $this->adapter->output = $output;
            $id = $this->generate($f, $plan, $units[$index], ['intent' => $output])->json('data.generation.id');
            $response = $this->refreshJob($f, $plan, $units[$index], $id)->assertOk()->json('data.generation');
            $this->assertSame('failed', $response['status'], $output);
            $this->assertFalse($response['output_available'], $output);
        }
        $this->assertSame(5, $this->adapter->submits);
    }

    public function test_a_transient_failure_retries_the_same_job_and_a_permanent_one_does_not(): void
    {
        [$f, $plan] = $this->planned([10, 10]);
        $units = $this->units($plan);

        $this->adapter->transientFailures = 1;
        $id = $this->generate($f, $plan, $units[0])->json('data.generation.id');
        $this->refreshJob($f, $plan, $units[0], $id)->assertJsonPath('data.generation.status', 'submitted');
        $this->refreshJob($f, $plan, $units[0], $id)->assertJsonPath('data.generation.status', 'processing');
        $this->refreshJob($f, $plan, $units[0], $id)->assertJsonPath('data.generation.output_available', true);
        $this->assertSame(1, $this->adapter->submits);
        $this->assertSame(1, ActivityLog::query()->where('action', StoryProductionUnitGenerationService::EVENT_RETRIED)->count());

        $this->adapter->permanentFailure = true;
        $permanent = $this->generate($f, $plan, $units[1], ['intent' => 'permanent'])->json('data.generation.id');
        $this->refreshJob($f, $plan, $units[1], $permanent)->assertJsonPath('data.generation.status', 'failed');
        $this->refreshJob($f, $plan, $units[1], $permanent);
        $this->assertSame(2, $this->adapter->submits);
    }

    public function test_a_failed_submit_is_one_failed_job_and_hides_the_provider_detail(): void
    {
        [$f, $plan] = $this->planned([10]);
        $this->adapter->failSubmit = true;

        $body = $this->generate($f, $plan, $this->units($plan)[0])->assertStatus(422)->getContent();

        $this->assertStringNotContainsString('secret', $body);
        $job = StoryVideoGenerationJob::query()->sole();
        $this->assertSame('failed', $job->status);
        $this->assertSame(1, $this->adapter->submits);
        $this->assertStringNotContainsString('secret', (string) $job->error_message);
    }

    public function test_a_claimed_submit_without_an_operation_is_not_sent_again(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $id = $this->generate($f, $plan, $unit)->json('data.generation.id');
        StoryVideoGenerationJob::query()->where('uuid', $id)->update([
            'operation_id' => null,
            'started_at' => now()->subHour(),
            'status' => 'submitted',
        ]);

        $this->refreshJob($f, $plan, $unit, $id)->assertJsonPath('data.generation.status', 'failed');

        $this->assertSame(1, $this->adapter->submits);
    }

    public function test_the_snapshot_does_not_follow_later_edits_and_unit_two_can_see_unit_one(): void
    {
        [$f, $plan] = $this->planned([17]);
        $units = $this->units($plan);
        $this->adapter->completion = 'sync';
        $firstId = $this->generate($f, $plan, $units[0], ['instruction' => 'Keep the whale small'])->json('data.generation.id');
        $this->refreshJob($f, $plan, $units[0], $firstId);
        $first = StoryVideoGenerationJob::query()->where('uuid', $firstId)->firstOrFail();
        $original = $first->request_payload['metadata']['context']['scene']['visual_prompt'];

        $f['scenes'][0]->forceFill(['visual_prompt' => 'A completely different picture'])->save();
        $this->refreshJob($f, $plan, $units[0], $firstId);
        $this->assertSame($original, StoryVideoGenerationJob::query()->where('uuid', $firstId)->firstOrFail()->request_payload['metadata']['context']['scene']['visual_prompt']);

        $versionId = $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->base($f, $plan, $units[0]).'/versions')
            ->assertOk()
            ->json('data.versions.0.id');
        $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $units[0]).'/versions/'.$versionId.'/approve')->assertOk();
        $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $units[0]).'/versions/'.$versionId.'/select')->assertOk();

        $secondId = $this->generate($f, $plan, $units[1], ['intent' => 'next'])->json('data.generation.id');
        $second = StoryVideoGenerationJob::query()->where('uuid', $secondId)->firstOrFail();
        $previous = $second->request_payload['metadata']['context']['continuity']['previous_unit'];
        $this->assertTrue($previous['available']);
        $this->assertSame($units[0]->uuid, $previous['id']);
        $this->assertSame($versionId, $previous['version_id']);
        $this->assertSame('videos', $previous['output']['disk']);
    }

    public function test_unit_two_still_generates_when_unit_one_has_no_output(): void
    {
        [$f, $plan] = $this->planned([17]);
        $units = $this->units($plan);

        $id = $this->generate($f, $plan, $units[1], ['intent' => 'ahead'])->assertCreated()->json('data.generation.id');
        $context = StoryVideoGenerationJob::query()->where('uuid', $id)->firstOrFail()->request_payload['metadata']['context'];

        $this->assertFalse($context['continuity']['previous_unit']['available']);
        $this->assertSame(1, $this->adapter->submits);
    }

    public function test_authorization_and_foreign_identifiers(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $url = $this->base($f, $plan, $unit).'/generate';

        $this->postJson($url, ['capability' => 'text_to_video'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson($url, ['capability' => 'text_to_video'])->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')->postJson($url, ['capability' => 'text_to_video'])->assertCreated();

        [$otherF, $otherPlan] = $this->planned([10]);
        $foreignUnit = $this->units($otherPlan)[0];
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $foreignUnit).'/generate', ['capability' => 'text_to_video'])
            ->assertNotFound();
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $otherPlan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->assertNotFound();
        $this->actingAs($f['user'], 'sanctum')
            ->postJson('/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.Str::uuid().'/units/'.$unit->uuid.'/generate', ['capability' => 'text_to_video'])
            ->assertNotFound();

        $this->assertSame(1, $this->adapter->submits);
    }

    public function test_responses_and_history_hide_provider_internals(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $this->adapter->completion = 'sync';
        $id = $this->generate($f, $plan, $unit)->assertCreated()->json('data.generation.id');
        $this->refreshJob($f, $plan, $unit, $id);

        $history = $this->actingAs($f['user'], 'sanctum')->getJson('/api/v1/story/projects/'.$f['project']->uuid.'/history')->assertOk()->getContent();
        $generation = $this->refreshJob($f, $plan, $unit, $id)->getContent();
        $this->assertStringNotContainsString('video.contract', $generation);
        $this->assertStringNotContainsString('contract-op', $generation);
        $this->assertStringNotContainsString('contract-model', $generation);
        $this->assertStringNotContainsString('idempotency_key', $generation);
        $this->assertStringNotContainsString('story_production_unit_id', $generation);
        $this->assertStringNotContainsString('secret', $generation);
        $this->assertStringContainsString('Unit 1', $history);
        $this->assertStringContainsString('unit_generation', $history);
        $this->assertSame(1, ActivityLog::query()->where('action', StoryProductionUnitGenerationService::EVENT_REQUESTED)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryProductionUnitGenerationService::EVENT_COMPLETED)->count());
    }

    public function test_cancellation_stops_the_attempt_without_a_second_submit(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $id = $this->generate($f, $plan, $unit)->json('data.generation.id');

        $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $unit)."/generations/{$id}/cancel")->assertOk()->assertJsonPath('data.generation.status', 'cancelled');
        $this->refreshJob($f, $plan, $unit, $id)->assertJsonPath('data.generation.status', 'cancelled');

        $this->assertSame(1, $this->adapter->submits);
    }

    public function test_nothing_is_submitted_when_video_is_not_connected(): void
    {
        [$f, $plan] = $this->planned([10]);
        $this->app->instance(StoryCapabilityRouterInterface::class, new StoryCapabilityRouter);

        $this->generate($f, $plan, $this->units($plan)[0])->assertStatus(501)->assertJsonPath('error_code', 'GENERATION_NOT_ENABLED');
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_direct_service_generation_matches_the_http_contract(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $this->adapter->completion = 'sync';

        $result = app(StoryProductionUnitGenerationServiceInterface::class)->generate($f['project'], $plan->uuid, $unit->uuid, $f['user'], [
            'capability' => 'text_to_video',
            'instruction' => 'Hold on the horizon',
        ]);
        $job = app(StoryProductionUnitGenerationServiceInterface::class)->refresh($f['project'], $plan->uuid, $unit->uuid, $result['job']->uuid);

        $this->assertTrue($result['created']);
        $this->assertTrue($job->provider_metadata['unit_output_checked']);
        $this->assertSame(10, $job->request_payload['metadata']['context']['unit']['duration_seconds']);
        $this->assertStringContainsString('Hold on the horizon', (string) $job->request_payload['prompt']);
    }

    /**
     * @param  list<int>  $durations
     * @return array{0: array<string, mixed>, 1: StoryProductionPlan}
     */
    private function planned(array $durations): array
    {
        $f = $this->productionFixture($durations);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $f['user'])['plan'];
        $plan->load('scenes.units');

        return [$f, $plan];
    }

    /**
     * @return list<StoryProductionUnit>
     */
    private function units(StoryProductionPlan $plan): array
    {
        return $plan->scenes
            ->sortBy('sequence')
            ->flatMap(static fn ($scene) => $scene->units->sortBy('sequence'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $extra
     */
    private function generate(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit, array $extra = []): TestResponse
    {
        return $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $unit).'/generate', array_merge(['capability' => 'text_to_video'], $extra));
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function refreshJob(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit, string $jobId): TestResponse
    {
        return $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $unit).'/generations/'.$jobId);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function base(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }

    private function referenceFor(int $workspaceId): StoryCharacterReference
    {
        $character = StoryCharacter::factory()->create(['story_workspace_id' => $workspaceId, 'name' => 'Mira']);
        $reference = StoryCharacterReference::factory()->create(['story_character_id' => $character->id]);
        Storage::disk('images')->put($reference->path, 'image-bytes');

        return $reference;
    }
}
