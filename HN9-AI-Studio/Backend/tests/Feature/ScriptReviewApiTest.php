<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\ScriptReviewAction;
use App\Enums\ScriptStatus;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use App\Policies\ScriptPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScriptReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_submit_a_draft_for_review(): void
    {
        [$owner, $project, $script] = $this->ownedDraft();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'submit-review'))
            ->assertOk()
            ->assertJsonPath('data.id', $script->uuid)
            ->assertJsonPath('data.status', ScriptStatus::PendingReview->value)
            ->assertJsonPath('data.latest_review.action', ScriptReviewAction::SubmittedForReview->value)
            ->assertJsonPath('data.latest_review.actor.id', $owner->uuid);

        $this->assertDatabaseHas('scripts', [
            'id' => $script->id,
            'status' => ScriptStatus::PendingReview->value,
        ]);

        $this->assertDatabaseHas('script_review_events', [
            'script_id' => $script->id,
            'user_id' => $owner->id,
            'action' => ScriptReviewAction::SubmittedForReview->value,
            'from_status' => ScriptStatus::Draft->value,
            'to_status' => ScriptStatus::PendingReview->value,
        ]);
    }

    public function test_reviewer_can_approve_a_pending_script(): void
    {
        [$owner, $project, $script] = $this->ownedDraft(reviewer: true);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'submit-review'))
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'approve'), [
                'comment' => 'Ship it.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ScriptStatus::Approved->value)
            ->assertJsonPath('data.latest_review.action', ScriptReviewAction::Approved->value)
            ->assertJsonPath('data.latest_review.comment', 'Ship it.')
            ->assertJsonPath('data.capabilities.edit', false);

        $this->assertDatabaseHas('scripts', [
            'id' => $script->id,
            'status' => ScriptStatus::Approved->value,
        ]);
    }

    public function test_reviewer_can_mark_needs_rework_with_comment(): void
    {
        [$owner, $project, $script] = $this->ownedPending(reviewer: true);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'needs-rework'), [
                'comment' => 'Tighten the hook and add a clearer CTA.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ScriptStatus::NeedsRework->value)
            ->assertJsonPath('data.latest_rework.comment', 'Tighten the hook and add a clearer CTA.')
            ->assertJsonPath('data.capabilities.edit', true)
            ->assertJsonPath('data.capabilities.submit', true);

        $this->assertDatabaseHas('script_review_events', [
            'script_id' => $script->id,
            'action' => ScriptReviewAction::NeedsRework->value,
            'comment' => 'Tighten the hook and add a clearer CTA.',
        ]);
    }

    public function test_needs_rework_requires_a_meaningful_comment(): void
    {
        [$owner, $project, $script] = $this->ownedPending(reviewer: true);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'needs-rework'), [
                'comment' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'needs-rework'), [
                'comment' => '   short   ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);

        $this->assertSame(ScriptStatus::PendingReview->value, $script->fresh()->status);
        $this->assertDatabaseCount('script_review_events', 0);
    }

    public function test_creator_can_rework_and_resubmit(): void
    {
        [$owner, $project, $script] = $this->ownedPending(reviewer: true);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'needs-rework'), [
                'comment' => 'Add a stronger close and keep the first line.',
            ])
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid, [
                'body' => 'Reworked voiceover copy.',
            ])
            ->assertOk()
            ->assertJsonPath('data.body', 'Reworked voiceover copy.')
            ->assertJsonPath('data.status', ScriptStatus::NeedsRework->value);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'submit-review'))
            ->assertOk()
            ->assertJsonPath('data.status', ScriptStatus::PendingReview->value)
            ->assertJsonPath('data.latest_review.action', ScriptReviewAction::Resubmitted->value);

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->url($project, $script, 'review-history'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.action', ScriptReviewAction::NeedsRework->value)
            ->assertJsonPath('data.1.action', ScriptReviewAction::Reworked->value)
            ->assertJsonPath('data.2.action', ScriptReviewAction::Resubmitted->value);
    }

    public function test_approval_history_persists_the_full_trail(): void
    {
        [$owner, $project, $script] = $this->ownedDraft(reviewer: true);

        $this->actingAs($owner, 'sanctum')->postJson($this->url($project, $script, 'submit-review'))->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson($this->url($project, $script, 'needs-rework'), [
            'comment' => 'Please rewrite the middle section for clarity.',
        ])->assertOk();
        $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid, [
            'body' => 'Clarified middle section.',
        ])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson($this->url($project, $script, 'submit-review'))->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson($this->url($project, $script, 'approve'))->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->url($project, $script, 'review-history'))
            ->assertOk()
            ->assertJsonPath('data.0.action', ScriptReviewAction::SubmittedForReview->value)
            ->assertJsonPath('data.1.action', ScriptReviewAction::NeedsRework->value)
            ->assertJsonPath('data.2.action', ScriptReviewAction::Reworked->value)
            ->assertJsonPath('data.3.action', ScriptReviewAction::Resubmitted->value)
            ->assertJsonPath('data.4.action', ScriptReviewAction::Approved->value);

        $this->assertDatabaseCount('script_review_events', 5);
        $this->assertSame(ScriptStatus::Approved->value, $script->fresh()->status);
    }

    public function test_invalid_state_transitions_are_rejected(): void
    {
        [$owner, $project, $draft] = $this->ownedDraft(reviewer: true);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $draft, 'approve'))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'script_workflow_invalid_transition');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $draft, 'needs-rework'), [
                'comment' => 'This should not apply to a draft script.',
            ])
            ->assertStatus(409);

        $pending = Script::factory()->for($project)->pendingReview()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $pending, 'submit-review'))
            ->assertStatus(409);

        $approved = Script::factory()->for($project)->approved()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $approved, 'submit-review'))
            ->assertStatus(409);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $approved, 'approve'))
            ->assertStatus(409);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $approved, 'needs-rework'), [
                'comment' => 'Cannot rework an already approved script.',
            ])
            ->assertStatus(409);
    }

    public function test_user_cannot_submit_or_review_another_users_script(): void
    {
        [$owner, $project, $script] = $this->ownedDraft(reviewer: true);
        $intruder = User::factory()->reviewer()->create();

        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->url($project, $script, 'submit-review'))
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'submit-review'))
            ->assertOk();

        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->url($project, $script, 'approve'))
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->url($project, $script, 'needs-rework'), [
                'comment' => 'Unauthorized rework request on another project.',
            ])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson($this->url($project, $script, 'review-history'))
            ->assertForbidden();

        $this->assertSame(ScriptStatus::PendingReview->value, $script->fresh()->status);
        $this->assertDatabaseCount('script_review_events', 1);
    }

    public function test_owner_without_review_permission_cannot_approve(): void
    {
        [$owner, $project, $script] = $this->ownedPending(reviewer: false);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'approve'))
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'needs-rework'), [
                'comment' => 'Owner without review permission cannot decide.',
            ])
            ->assertForbidden();

        $this->assertSame(ScriptStatus::PendingReview->value, $script->fresh()->status);
        $this->assertFalse($owner->hasPermission(ScriptPolicy::REVIEW_PERMISSION));
    }

    public function test_admin_can_review_another_users_script(): void
    {
        [$owner, $project, $script] = $this->ownedPending(reviewer: false);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->url($project, $script, 'approve'), [
                'comment' => 'Admin approval.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ScriptStatus::Approved->value)
            ->assertJsonPath('data.latest_review.actor.id', $admin->uuid);
    }

    public function test_archived_project_rejects_review_actions(): void
    {
        $owner = User::factory()->reviewer()->create();
        $project = Project::factory()->for($owner)->create([
            'status' => ProjectStatus::Archived->value,
        ]);
        $script = Script::factory()->for($project)->create([
            'status' => ScriptStatus::Draft->value,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $script, 'submit-review'))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'script_workflow_project_not_editable');

        $pending = Script::factory()->for($project)->pendingReview()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $pending, 'approve'))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'script_workflow_project_not_editable');
    }

    public function test_approved_script_cannot_be_edited_through_update(): void
    {
        [$owner, $project] = $this->ownedProject(reviewer: true);
        $script = Script::factory()->for($project)->approved()->create([
            'title' => 'Locked title',
            'body' => 'Locked body',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid, [
                'title' => 'Hijacked title',
                'body' => 'Hijacked body',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'script_workflow_edit_locked');

        $this->assertDatabaseHas('scripts', [
            'id' => $script->id,
            'title' => 'Locked title',
            'body' => 'Locked body',
            'status' => ScriptStatus::Approved->value,
        ]);
    }

    public function test_pending_review_script_cannot_be_edited_or_status_forced(): void
    {
        [$owner, $project, $script] = $this->ownedPending(reviewer: true);

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid, [
                'body' => 'Silent edit during review',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'script_workflow_edit_locked');

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid, [
                'status' => ScriptStatus::Approved->value,
                'approved_by' => $owner->uuid,
                'reviewer_id' => $owner->uuid,
            ])
            ->assertStatus(422);

        $this->assertSame(ScriptStatus::PendingReview->value, $script->fresh()->status);
        $this->assertNotSame('Silent edit during review', $script->fresh()->body);
    }

    public function test_foreign_project_or_script_uuid_does_not_leak(): void
    {
        $user = User::factory()->reviewer()->create();
        $projectA = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        $projectB = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        $script = Script::factory()->for($projectB)->create([
            'status' => ScriptStatus::Draft->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$projectA->uuid.'/scripts/'.$script->uuid.'/submit-review')
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/scripts/'.$script->uuid.'/review-history')
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$projectA->uuid.'/scripts/'.fake()->uuid().'/approve')
            ->assertNotFound();
    }

    public function test_pending_review_script_cannot_be_regenerated(): void
    {
        [$owner, $project, $script] = $this->ownedPending(reviewer: true);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid.'/regenerate', [
                'topic' => 'Should not generate while pending review',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'script_workflow_invalid_transition');

        $this->assertDatabaseCount('scripts', 1);
    }

    public function test_mass_assignment_cannot_force_workflow_status_on_create(): void
    {
        [$owner, $project] = $this->ownedProject();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts', [
                'title' => 'Forced approval',
                'status' => ScriptStatus::Approved->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseCount('scripts', 0);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function ownedProject(bool $reviewer = false): array
    {
        $owner = $reviewer
            ? User::factory()->reviewer()->create()
            : User::factory()->create();
        $project = Project::factory()->for($owner)->create([
            'status' => ProjectStatus::Active->value,
        ]);

        return [$owner, $project];
    }

    /**
     * @return array{0: User, 1: Project, 2: Script}
     */
    private function ownedDraft(bool $reviewer = false): array
    {
        [$owner, $project] = $this->ownedProject($reviewer);
        $script = Script::factory()->for($project)->create([
            'status' => ScriptStatus::Draft->value,
            'title' => 'Draft VO',
            'body' => 'Original draft copy',
        ]);

        return [$owner, $project, $script];
    }

    /**
     * @return array{0: User, 1: Project, 2: Script}
     */
    private function ownedPending(bool $reviewer = true): array
    {
        [$owner, $project] = $this->ownedProject($reviewer);
        $script = Script::factory()->for($project)->pendingReview()->create([
            'title' => 'Pending VO',
            'body' => 'Waiting for review',
        ]);

        return [$owner, $project, $script];
    }

    private function url(Project $project, Script $script, string $action): string
    {
        return '/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid.'/'.$action;
    }
}
