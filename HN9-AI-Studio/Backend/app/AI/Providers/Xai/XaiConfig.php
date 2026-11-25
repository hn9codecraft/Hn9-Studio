<?php

declare(strict_types=1);

namespace App\AI\Providers\Xai;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Exceptions\ProviderNotConfiguredException;

final readonly class XaiConfig
{
    public const KEY = 'xai';

    /**
     * @param  list<string>  $models
     * @param  list<string>  $imageModels
     * @param  array<string, array{input?: float|int, output?: float|int}>  $pricing
     */
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public ?string $defaultModel,
        public int $timeout,
        public int $maxRetries,
        public array $models,
        public array $imageModels,
        public ?string $imageDefaultModel,
        public ?string $voiceEn,
        public ?string $voiceHi,
        public bool $gujaratiExperimental,
        public bool $supportsStreaming,
        public array $pricing,
    ) {}

    public static function fromProviderConfig(ProviderConfigDTO $config): self
    {
        $apiKey = $config->option('api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw ProviderNotConfiguredException::forKey(self::KEY);
        }

        $models = $config->option('models', []);
        $imageModels = $config->option('image_models', []);
        $pricing = $config->option('pricing', []);
        $imageDefault = $config->option('image_default_model');
        $voiceEn = $config->option('tts_voice_en');
        $voiceHi = $config->option('tts_voice_hi');

        return new self(
            apiKey: $apiKey,
            baseUrl: rtrim($config->baseUrl ?? '', '/'),
            defaultModel: $config->defaultModel,
            timeout: $config->timeout,
            maxRetries: $config->maxRetries,
            models: is_array($models) ? array_values(array_filter($models, 'is_string')) : [],
            imageModels: is_array($imageModels) ? array_values(array_filter($imageModels, 'is_string')) : [],
            imageDefaultModel: is_string($imageDefault) && $imageDefault !== '' ? $imageDefault : null,
            voiceEn: is_string($voiceEn) && trim($voiceEn) !== '' ? trim($voiceEn) : null,
            voiceHi: is_string($voiceHi) && trim($voiceHi) !== '' ? trim($voiceHi) : null,
            gujaratiExperimental: (bool) $config->option('gujarati_experimental', false),
            supportsStreaming: (bool) $config->option('supports_streaming', false),
            pricing: is_array($pricing) ? $pricing : [],
        );
    }
}
