<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryCharacterRepositoryInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Contracts\Logging\ActivityLoggerInterface;
use App\Story\Enums\StoryCharacterReferenceStatus;
use App\Story\Enums\StoryCharacterStatus;
use App\Story\Exceptions\StoryCharacterException;
use App\Story\Exceptions\StoryCharacterLockException;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterLock;
use App\Story\Models\StoryCharacterLockEvent;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryScene;
use App\Story\Support\StoryCharacterIdentityCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class StoryCharacterLockService
{
    public function __construct(
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryCharacterRepositoryInterface $characters,
        private ActivityLoggerInterface $activity,
    ) {}

    /**
     * Scene names are matched to character names, case-insensitive and trimmed.
     * Character ids are not stored on the scene.
     *
     * @return list<StoryCharacterLock>
     */
    public function activeLocksForScene(StoryScene $scene): array
    {
        $names = [];
        foreach ((array) $scene->characters as $index => $name) {
            $key = mb_strtolower(trim((string) $name));
            if ($key !== '' && ! array_key_exists($key, $names)) {
                $names[$key] = $index;
            }
        }
        if ($names === []) {
            return [];
        }

        $scene->loadMissing('reel');
        $workspaceId = $scene->reel?->story_workspace_id;
        if ($workspaceId === null) {
            return [];
        }

        $locks = StoryCharacterLock::query()
            ->where('story_workspace_id', $workspaceId)
            ->with(['character', 'reference'])
            ->get()
            ->filter(function (StoryCharacterLock $lock) use ($names): bool {
                $name = mb_strtolower(trim((string) $lock->character?->name));

                return $name !== '' && array_key_exists($name, $names);
            })
            ->sortBy(fn (StoryCharacterLock $lock): int => $names[mb_strtolower(trim((string) $lock->character?->name))] ?? PHP_INT_MAX)
            ->values();

        return $locks->all();
    }

    /**
     * @return array{changed: bool, lock: StoryCharacterLock|null}
     */
    public function lock(Project $project, string $characterUuid, User $actor, ?string $referenceUuid = null): array
    {
        $character = $this->character($project, $characterUuid);
        if ($character->statusEnum() !== StoryCharacterStatus::Draft) {
            throw StoryCharacterException::archived();
        }

        $reference = $this->approvedReference($character, $referenceUuid);
        $identity = StoryCharacterIdentityCard::build($character);
        $hash = hash('sha256', $identity);
        $existing = StoryCharacterLock::query()->where('story_character_id', $character->id)->first();
        if ($existing instanceof StoryCharacterLock
            && (int) $existing->story_character_reference_id === (int) $reference->id
            && hash_equals((string) $existing->identity_hash, $hash)
        ) {
            return ['changed' => false, 'lock' => $existing];
        }

        $lock = DB::transaction(function () use ($character, $reference, $identity, $hash, $actor, $existing): StoryCharacterLock {
            $payload = [
                'story_workspace_id' => $character->story_workspace_id,
                'story_character_reference_id' => $reference->id,
                'identity_text' => $identity,
                'identity_hash' => $hash,
                'locked_fields' => $this->snapshot($character),
                'locked_by' => $actor->id,
                'locked_at' => now(),
            ];
            if ($existing instanceof StoryCharacterLock) {
                $existing->fill($payload)->save();
                $lock = $existing->refresh();
                $this->event($character, 'relock', $hash, $reference->id, $actor);
            } else {
                $lock = StoryCharacterLock::query()->create($payload + [
                    'story_character_id' => $character->id,
                ]);
                $this->event($character, 'lock', $hash, $reference->id, $actor);
            }

            return $lock;
        });

        $this->activity->log(
            $existing instanceof StoryCharacterLock ? 'story.character.locked' : 'story.character.locked',
            $character,
            $actor,
            'Character look locked',
            [
                'character_uuid' => $character->uuid,
                'reference_uuid' => $reference->uuid,
                'identity_hash' => $hash,
            ],
        );

        return ['changed' => true, 'lock' => $lock];
    }

    /**
     * @return array{changed: bool, lock: null}
     */
    public function unlock(Project $project, string $characterUuid, User $actor): array
    {
        $character = $this->character($project, $characterUuid);
        $existing = StoryCharacterLock::query()->where('story_character_id', $character->id)->first();
        if (! $existing instanceof StoryCharacterLock) {
            return ['changed' => false, 'lock' => null];
        }

        DB::transaction(function () use ($character, $existing, $actor): void {
            $this->event($character, 'unlock', (string) $existing->identity_hash, (int) $existing->story_character_reference_id, $actor);
            $existing->delete();
        });

        $reference = StoryCharacterReference::query()->find($existing->story_character_reference_id);
        $this->activity->log('story.character.unlocked', $character, $actor, 'Character look unlocked', [
            'character_uuid' => $character->uuid,
            'reference_uuid' => $reference?->uuid,
            'identity_hash' => $existing->identity_hash,
        ]);

        return ['changed' => true, 'lock' => null];
    }

    /**
     * @return Collection<int, StoryCharacterLock>
     */
    public function listForProject(Project $project): Collection
    {
        $workspace = $this->workspaces->workspaceForProject($project);

        return StoryCharacterLock::query()
            ->where('story_workspace_id', $workspace->id)
            ->with(['character', 'reference', 'lockedBy'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assertLookEditable(StoryCharacter $character, array $attributes): void
    {
        $lock = StoryCharacterLock::query()->where('story_character_id', $character->id)->first();
        if (! $lock instanceof StoryCharacterLock) {
            return;
        }

        foreach (['appearance', 'face_description', 'hair', 'clothing', 'special_details', 'name'] as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }
            $next = trim((string) ($attributes[$field] ?? ''));
            $current = trim((string) ($character->{$field} ?? ''));
            if ($next !== $current) {
                throw StoryCharacterLockException::locked();
            }
        }
    }

    public function assertCanArchive(StoryCharacter $character): void
    {
        if (StoryCharacterLock::query()->where('story_character_id', $character->id)->exists()) {
            throw StoryCharacterLockException::locked();
        }
    }

    public function assertReferenceMutable(StoryCharacterReference $reference): void
    {
        $locked = StoryCharacterLock::query()
            ->where('story_character_id', $reference->story_character_id)
            ->where('story_character_reference_id', $reference->id)
            ->exists();
        if ($locked) {
            throw StoryCharacterLockException::referenceLocked();
        }
    }

    /**
     * @return array<string, string|null>
     */
    public function present(?StoryCharacterLock $lock): ?array
    {
        if (! $lock instanceof StoryCharacterLock) {
            return null;
        }
        $lock->loadMissing('reference');

        return [
            'reference_uuid' => $lock->reference?->uuid,
            'identity_text' => $lock->identity_text,
            'locked_at' => $lock->locked_at?->toIso8601String(),
        ];
    }

    private function character(Project $project, string $characterUuid): StoryCharacter
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $character = $this->characters->findByUuidForWorkspace($workspace, $characterUuid);
        if (! $character instanceof StoryCharacter) {
            throw StoryException::notFound('Character');
        }

        return $character;
    }

    private function approvedReference(StoryCharacter $character, ?string $referenceUuid): StoryCharacterReference
    {
        if ($referenceUuid !== null && $referenceUuid !== '') {
            $reference = StoryCharacterReference::query()
                ->where('uuid', $referenceUuid)
                ->where('story_character_id', $character->id)
                ->first();
            if (! $reference instanceof StoryCharacterReference) {
                throw StoryException::notFound('Character reference');
            }
            if ($reference->statusEnum() !== StoryCharacterReferenceStatus::Approved) {
                throw StoryCharacterLockException::needsReference();
            }

            return $reference;
        }

        $approved = $character->approved_reference_id === null
            ? null
            : StoryCharacterReference::query()->find($character->approved_reference_id);
        if (! $approved instanceof StoryCharacterReference
            || (int) $approved->story_character_id !== (int) $character->id
            || $approved->statusEnum() !== StoryCharacterReferenceStatus::Approved
        ) {
            throw StoryCharacterLockException::needsReference();
        }

        return $approved;
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(StoryCharacter $character): array
    {
        return [
            'name' => $character->name,
            'appearance' => $character->appearance,
            'face_description' => $character->face_description,
            'hair' => $character->hair,
            'clothing' => $character->clothing,
            'special_details' => $character->special_details,
        ];
    }

    private function event(StoryCharacter $character, string $action, string $hash, int $referenceId, User $actor): void
    {
        StoryCharacterLockEvent::query()->create([
            'story_character_id' => $character->id,
            'action' => $action,
            'identity_hash' => $hash,
            'reference_id' => $referenceId,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
