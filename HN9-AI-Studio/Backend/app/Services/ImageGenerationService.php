<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\Execution\DispatchOptions;
use App\AI\Support\Modality;
use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ExecutionOrchestratorInterface;
use App\Contracts\Services\ImageGenerationServiceInterface;
use App\Contracts\Services\ImageServiceInterface;
use App\Contracts\Services\ScriptServiceInterface;
use App\DTOs\Generation\GenerationRequestData;
use App\DTOs\Image\CreateImageData;
use App\Enums\ImageAspectRatio;
use App\Enums\ImageSource;
use App\Enums\ImageStatus;
use App\Exceptions\ImageGenerationException;
use App\Exceptions\ImageWorkflowException;
use App\Models\GeneratedAsset;
use App\Models\GeneratedContent;
use App\Models\Image;
use App\Models\MediaFile;
use App\Models\Project;
use App\Models\PromptExecution;
use App\Models\Script;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Studio image generation. Dispatches through ExecutionOrchestrator and stores
 * the provider image before a new studio row is created. Existing images are
 * never overwritten.
 */
final readonly class ImageGenerationService implements ImageGenerationServiceInterface
{
    /**
     * Adapters that implement generateImage. Capability still depends on configured image models.
     *
     * @var list<string>
     */
    private const IMAGE_PROVIDERS = ['openai', 'gemini'];

    public function __construct(
        private ExecutionOrchestratorInterface $orchestrator,
        private ImageServiceInterface $images,
        private ScriptServiceInterface $scripts,
        private ActivityLoggerInterface $activity,
    ) {}

    public function generate(Project $project, array $input, ?User $causer = null, ?Image $parent = null): array
    {
        if ($parent !== null && ! $parent->allowsRegeneration()) {
            throw ImageWorkflowException::invalidTransition(
                $parent->uuid,
                'regenerated',
                $parent->statusEnum()->value,
            );
        }

        $prompt = $this->resolvePrompt($project, $input);
        $aspectRatio = $this->aspectRatio($input, $parent);
        $provider = $this->nullableString($input['provider'] ?? null);
        $model = $this->nullableString($input['model'] ?? null);
        $size = $this->nullableString($input['size'] ?? null);
        $quality = $this->nullableString($input['quality'] ?? null);
        $pinned = $this->assertSupported($provider, $model);
        $script = $this->scriptFor($project, $input);

        $dto = new GenerationRequestData(
            project_id: $project->getKey(),
            deliverable_type: 'image',
            user_id: $causer?->getKey(),
            platform: $this->nullableString($input['platform'] ?? null) ?? 'instagram',
            language: 'en',
            topic: $this->nullableString($input['title'] ?? null) ?? $this->titleFrom($prompt),
            payload: [
                'prompt' => $prompt,
                'aspect_ratio' => $aspectRatio,
                'size' => $size ?? '',
                'model' => $model ?? '',
                'provider' => $pinned ?? '',
            ],
            source: 'image_studio',
            type: 'brief',
        );

        $result = $this->orchestrator->execute($project, $dto, [
            'user' => $causer,
            'modality' => Modality::Image->value,
            'image_prompt' => $prompt,
            'model' => $model,
            'size' => $size,
            'quality' => $quality,
            'provider' => $pinned,
        ]);

        $stored = $result['stored_image'] ?? null;
        $content = $result['content'] ?? null;
        $asset = $result['asset'] ?? null;

        if (! $stored instanceof StoredImage || ! $content instanceof GeneratedContent || ! $asset instanceof GeneratedAsset) {
            throw ImageGenerationException::malformed();
        }

        $dispatch = is_array($result['dispatch'] ?? null) ? $result['dispatch'] : [];
        $promptExecution = $result['prompt_execution'] ?? null;
        $usage = is_array($dispatch['usage'] ?? null) ? $dispatch['usage'] : null;

        $image = DB::transaction(function () use ($project, $prompt, $aspectRatio, $input, $script, $parent, $content, $asset, $stored, $dispatch, $promptExecution, $usage, $model, $size, $causer): Image {
            $created = $this->images->create(new CreateImageData(
                project_id: $project->getKey(),
                title: $this->nullableString($input['title'] ?? null) ?? $this->titleFrom($prompt),
                prompt: $prompt,
                negative_prompt: $this->nullableString($input['negative_prompt'] ?? null),
                aspect_ratio: $aspectRatio,
                status: ImageStatus::Draft->value,
                source: ImageSource::Ai->value,
                script_id: $script?->getKey(),
                parent_image_id: $parent?->getKey(),
                generated_content_id: $content->getKey(),
                generated_asset_id: $asset->getKey(),
                provider: isset($dispatch['provider']) ? (string) $dispatch['provider'] : null,
                provider_job_id: null,
                metadata: [
                    'mime_type' => $stored->mimeType,
                    'extension' => $stored->extension,
                    'size' => $stored->size,
                    'width' => $stored->width,
                    'height' => $stored->height,
                ],
                generation: [
                    'provider' => $dispatch['provider'] ?? null,
                    'model' => $model ?? ($dispatch['model'] ?? null),
                    'prompt' => $prompt,
                    'size' => $size,
                    'aspect_ratio' => $aspectRatio,
                    'synchronous' => true,
                    'generated_content_id' => $content->uuid,
                    'generated_asset_id' => $asset->uuid,
                    'prompt_execution_id' => $promptExecution instanceof PromptExecution ? $promptExecution->uuid : null,
                    'parent_image_id' => $parent?->uuid,
                    'script_id' => $script?->uuid,
                    'usage' => $usage,
                    'cost' => $dispatch['cost'] ?? null,
                ],
            ), $causer);

            $file = new MediaFile([
                'disk' => $stored->disk,
                'path' => $stored->path,
                'original_name' => 'image.'.$stored->extension,
                'mime_type' => $stored->mimeType,
                'extension' => $stored->extension,
                'size' => $stored->size,
                'checksum' => $stored->checksum,
                'collection' => 'images',
                'meta' => array_filter([
                    'width' => $stored->width,
                    'height' => $stored->height,
                ], static fn (mixed $value): bool => $value !== null),
            ]);
            $file->mediable()->associate($created);
            $file->save();

            return $created->load(['file']);
        });

        $this->activity->log(
            $parent === null ? 'image.generated' : 'image.regenerated',
            $image,
            $causer,
            $parent === null ? 'AI image generated' : 'AI image variation generated',
            [
                'parent_image_id' => $parent?->uuid,
                'generated_asset_id' => $asset->uuid,
                'provider' => $dispatch['provider'] ?? null,
            ],
        );

        return [
            'image' => $image->load([
                'project',
                'script',
                'parentImage',
                'generatedContent',
                'generatedAsset',
                'file',
                'latestReviewEvent.user',
                'latestReworkEvent.user',
            ]),
            'dispatch' => $dispatch,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolvePrompt(Project $project, array $input): string
    {
        $prompt = $this->nullableString($input['prompt'] ?? null);

        if ($prompt !== null) {
            return $prompt;
        }

        $script = $this->scriptFor($project, $input);
        $body = $this->nullableString($script?->body);

        if ($body === null) {
            throw ImageGenerationException::promptRequired();
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function scriptFor(Project $project, array $input): ?Script
    {
        $uuid = $this->nullableString($input['script_id'] ?? null);

        if ($uuid === null) {
            return null;
        }

        $script = $this->scripts->getByUuid($uuid);

        if ($script->project_id !== $project->getKey()) {
            abort(404);
        }

        return $script;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function aspectRatio(array $input, ?Image $parent): string
    {
        $value = $this->nullableString($input['aspect_ratio'] ?? null) ?? $parent?->aspect_ratio ?? ImageAspectRatio::Square->value;

        return ImageAspectRatio::tryFrom($value)?->value ?? ImageAspectRatio::Square->value;
    }

    private function assertSupported(?string $provider, ?string $model): ?string
    {
        if ($provider !== null && ! in_array($provider, self::IMAGE_PROVIDERS, true)) {
            throw ImageGenerationException::unsupported($provider, $model);
        }

        if ($provider !== null) {
            $models = $this->configuredImageModels($provider);

            if ($models === [] || ($model !== null && ! in_array($model, $models, true))) {
                throw ImageGenerationException::unsupported($provider, $model);
            }

            return $provider;
        }

        if ($model === null) {
            return null;
        }

        $matches = [];

        foreach (self::IMAGE_PROVIDERS as $key) {
            if (in_array($model, $this->configuredImageModels($key), true)) {
                $matches[] = $key;
            }
        }

        if (count($matches) !== 1) {
            throw ImageGenerationException::unsupported(null, $model);
        }

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private function configuredImageModels(string $provider): array
    {
        $settings = config("ai.providers.{$provider}");

        if (! is_array($settings) || ! ($settings['enabled'] ?? false)) {
            return [];
        }

        $models = $settings['image_models'] ?? [];

        return is_array($models) ? array_values(array_filter($models, 'is_string')) : [];
    }

    private function titleFrom(string $prompt): string
    {
        $line = trim(strtok($prompt, "\n") ?: $prompt);

        if ($line === '') {
            return 'AI generated image';
        }

        return mb_strlen($line) > 120 ? mb_substr($line, 0, 117).'...' : $line;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
