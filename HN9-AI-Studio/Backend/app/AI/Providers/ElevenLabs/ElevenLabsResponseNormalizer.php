<?php

declare(strict_types=1);

namespace App\AI\Providers\ElevenLabs;

use App\AI\DTOs\ProviderResponseDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Responses\VoiceResponse;
use App\AI\Support\ConfigNormalizer;

/**
 * Translates ElevenLabs output into the shared response objects, so nothing
 * downstream handles vendor shapes — or raw bytes.
 *
 * Synthesis answers with audio rather than JSON, and the shared
 * {@see VoiceResponse} carries a *reference* to audio, not binary data. The
 * bytes are therefore surfaced as a data URI, exactly as the image normalizers
 * already do for inline image output, which keeps one representation of
 * generated media across every modality and provider.
 *
 * Everything the vendor does not express in the shared contract — the character
 * count, the credits charged, the voice name, the request id — travels on the
 * response's raw payload rather than widening that contract.
 */
final readonly class ElevenLabsResponseNormalizer
{
    /**
     * Fallback media types, keyed by the family an output format names
     * (`mp3_44100_128` → `mp3`). Consulted only when the vendor omits a
     * `Content-Type`; its own header always wins.
     */
    private const MEDIA_TYPES = [
        'mp3' => 'audio/mpeg',
        'pcm' => 'audio/pcm',
        'wav' => 'audio/wav',
        'opus' => 'audio/opus',
        'flac' => 'audio/flac',
        'ulaw' => 'audio/basic',
        'alaw' => 'audio/basic',
    ];

    private const FALLBACK_MEDIA_TYPE = 'application/octet-stream';

    public function __construct(private ElevenLabsUsageCalculator $usage) {}

    /**
     * @param  array{body: string, content_type: string|null, request_id: string|null}  $audio
     */
    public function voice(
        array $audio,
        string $model,
        string $voiceId,
        ?string $voiceName,
        string $format,
        int $characters,
        int $executionTimeMs,
    ): VoiceResponse {
        if ($audio['body'] === '') {
            throw ProviderApiException::forProvider(
                ElevenLabsConfig::KEY,
                'ElevenLabs response did not contain audio output.',
            );
        }

        $mediaType = $this->mediaType($audio['content_type'], $format);

        return new VoiceResponse(
            audio: 'data:'.$mediaType.';base64,'.base64_encode($audio['body']),
            model: $model,
            voice: $voiceId,
            format: $format,
            // The vendor does not report duration on the audio route, and it is
            // not derivable from every format, so it is left unstated.
            durationSeconds: null,
            usage: $this->usage->fromCharacters($characters, $model, $executionTimeMs),
            raw: [
                'characters' => $characters,
                'credits' => $this->usage->credits($characters, $model),
                'voice_id' => $voiceId,
                'voice_name' => $voiceName,
                'model_id' => $model,
                'output_format' => $format,
                'media_type' => $mediaType,
                'bytes' => strlen($audio['body']),
                'request_id' => $audio['request_id'],
            ],
        );
    }

    public function envelope(VoiceResponse $response): ProviderResponseDTO
    {
        return ProviderResponseDTO::success($response, ElevenLabsConfig::KEY, $response->usage);
    }

    /**
     * The vendor's declared media type, stripped of any parameters, falling back
     * to the family named by the output format.
     */
    private function mediaType(?string $contentType, string $format): string
    {
        $declared = ConfigNormalizer::nonEmptyString($contentType);

        if ($declared !== null) {
            return trim(explode(';', $declared)[0]);
        }

        $family = strtolower(explode('_', $format)[0]);

        return self::MEDIA_TYPES[$family] ?? self::FALLBACK_MEDIA_TYPE;
    }
}
