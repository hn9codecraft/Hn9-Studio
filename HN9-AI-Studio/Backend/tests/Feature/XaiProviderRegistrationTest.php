<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\ProviderSetting;
use App\Models\User;
use Database\Seeders\AiProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XaiProviderRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalog_row_is_idempotent_and_xai_is_not_a_default_route(): void
    {
        Http::preventStrayRequests();
        $users = User::query()->count();
        $this->seed(AiProviderSeeder::class);
        $this->seed(AiProviderSeeder::class);

        $this->assertSame(1, AiProvider::query()->where('slug', 'xai')->count());
        $this->assertSame('xAI (Grok)', AiProvider::query()->where('slug', 'xai')->value('name'));
        $this->assertSame(0, ProviderSetting::query()->count());
        $this->assertSame($users, User::query()->count());
        $this->assertNotContains('video.xai', config('story_video.routing.preferred_order'));
        $this->assertNotContains('xai', (array) config('ai.routing.fallback.chains.text'));
        $this->assertSame('', (string) config('ai.story_text_provider'));
        $this->assertSame('', (string) config('ai.story_image_provider'));
        $this->assertFalse((bool) config('ai.providers.xai.enabled'));
        Http::assertNothingSent();
    }
}
