<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\StoryRenderFinished;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryTimeline;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_a_user_reads_only_their_own_notifications(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $render = $this->render($owner);
        $owner->notify(new StoryRenderFinished($render));
        $owner->notify(new StoryRenderFinished($render));
        $other->notify(new StoryRenderFinished($render));

        $listed = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
        $this->assertCount(2, $listed->json('data.notifications'));
        $id = $listed->json('data.notifications.0.id');

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/notifications/'.$id.'/read')
            ->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/notifications/'.$id.'/read')
            ->assertOk();
        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
        $this->assertSame(0, $owner->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $other->fresh()->unreadNotifications()->count());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/dashboard/notifications')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
        Http::assertNothingSent();
    }

    private function render(User $owner): StoryFinalRender
    {
        $project = \App\Models\Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        $timeline = StoryTimeline::query()->create(['story_reel_id' => $reel->id]);

        return StoryFinalRender::query()->create([
            'story_timeline_id' => $timeline->id,
            'story_reel_id' => $reel->id,
            'requested_by' => $owner->id,
            'status' => 'completed',
            'timeline_version' => hash('sha256', 'notice'),
            'timeline_snapshot' => ['clips' => []],
        ]);
    }
}
