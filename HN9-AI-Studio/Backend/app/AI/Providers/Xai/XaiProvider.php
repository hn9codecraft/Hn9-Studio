<?php

declare(strict_types=1);

namespace App\AI\Providers\Xai;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderHealthDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Providers\AbstractProvider;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VideoRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\TokenResponse;
use App\AI\Responses\VideoResponse;
use App\AI\Responses\VoiceResponse;
use App\AI\Support\Capability;
use Throwable;

final class XaiProvider extends AbstractProvider
{
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly XaiClient $client,
        private readonly XaiModelRegistry $models,
        private readonly XaiUsageCalculator $usageCalculator,
        private readonly XaiResponseNormalizer $normalizer,
        private readonly XaiTokenCounter $tokenCounter,
        private readonly XaiConfig $xaiConfig,
        private readonly XaiImageParameterMap $images,
    ) {
        parent::__construct(new ProviderConfigDTO(
            XaiConfig::KEY,
            $xaiConfig->baseUrl,
            $xaiConfig->defaultModel,
            $xaiConfig->timeout,
            $xaiConfig->maxRetries,
        ));
    }

    public function providerName(): string
    {
        return XaiConfig::KEY;
    }

    public function providerVersion(): string
    {
        return self::VERSION;
    }

    public function generateText(TextRequest $request): TextResponse
    {
        $model = $this->models->resolve($request->model);
        $startedAt = hrtime(true);
        $response = $this->client->post('responses', $this->textPayload($request, $model));

        return $this->normalizer->text($response, $model, $this->elapsedMilliseconds($startedAt));
    }

    public function generateImage(ImageRequest $request): ImageResponse
    {
        if ($this->xaiConfig->imageModels === []) {
            throw UnsupportedCapabilityException::make($this->providerName(), Capability::Image);
        }

        $model = $this->models->resolveImage($request->model);
        $startedAt = hrtime(true);
        $response = $this->client->post('images/generations', $this->images->body(
            $model,
            $request->prompt,
            $request->count,
            $request->size,
            $request->quality,
            $request->format,
        ));

        return $this->normalizer->image($response, $model, $this->elapsedMilliseconds($startedAt));
    }

    public function generateVideo(VideoRequest $request): VideoResponse
    {
        throw UnsupportedCapabilityException::make($this->providerName(), Capability::Video);
    }

    public function generateVoice(VoiceRequest $request): VoiceResponse
    {
        $language = $this->language($request);
        if ($language === 'gu' && ! $this->xaiConfig->gujaratiExperimental) {
            throw ProviderApiException::forProvider(
                XaiConfig::KEY,
                'Gujarati voice stays on ElevenLabs unless the experimental flag is on.',
            );
        }

        $voiceId = $this->voice($request, $language);
        $withTimestamps = ($request->options['with_timestamps'] ?? true) !== false;
        $payload = [
            'text' => $request->input,
            'voice_id' => $voiceId,
            'language' => $language,
        ];
        if ($withTimestamps) {
            $payload['with_timestamps'] = true;
        }
        $startedAt = hrtime(true);
        $response = $this->client->post('tts', $payload);

        return $this->normalizer->voice(
            $response,
            $voiceId,
            $language,
            mb_strlen($request->input),
            $this->elapsedMilliseconds($startedAt),
        );
    }

    public function estimateCost(ProviderRequestDTO $request): float
    {
        $model = $this->models->resolve($request->model);
        $prompt = (string) ($request->parameters['prompt'] ?? '');
        $input = $this->tokenCounter->count($prompt, $model)->count;
        $output = (int) ($request->parameters['max_tokens'] ?? 0);
        $usage = $this->usageCalculator->fromUsage(['input_tokens' => $input, 'output_tokens' => $output], $model);

        return $usage->routingEstimate();
    }

    public function countTokens(string $text, ?string $model = null): TokenResponse
    {
        return $this->tokenCounter->count($text, $this->models->resolve($model));
    }

    public function supportsStreaming(): bool
    {
        return $this->xaiConfig->supportsStreaming;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
    }

    public function supportedModels(): array
    {
        return $this->models->all();
    }

    public function healthCheck(): ProviderHealthDTO
    {
        try {
            $startedAt = hrtime(true);
            $this->client->get('models');

            return ProviderHealthDTO::healthy(XaiConfig::KEY, $this->elapsedMilliseconds($startedAt), now()->toIso8601String());
        } catch (Throwable $exception) {
            return ProviderHealthDTO::unavailable(XaiConfig::KEY, $exception->getMessage(), now()->toIso8601String());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(TextRequest $request, string $model): array
    {
        $input = $request->messages !== [] ? $request->messages : $request->prompt;
        $payload = array_filter([
            'model' => $model,
            'input' => $input,
            'instructions' => $request->system,
            'max_output_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'top_p' => $request->topP,
        ], static fn (mixed $value): bool => $value !== null);

        return [...$payload, ...$request->options];
    }

    private function language(VoiceRequest $request): string
    {
        $language = strtolower(trim((string) ($request->language ?? '')));
        if (in_array($language, ['en', 'hi', 'gu'], true)) {
            return $language;
        }

        return 'en';
    }

    private function voice(VoiceRequest $request, string $language): string
    {
        if (is_string($request->voice) && trim($request->voice) !== '') {
            return trim($request->voice);
        }
        $configured = $language === 'hi' ? $this->xaiConfig->voiceHi : $this->xaiConfig->voiceEn;

        return $configured ?? 'eve';
    }
}
