<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresXai;
use Tests\TestCase;

final class XaiImageGenerationTest extends TestCase
{
    use ConfiguresXai;
    use RefreshDatabase;

    public function test_a_character_picture_maps_fields_and_downloads_the_temporary_url(): void
    {
        Storage::fake('images');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);
        $this->enableXai(['ai.story_image_provider' => 'xai']);
        Http::fake([
            'https://api.x.ai/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://cdn.x.ai.test/character.png']],
            ]),
            'https://cdn.x.ai.test/character.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = StoryCharacter::factory()->create([
            'story_workspace_id' => app(\App\Story\Contracts\StoryWorkspaceServiceInterface::class)->workspaceForProject($project)->id,
            'name' => 'Aarav',
            'appearance' => 'round face',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/generate", [
                'size' => '1792x1024',
                'quality' => 'hd',
            ])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'xai');

        $reference = StoryCharacterReference::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        Storage::disk('images')->assertExists($reference->path);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/images/generations')) {
                return false;
            }
            $body = $request->data();

            return ($body['model'] ?? null) === 'grok-imagine-image'
                && ($body['aspect_ratio'] ?? null) === '16:9'
                && ($body['response_format'] ?? null) === 'url'
                && ! array_key_exists('size', $body)
                && ! array_key_exists('style', $body)
                && ! array_key_exists('quality', $body);
        });
        Http::assertSent(fn ($request): bool => $request->url() === 'https://cdn.x.ai.test/character.png');
    }
}
