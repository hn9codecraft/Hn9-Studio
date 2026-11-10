<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Models\StoryCharacterLock;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryUnitPromptEdit;
use App\Story\Services\StoryCharacterLockService;
use App\Story\Video\StoryVideoInput;

/**
 * Builds the video prompt and the lock pictures for one scene.
 * With no lock and no saved edit, the text matches the pre-M17 prompt byte for byte.
 */
final readonly class StoryVideoPromptComposer
{
    public function __construct(
        private StoryCharacterLockService $locks,
        private StoryCapabilityRouterInterface $router,
    ) {}

    public function automaticBase(StoryScene $scene, string $instruction = ''): string
    {
        $instruction = trim($instruction);

        return trim(implode("\n\n", array_filter([
            $scene->visual_prompt,
            $scene->motion_prompt,
            $scene->story,
            $instruction !== '' ? 'Instruction: '.$instruction : null,
        ])));
    }

    public function sceneBase(StoryScene $scene, ?string $userPrompt): string
    {
        $prompt = trim((string) $userPrompt);

        return $prompt !== '' ? $prompt : (string) ($scene->visual_prompt ?: $scene->story ?: $scene->title);
    }

    /**
     * @param  list<StoryVideoInput>  $userInputs
     */
    public function composeUnit(
        StoryScene $scene,
        StoryProductionUnit $unit,
        StoryVideoCapability $capability,
        ?string $providerKey,
        array $userInputs,
        string $instruction,
        ?string $projectUuid,
    ): StoryComposedPrompt {
        $unit->loadMissing('promptEdit');
        $edit = $unit->promptEdit;
        $automatic = $this->automaticBase($scene, $instruction);
        $edited = $edit instanceof StoryUnitPromptEdit;
        $base = $edited ? (string) $edit->prompt : $automatic;
        $stale = $edited && ! hash_equals((string) $edit->base_hash, hash('sha256', $automatic));
        $parts = $edited ? null : [
            'visual_prompt' => $scene->visual_prompt,
            'motion_prompt' => $scene->motion_prompt,
            'story' => $scene->story,
            'instruction' => trim($instruction),
        ];

        return $this->compose($scene, $capability, $providerKey, $userInputs, $base, $parts, $edited, $stale, $automatic, $projectUuid);
    }

    /**
     * @param  list<StoryVideoInput>  $userInputs
     */
    public function composeScene(
        StoryScene $scene,
        StoryVideoCapability $capability,
        ?string $providerKey,
        array $userInputs,
        ?string $userPrompt,
        ?string $projectUuid,
    ): StoryComposedPrompt {
        $base = $this->sceneBase($scene, $userPrompt);

        return $this->compose($scene, $capability, $providerKey, $userInputs, $base, null, false, false, $base, $projectUuid);
    }

    /**
     * @param  list<StoryVideoInput>  $userInputs
     * @param  array<string, mixed>|null  $parts
     */
    private function compose(
        StoryScene $scene,
        StoryVideoCapability $capability,
        ?string $providerKey,
        array $userInputs,
        string $base,
        ?array $parts,
        bool $edited,
        bool $editStale,
        string $automatic,
        ?string $projectUuid,
    ): StoryComposedPrompt {
        $adapter = $this->adapter($providerKey);
        $limit = $adapter !== null && method_exists($adapter, 'maxPromptLength') ? $adapter->maxPromptLength() : null;
        $pictureLimit = $adapter !== null && method_exists($adapter, 'referenceImageLimit')
            ? (int) $adapter->referenceImageLimit($capability)
            : 0;

        $sceneLocks = $this->locks->activeLocksForScene($scene);
        $max = (int) config('story_character_lock.max_locked_per_scene', 4);
        $included = array_slice($sceneLocks, 0, max(0, $max));
        $extra = array_slice($sceneLocks, max(0, $max));

        $cards = [];
        foreach ($included as $lock) {
            $cards[] = (string) $lock->identity_text;
        }
        $warnings = [];
        if ($editStale) {
            $warnings[] = 'edit_based_on_old_scene_text';
        }
        $lockBlock = $this->lockBlock($cards);
        if ($limit !== null && $lockBlock !== '' && mb_strlen($lockBlock) > $limit) {
            $cards = $this->shortenCards($cards, $limit);
            $lockBlock = $this->lockBlock($cards);
            $warnings[] = 'lock_too_long';
        }

        $trimmed = [];
        $final = $this->join($base, $parts, $lockBlock);
        if ($limit !== null && mb_strlen($final) > $limit) {
            [$final, $trimmed] = $this->trimBase($base, $parts, $lockBlock, $limit);
            if ($trimmed !== []) {
                $warnings[] = 'prompt_trimmed';
            }
        }

        $modes = (array) config('story_character_lock.attach_reference_modes', []);
        $canAttach = in_array($capability->value, $modes, true) && $pictureLimit > 0;
        $inputs = $userInputs;
        $referencesSent = [];
        $sent = [];
        if ($included !== [] && ! $canAttach) {
            $warnings[] = 'references_not_supported_by_mode';
        }
        if ($canAttach) {
            [$inputs, $referencesSent, $sent] = $this->attach($capability, $userInputs, $included, $pictureLimit);
        }

        $lockRows = [];
        $provenance = [];
        foreach ($included as $index => $lock) {
            $reference = $lock->reference;
            $as = $sent[$lock->id] ?? 'text';
            $reason = null;
            if ($as === 'text' && ! $canAttach) {
                $reason = 'references_not_supported_by_mode';
            }
            $lockRows[] = $this->lockRow($lock, $projectUuid, $as, $reason);
            $provenance[] = [
                'character_uuid' => $lock->character?->uuid,
                'reference_uuid' => $reference?->uuid,
                'identity_hash' => $lock->identity_hash,
            ];
            unset($index);
        }
        foreach ($extra as $lock) {
            $lockRows[] = $this->lockRow($lock, $projectUuid, 'not_sent', 'too_many');
        }

        return new StoryComposedPrompt(
            base: $base,
            lockBlock: $lockBlock,
            final: $final,
            limit: $limit,
            trimmed: $trimmed,
            inputs: $inputs,
            locks: $lockRows,
            warnings: array_values(array_unique($warnings)),
            edited: $edited,
            editStale: $editStale,
            automaticBase: $automatic,
            provenanceLocks: $provenance,
            referencesSent: $referencesSent,
        );
    }

    /**
     * @param  list<string>  $cards
     */
    private function lockBlock(array $cards): string
    {
        if ($cards === []) {
            return '';
        }
        $lines = ['Keep these characters exactly the same in every shot:'];
        foreach ($cards as $card) {
            $lines[] = '- '.$card;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>|null  $parts
     */
    private function join(string $base, ?array $parts, string $lockBlock): string
    {
        $text = $parts === null ? $base : trim(implode("\n\n", array_filter([
            $parts['visual_prompt'] ?? null,
            $parts['motion_prompt'] ?? null,
            $parts['story'] ?? null,
            (($parts['instruction'] ?? '') !== '' && $parts['instruction'] !== null) ? 'Instruction: '.$parts['instruction'] : null,
        ])));
        if ($lockBlock === '') {
            return $text;
        }

        return $text === '' ? $lockBlock : $text."\n\n".$lockBlock;
    }

    /**
     * @param  array<string, mixed>|null  $parts
     * @return array{0: string, 1: list<string>}
     */
    private function trimBase(string $base, ?array $parts, string $lockBlock, int $limit): array
    {
        $trimmed = [];
        if ($parts === null) {
            $text = $base;
            while (mb_strlen($this->join($text, null, $lockBlock)) > $limit && $text !== '') {
                $text = $this->shorten($text, mb_strlen($this->join($text, null, $lockBlock)) - $limit);
                $trimmed = ['prompt'];
            }

            return [$this->join($text, null, $lockBlock), $trimmed];
        }

        $order = ['story', 'motion_prompt', 'visual_prompt', 'instruction'];
        foreach ($order as $key) {
            while (mb_strlen($this->join('', $parts, $lockBlock)) > $limit && trim((string) ($parts[$key] ?? '')) !== '') {
                $over = mb_strlen($this->join('', $parts, $lockBlock)) - $limit;
                $parts[$key] = $this->shorten((string) $parts[$key], $over);
                if (! in_array($key, $trimmed, true)) {
                    $trimmed[] = $key;
                }
            }
        }

        return [$this->join('', $parts, $lockBlock), $trimmed];
    }

    private function shorten(string $text, int $over): string
    {
        if (mb_strlen($text) <= 1) {
            return '';
        }
        $target = max(0, mb_strlen($text) - max(1, $over));
        if ($target === 0) {
            return '';
        }
        $cut = mb_substr($text, 0, $target);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            return rtrim(mb_substr($cut, 0, $space));
        }

        return rtrim($cut);
    }

    /**
     * @param  list<string>  $cards
     * @return list<string>
     */
    private function shortenCards(array $cards, int $limit): array
    {
        $count = count($cards);
        if ($count === 0) {
            return [];
        }
        $header = "Keep these characters exactly the same in every shot:\n";
        $overhead = mb_strlen($header) + ($count * 2) + max(0, $count - 1);
        $budget = max($count, $limit - $overhead);
        $each = intdiv($budget, $count);
        $short = [];
        foreach ($cards as $card) {
            $short[] = StoryCharacterIdentityCard::limit($card, max(1, $each));
        }
        $block = $this->lockBlock($short);
        $guard = 0;
        while (mb_strlen($block) > $limit && $guard < 20) {
            $guard++;
            $changed = false;
            foreach ($short as $index => $card) {
                if (mb_strlen($card) <= 1) {
                    continue;
                }
                $short[$index] = $this->shorten($card, 8);
                $changed = true;
                $block = $this->lockBlock($short);
                if (mb_strlen($block) <= $limit) {
                    break;
                }
            }
            if (! $changed) {
                break;
            }
        }

        return $short;
    }

    /**
     * @param  list<StoryVideoInput>  $userInputs
     * @param  list<StoryCharacterLock>  $included
     * @return array{0: list<StoryVideoInput>, 1: list<string>, 2: array<int, string>}
     */
    private function attach(StoryVideoCapability $capability, array $userInputs, array $included, int $pictureLimit): array
    {
        $inputs = $userInputs;
        $sentIds = [];
        $sent = [];
        $known = [];
        foreach ($userInputs as $input) {
            if ($input->assetId !== null) {
                $known[$input->assetId] = true;
            }
        }
        $hasImage = false;
        foreach ($userInputs as $input) {
            if ($input->type === StoryVideoInputType::Image) {
                $hasImage = true;
                break;
            }
        }

        if ($capability === StoryVideoCapability::ImageToVideo) {
            if ($hasImage || $included === []) {
                return [$inputs, [], []];
            }
            $lock = $included[0];
            $uuid = $lock->reference?->uuid;
            if (is_string($uuid) && $uuid !== '' && ! isset($known[$uuid])) {
                $inputs[] = new StoryVideoInput(
                    type: StoryVideoInputType::Image,
                    assetId: $uuid,
                    order: count($inputs),
                );
                $sentIds[] = $uuid;
                $sent[$lock->id] = 'image';
            }

            return [$inputs, $sentIds, $sent];
        }

        $added = 0;
        foreach ($included as $lock) {
            if ($added >= $pictureLimit) {
                break;
            }
            $uuid = $lock->reference?->uuid;
            if (! is_string($uuid) || $uuid === '' || isset($known[$uuid])) {
                continue;
            }
            $inputs[] = new StoryVideoInput(
                type: StoryVideoInputType::ReferenceImage,
                assetId: $uuid,
                order: count($inputs),
            );
            $known[$uuid] = true;
            $sentIds[] = $uuid;
            $sent[$lock->id] = 'reference_image';
            $added++;
        }

        return [$inputs, $sentIds, $sent];
    }

    /**
     * @return array<string, mixed>
     */
    private function lockRow(StoryCharacterLock $lock, ?string $projectUuid, string $sentAs, ?string $reason): array
    {
        $character = $lock->character;
        $reference = $lock->reference;
        $url = null;
        if ($projectUuid !== null && $character !== null && $reference !== null) {
            $url = '/api/v1/story/projects/'.$projectUuid.'/characters/'.$character->uuid.'/references/'.$reference->uuid.'/file';
        }

        return [
            'character_uuid' => $character?->uuid,
            'name' => $character?->name,
            'reference_uuid' => $reference?->uuid,
            'reference_thumbnail_url' => $url,
            'sent_as' => $sentAs,
            'reason' => $reason,
        ];
    }

    private function adapter(?string $providerKey): ?object
    {
        if ($providerKey === null || $providerKey === '') {
            return null;
        }
        foreach ($this->router->adapters() as $adapter) {
            if (method_exists($adapter, 'key') && $adapter->key() === $providerKey) {
                return $adapter;
            }
        }

        return null;
    }
}
