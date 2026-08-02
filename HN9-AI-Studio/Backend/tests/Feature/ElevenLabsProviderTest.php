<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Providers\ElevenLabs\ElevenLabsClient;
use App\AI\Providers\ElevenLabs\ElevenLabsConfig;
use App\AI\Providers\ElevenLabs\ElevenLabsProvider;
use App\AI\Providers\ElevenLabs\ElevenLabsResponseNormalizer;
use App\AI\Providers\ElevenLabs\ElevenLabsTokenCounter;
use App\AI\Providers\ElevenLabs\ElevenLabsUsageCalculator;
use App\AI\Providers\ElevenLabs\ElevenLabsVoiceRegistry;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VideoRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Support\Capability;
use App\AI\Support\HealthStatus;
use App\AI\Support\Modality;
use App\AI\Support\ProviderConfigResolver;
use App\Providers\AIServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElevenLabsProviderTest extends TestCase
{
    private const SPEECH_URL = 'https://api.elevenlabs.io/v1/text-to-speech/*';

    private const SUBSCRIPTION_URL = 'https://api.elevenlabs.io/v1/user/subscription';

    private const VOICE_URL = 'https://api.elevenlabs.io/v1/voices/voice-id-one';

    private const MODELS_URL = 'https://api.elevenlabs.io/v1/models';

    /** Stand-in for the binary body the vendor returns; never a mocked API. */
    private const AUDIO_BYTES = "ID3\x04\x00audio-bytes";

    /**
     * Laravel executes any request that matches no stub for real. This suite
     * exercises a live vendor's routes, so a stray request must fail loudly
     * rather than reach ElevenLabs with a test credential.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Builds the adapter exactly as the service provider does, from a
     * configuration array — no voice, model or format is hardcoded in the adapter.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function provider(array $overrides = []): ElevenLabsProvider
    {
        $config = $this->config($overrides);
        $usage = new ElevenLabsUsageCalculator($config);

        return new ElevenLabsProvider(
            new ElevenLabsClient($this->app->make(Factory::class), $config),
            new ElevenLabsVoiceRegistry($config),
            $usage,
            new ElevenLabsResponseNormalizer($usage),
            new ElevenLabsTokenCounter,
            $config,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): ElevenLabsConfig
    {
        return ElevenLabsConfig::fromProviderConfig(
            ProviderConfigDTO::fromArray('elevenlabs', [...$this->settings(), ...$overrides]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'api_key' => 'test-key',
            'base_url' => 'https://api.elevenlabs.io/v1',
            'default_model' => 'model-standard',
            'models' => ['model-standard', 'model-fast'],
            'voices' => ['Rachel' => 'voice-id-one', 'Adam' => 'voice-id-two'],
            'default_voice' => 'Rachel',
            'output_format' => 'mp3_44100_128',
            'output_formats' => ['mp3_44100_128', 'pcm_16000'],
            'voice_settings' => ['stability' => 0.5, 'similarity' => 0.75],
            'credit_multipliers' => ['model-fast' => 0.5],
            'supports_streaming' => true,
            // Per-million-credit USD rates.
            'pricing' => ['model-standard' => ['input' => 300.0]],
        ];
    }

    private function fakeAudio(): void
    {
        Http::fake([
            self::SPEECH_URL => Http::response(self::AUDIO_BYTES, 200, ['Content-Type' => 'audio/mpeg', 'request-id' => 'req-123']),
        ]);
    }

    // -------------------------------------------------------------- speech --

    public function test_voice_generation_is_normalized_into_the_shared_voice_response(): void
    {
        $this->fakeAudio();

        $response = $this->provider()->generateVoice(new VoiceRequest(input: 'Hello studio.'));

        $this->assertSame('data:audio/mpeg;base64,'.base64_encode(self::AUDIO_BYTES), $response->audio);
        $this->assertSame('model-standard', $response->model);
        $this->assertSame('voice-id-one', $response->voice);
        $this->assertSame('mp3_44100_128', $response->format);
        $this->assertSame(Modality::Voice, $response->modality());
        // The vendor does not report duration on the audio route; it is not invented.
        $this->assertNull($response->durationSeconds);
    }

    public function test_the_payload_carries_text_model_language_and_merged_voice_settings(): void
    {
        $this->fakeAudio();

        $this->provider()->generateVoice(new VoiceRequest(
            input: 'Hello studio.',
            model: 'model-fast',
            voice: 'Rachel',
            language: 'en',
            format: 'mp3_44100_128',
            speed: 1.1,
            options: ['style' => 0.3, 'speaker_boost' => true],
        ));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return str_starts_with($request->url(), 'https://api.elevenlabs.io/v1/text-to-speech/voice-id-one')
                && $body['text'] === 'Hello studio.'
                && $body['model_id'] === 'model-fast'
                && $body['language_code'] === 'en'
                && $body['voice_settings'] === [
                    // Configured defaults, overlaid with the request's own settings.
                    'stability' => 0.5,
                    'similarity_boost' => 0.75,
                    'style' => 0.3,
                    'use_speaker_boost' => true,
                    'speed' => 1.1,
                ];
        });
    }

    public function test_the_output_format_travels_as_a_query_parameter(): void
    {
        $this->fakeAudio();

        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.', format: 'pcm_16000'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'output_format=pcm_16000'));
    }

    public function test_the_typed_speed_field_wins_over_the_same_key_in_options(): void
    {
        $this->fakeAudio();

        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.', speed: 1.2, options: ['speed' => 0.8]));

        Http::assertSent(fn (Request $request): bool => $request->data()['voice_settings']['speed'] === 1.2);
    }

    public function test_nested_voice_settings_are_accepted_in_the_vendor_spelling(): void
    {
        $this->fakeAudio();

        $this->provider()->generateVoice(new VoiceRequest(
            input: 'Hello.',
            options: ['voice_settings' => ['similarity_boost' => 0.9, 'use_speaker_boost' => false]],
        ));

        Http::assertSent(fn (Request $request): bool => $request->data()['voice_settings'] === [
            'stability' => 0.5,
            'similarity_boost' => 0.9,
            'use_speaker_boost' => false,
        ]);
    }

    public function test_unmodelled_vendor_parameters_pass_through_but_voice_settings_keys_do_not(): void
    {
        $this->fakeAudio();

        $this->provider()->generateVoice(new VoiceRequest(
            input: 'Hello.',
            options: ['seed' => 42, 'apply_text_normalization' => 'auto', 'stability' => 0.9],
        ));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $body['seed'] === 42
                && $body['apply_text_normalization'] === 'auto'
                // Consumed as a voice setting, so it must not also sit at the top level.
                && ! array_key_exists('stability', $body)
                && $body['voice_settings']['stability'] === 0.9;
        });
    }

    public function test_the_credential_header_accompanies_every_request(): void
    {
        $this->fakeAudio();

        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('xi-api-key', 'test-key'));
    }

    // ------------------------------------------------------- normalization --

    public function test_usage_reports_characters_credits_cost_and_execution_time(): void
    {
        $this->fakeAudio();

        $response = $this->provider()->generateVoice(new VoiceRequest(input: 'Hello studio.'));

        // 13 characters at 1 credit each on the standard model.
        $this->assertSame(13, $response->raw['characters']);
        $this->assertSame(13, $response->raw['credits']);
        $this->assertSame(13, $response->usage?->promptTokens);
        $this->assertSame(13, $response->usage?->totalTokens);
        $this->assertSame(0, $response->usage?->completionTokens);
        $this->assertNotNull($response->usage?->executionTimeMs);
        // 13 credits at 300.00 per million credits.
        $this->assertEqualsWithDelta(0.0039, $response->usage?->cost ?? 0.0, 0.0000001);
    }

    public function test_a_configured_credit_multiplier_reduces_the_credits_charged(): void
    {
        $this->fakeAudio();

        $response = $this->provider()->generateVoice(new VoiceRequest(input: 'Hello studio.', model: 'model-fast'));

        $this->assertSame(13, $response->raw['characters']);
        // The fast model bills half a credit per character, rounded up.
        $this->assertSame(7, $response->raw['credits']);
        // ...and carries no configured rate, so the cost stays zero rather than invented.
        $this->assertSame(0.0, $response->usage?->cost);
    }

    public function test_the_response_records_the_voice_model_and_request_id_used(): void
    {
        $this->fakeAudio();

        $raw = $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.', voice: 'Adam'))->raw;

        $this->assertSame('voice-id-two', $raw['voice_id']);
        $this->assertSame('Adam', $raw['voice_name']);
        $this->assertSame('model-standard', $raw['model_id']);
        $this->assertSame('mp3_44100_128', $raw['output_format']);
        $this->assertSame('audio/mpeg', $raw['media_type']);
        $this->assertSame(strlen(self::AUDIO_BYTES), $raw['bytes']);
        $this->assertSame('req-123', $raw['request_id']);
    }

    public function test_the_media_type_is_derived_from_the_format_when_the_vendor_omits_it(): void
    {
        Http::fake([self::SPEECH_URL => Http::response(self::AUDIO_BYTES, 200)]);

        $response = $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.', format: 'pcm_16000'));

        $this->assertStringStartsWith('data:audio/pcm;base64,', $response->audio);
    }

    public function test_content_type_parameters_are_stripped_from_the_media_type(): void
    {
        Http::fake([self::SPEECH_URL => Http::response(self::AUDIO_BYTES, 200, ['Content-Type' => 'audio/mpeg; charset=binary'])]);

        $response = $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));

        $this->assertStringStartsWith('data:audio/mpeg;base64,', $response->audio);
    }

    public function test_responses_are_wrapped_in_the_provider_independent_envelope(): void
    {
        $this->fakeAudio();

        $config = $this->config();
        $normalizer = new ElevenLabsResponseNormalizer(new ElevenLabsUsageCalculator($config));
        $envelope = $normalizer->envelope($this->provider()->generateVoice(new VoiceRequest(input: 'Hello studio.')));

        $this->assertTrue($envelope->success);
        $this->assertSame(Modality::Voice, $envelope->modality);
        $this->assertSame('elevenlabs', $envelope->providerKey);
        $this->assertSame('model-standard', $envelope->model);
        $this->assertSame('voice-id-one', $envelope->payload['voice']);
        $this->assertSame(13, $envelope->usage?->totalTokens);
    }

    public function test_an_empty_audio_body_is_a_typed_parsing_failure(): void
    {
        Http::fake([self::SPEECH_URL => Http::response('', 200, ['Content-Type' => 'audio/mpeg'])]);

        try {
            $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertStringContainsString('empty audio', $exception->getMessage());
            $this->assertSame('elevenlabs', $exception->context()['provider'] ?? null);
        }
    }

    // ------------------------------------------------------- voice registry --

    public function test_voices_are_loaded_dynamically_from_configuration(): void
    {
        $provider = $this->provider();

        $this->assertSame(['Rachel' => 'voice-id-one', 'Adam' => 'voice-id-two'], $provider->supportedVoices());
        $this->assertSame(['model-standard', 'model-fast'], $provider->supportedModels());
        $this->assertSame('elevenlabs', $provider->providerName());
        $this->assertSame(ElevenLabsProvider::VERSION, $provider->providerVersion());
    }

    public function test_a_voice_resolves_by_name_case_insensitively_or_by_identifier(): void
    {
        $registry = new ElevenLabsVoiceRegistry($this->config());

        $this->assertSame('voice-id-one', $registry->resolveVoice('Rachel'));
        $this->assertSame('voice-id-one', $registry->resolveVoice('rachel'));
        $this->assertSame('voice-id-one', $registry->resolveVoice('voice-id-one'));
        $this->assertSame('voice-id-two', $registry->resolveVoice('Adam'));
        // No voice requested falls back to the configured default.
        $this->assertSame('voice-id-one', $registry->resolveVoice(null));
        $this->assertSame('Rachel', $registry->voiceName('voice-id-one'));
        $this->assertNull($registry->voiceName('voice-id-unknown'));
    }

    public function test_any_future_or_custom_voice_is_adopted_by_configuration_alone(): void
    {
        Http::fake(['https://api.elevenlabs.io/v1/text-to-speech/*' => Http::response(self::AUDIO_BYTES, 200)]);

        $provider = $this->provider([
            'voices' => ['Studio Narrator' => 'custom-clone-9', 'Future' => 'voice-not-yet-released'],
            'default_voice' => 'Studio Narrator',
        ]);

        $this->assertSame(['Studio Narrator', 'Future'], array_keys($provider->supportedVoices()));
        $this->assertSame('voice-not-yet-released', $provider->generateVoice(
            new VoiceRequest(input: 'Hello.', voice: 'Future'),
        )->voice);
    }

    public function test_an_unconfigured_voice_is_rejected_before_any_call_is_made(): void
    {
        Http::fake();

        try {
            $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.', voice: 'Unlisted'));
            $this->fail('Expected an unconfigured-voice failure.');
        } catch (ProviderNotConfiguredException $exception) {
            $this->assertSame('elevenlabs', $exception->context()['key'] ?? null);
        }

        Http::assertNothingSent();
    }

    public function test_an_unconfigured_model_is_rejected_before_any_call_is_made(): void
    {
        Http::fake();

        $this->expectException(ProviderNotConfiguredException::class);
        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.', model: 'model-unlisted'));
    }

    public function test_an_output_format_outside_the_configured_allow_list_is_rejected(): void
    {
        Http::fake();

        $this->expectException(ProviderNotConfiguredException::class);
        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.', format: 'opus_48000_128'));
    }

    public function test_formats_are_unrestricted_when_no_allow_list_is_configured(): void
    {
        Http::fake([self::SPEECH_URL => Http::response(self::AUDIO_BYTES, 200)]);

        $response = $this->provider(['output_formats' => []])
            ->generateVoice(new VoiceRequest(input: 'Hello.', format: 'opus_48000_128'));

        $this->assertSame('opus_48000_128', $response->format);
    }

    // ------------------------------------------------------------ counting --

    public function test_characters_are_counted_exactly_rather_than_estimated(): void
    {
        $counter = new ElevenLabsTokenCounter;

        // The shared four-characters-per-token estimate would say 2; ElevenLabs bills 8.
        $this->assertSame(8, $counter->characters('12345678'));
        $this->assertSame(8, $counter->count('12345678', 'model-standard')->count);
        // Multibyte text is counted in characters, as the vendor bills it.
        $this->assertSame(3, $counter->characters('añé'));
        $this->assertSame(0, $counter->characters(''));
    }

    public function test_token_counting_reports_the_capability_as_unsupported(): void
    {
        try {
            $this->provider()->countTokens('Hello.');
            $this->fail('Expected an unsupported-capability exception.');
        } catch (UnsupportedCapabilityException $exception) {
            $this->assertSame('elevenlabs', $exception->context()['provider'] ?? null);
        }
    }

    public function test_cost_estimation_prices_characters_with_the_configured_rates(): void
    {
        $cost = $this->provider()->estimateCost(
            ProviderRequestDTO::fromRequest(new VoiceRequest(input: 'Hello studio.')),
        );

        // 13 credits at 300.00 per million credits. No API call is made.
        $this->assertEqualsWithDelta(0.0039, $cost, 0.0000001);
    }

    public function test_cost_estimation_of_an_unpriced_model_is_zero_rather_than_invented(): void
    {
        $cost = $this->provider()->estimateCost(
            ProviderRequestDTO::fromRequest(new VoiceRequest(input: 'Hello studio.', model: 'model-fast')),
        );

        $this->assertSame(0.0, $cost);
    }

    // --------------------------------------------------------------- health --

    public function test_health_check_validates_authentication_the_voice_and_the_model(): void
    {
        Http::fake([
            self::SUBSCRIPTION_URL => Http::response(['tier' => 'creator', 'character_count' => 1200, 'character_limit' => 100000]),
            self::VOICE_URL => Http::response(['voice_id' => 'voice-id-one', 'name' => 'Rachel']),
            self::MODELS_URL => Http::response([['model_id' => 'model-standard'], ['model_id' => 'model-fast']]),
        ]);

        $health = $this->provider()->healthCheck();

        $this->assertSame(HealthStatus::Healthy, $health->status);
        $this->assertTrue($health->isOperational());
        $this->assertSame('elevenlabs', $health->key);
        $this->assertNotNull($health->latencyMs);
        $this->assertNotNull($health->checkedAt);
        $this->assertSame('model-standard', $health->details['default_model']);
        $this->assertTrue($health->details['model_verified']);
        $this->assertSame('voice-id-one', $health->details['default_voice']);
        $this->assertSame('Rachel', $health->details['default_voice_name']);
        $this->assertTrue($health->details['voice_verified']);
        $this->assertSame(2, $health->details['voices_configured']);
        $this->assertSame(2, $health->details['models_configured']);
        $this->assertSame('mp3_44100_128', $health->details['output_format']);
        $this->assertSame('creator', $health->details['tier']);
        $this->assertSame(1200, $health->details['characters_used']);
        $this->assertSame(100000, $health->details['character_limit']);
    }

    public function test_health_check_is_degraded_when_the_configured_voice_is_missing(): void
    {
        Http::fake([
            self::SUBSCRIPTION_URL => Http::response(['tier' => 'creator']),
            self::VOICE_URL => Http::response(['detail' => ['status' => 'voice_not_found', 'message' => 'Voice not found']], 404),
            self::MODELS_URL => Http::response([['model_id' => 'model-standard']]),
        ]);

        $health = $this->provider()->healthCheck();

        $this->assertSame(HealthStatus::Degraded, $health->status);
        // Degraded still routes: the credential works, one asset is in doubt.
        $this->assertTrue($health->isOperational());
        $this->assertFalse($health->details['voice_verified']);
        $this->assertTrue($health->details['model_verified']);
        $this->assertStringContainsString('voice', (string) $health->message);
    }

    public function test_health_check_is_degraded_when_the_configured_model_is_absent(): void
    {
        Http::fake([
            self::SUBSCRIPTION_URL => Http::response(['tier' => 'creator']),
            self::VOICE_URL => Http::response(['voice_id' => 'voice-id-one']),
            self::MODELS_URL => Http::response([['model_id' => 'some-other-model']]),
        ]);

        $health = $this->provider()->healthCheck();

        $this->assertSame(HealthStatus::Degraded, $health->status);
        $this->assertFalse($health->details['model_verified']);
        $this->assertStringContainsString('model', (string) $health->message);
    }

    public function test_health_check_reports_unavailable_when_authentication_fails(): void
    {
        Http::fake([self::SUBSCRIPTION_URL => Http::response(['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key']], 401)]);

        $health = $this->provider()->healthCheck();

        $this->assertSame(HealthStatus::Unavailable, $health->status);
        $this->assertFalse($health->isOperational());
    }

    public function test_health_check_reports_unavailable_when_no_voice_is_configured(): void
    {
        Http::fake();

        $health = $this->provider(['voices' => [], 'default_voice' => null])->healthCheck();

        $this->assertFalse($health->isOperational());
        $this->assertStringContainsString('elevenlabs', (string) $health->message);
    }

    // ------------------------------------------------------------ contracts --

    public function test_text_image_and_video_report_the_unsupported_capability(): void
    {
        $provider = $this->provider();

        foreach ([Capability::Text, Capability::Image, Capability::Video] as $capability) {
            try {
                match ($capability) {
                    Capability::Text => $provider->generateText(new TextRequest(prompt: 'x')),
                    Capability::Image => $provider->generateImage(new ImageRequest(prompt: 'x')),
                    default => $provider->generateVideo(new VideoRequest(prompt: 'x')),
                };
                $this->fail("Expected an unsupported-capability exception for {$capability->value}.");
            } catch (UnsupportedCapabilityException $exception) {
                $this->assertSame($capability->value, $exception->context()['capability'] ?? null);
                $this->assertSame('elevenlabs', $exception->context()['provider'] ?? null);
            }
        }
    }

    public function test_capability_flags_reflect_what_the_vendor_actually_offers(): void
    {
        $this->assertTrue($this->provider()->supportsStreaming());
        $this->assertFalse($this->provider(['supports_streaming' => false])->supportsStreaming());
        // Text-to-speech has no function calling; it is not declared.
        $this->assertFalse($this->provider()->supportsFunctionCalling());
    }

    // ----------------------------------------------------- error taxonomies --

    public function test_http_401_is_mapped_to_a_typed_authentication_exception(): void
    {
        Http::fake([self::SPEECH_URL => Http::response(['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key']], 401)]);

        $this->expectException(ProviderAuthenticationException::class);
        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));
    }

    public function test_an_exhausted_quota_is_not_reported_as_an_authentication_failure(): void
    {
        // The vendor reports an empty balance as 401; rotating the key would not help.
        Http::fake([self::SPEECH_URL => Http::response([
            'detail' => ['status' => 'quota_exceeded', 'message' => 'You have exceeded your character quota'],
        ], 401)]);

        try {
            $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertSame('You have exceeded your character quota', $exception->getMessage());
            $this->assertSame(402, $exception->statusCode());
        }
    }

    public function test_rate_limiting_is_mapped_to_a_typed_exception(): void
    {
        Http::fake([self::SPEECH_URL => Http::response(['detail' => 'Too many requests'], 429)]);

        $this->expectException(ProviderRateLimitException::class);
        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));
    }

    public function test_an_invalid_voice_rejected_by_the_vendor_is_a_typed_api_exception(): void
    {
        Http::fake([self::SPEECH_URL => Http::response([
            'detail' => ['status' => 'voice_not_found', 'message' => 'A voice with voice_id voice-id-one was not found.'],
        ], 404)]);

        try {
            $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertSame('A voice with voice_id voice-id-one was not found.', $exception->getMessage());
            $this->assertSame(404, $exception->statusCode());
        }
    }

    public function test_a_validation_failure_surfaces_the_vendors_own_message(): void
    {
        Http::fake([self::SPEECH_URL => Http::response([
            'detail' => [['loc' => ['body', 'text'], 'msg' => 'text must not be empty', 'type' => 'value_error']],
        ], 422)]);

        try {
            $this->provider()->generateVoice(new VoiceRequest(input: ''));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertSame('text must not be empty', $exception->getMessage());
            $this->assertSame(422, $exception->statusCode());
        }
    }

    public function test_a_string_detail_is_surfaced_as_the_error_message(): void
    {
        Http::fake([self::SPEECH_URL => Http::response(['detail' => 'Internal server error'], 500)]);

        try {
            $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertSame('Internal server error', $exception->getMessage());
        }
    }

    public function test_a_timeout_is_mapped_to_a_typed_timeout_exception(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds'));

        $this->expectException(ProviderTimeoutException::class);
        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));
    }

    public function test_a_connection_failure_is_mapped_to_a_typed_network_exception(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('Could not resolve host: api.elevenlabs.io'));

        $this->expectException(ProviderNetworkException::class);
        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello.'));
    }

    // -------------------------------------------------------- configuration --

    public function test_missing_credentials_raise_a_configuration_error(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->config(['api_key' => null]);
    }

    public function test_a_missing_base_url_raises_a_configuration_error(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->config(['base_url' => '']);
    }

    public function test_configuration_is_loaded_dynamically_from_config_and_the_environment(): void
    {
        config()->set('ai.providers.elevenlabs', [
            'api_key' => 'env-key',
            'base_url' => 'https://api.elevenlabs.io/v1/',
            'default_model' => 'model-future',
            'models' => ['model-future', ' model-next ', ''],
            // A map survives a single environment variable as name:id pairs.
            'voices' => 'Bella:voice-bella, Narrator:voice-narrator',
            'default_voice' => 'Bella',
            'output_format' => 'mp3_22050_32',
            'timeout' => 45,
            'max_retries' => 5,
            'voice_settings' => ['stability' => '0.4', 'speaker_boost' => 'true', 'unknown' => 1],
        ]);

        $config = ElevenLabsConfig::fromProviderConfig(
            $this->app->make(ProviderConfigResolver::class)->resolve('elevenlabs'),
        );

        $this->assertSame('env-key', $config->apiKey);
        $this->assertSame('https://api.elevenlabs.io/v1', $config->baseUrl);
        $this->assertSame(['model-future', 'model-next'], $config->models);
        $this->assertSame(['Bella' => 'voice-bella', 'Narrator' => 'voice-narrator'], $config->voices);
        $this->assertSame('Bella', $config->defaultVoice);
        $this->assertSame('mp3_22050_32', $config->outputFormat);
        $this->assertSame(45, $config->timeout);
        $this->assertSame(5, $config->maxRetries);
        // Loose values are coerced to the vendor's spellings and types; unknown keys drop.
        $this->assertSame(['stability' => 0.4, 'use_speaker_boost' => true], $config->voiceSettings);
    }

    // --------------------------------------------------------- registration --

    public function test_elevenlabs_registers_through_the_provider_registry(): void
    {
        config()->set('ai.providers.elevenlabs', [...$this->settings(), 'enabled' => true, 'priority' => 60]);

        (new AIServiceProvider($this->app))->boot();

        $registry = $this->app->make(ProviderRegistryInterface::class);
        $manager = $this->app->make(ProviderManagerInterface::class);

        $this->assertTrue($registry->has('elevenlabs'));
        $this->assertSame('elevenlabs', $this->app->make(ProviderFactoryInterface::class)->make('elevenlabs')->providerName());
        $this->assertContains('elevenlabs', $manager->forCapability(Capability::Voice));
        $this->assertNotContains('elevenlabs', $manager->forCapability(Capability::Text));
        $this->assertNotContains('elevenlabs', $manager->forCapability(Capability::Image));

        $capabilities = $manager->capabilities('elevenlabs');
        $this->assertSame(['model-standard', 'model-fast'], $capabilities->models);
        $this->assertSame(ElevenLabsProvider::VERSION, $capabilities->version);
        $this->assertSame(['voice', 'streaming'], array_map(
            static fn (Capability $capability): string => $capability->value,
            $capabilities->capabilities(),
        ));
    }

    public function test_a_disabled_provider_is_never_registered(): void
    {
        config()->set('ai.providers.elevenlabs', [...$this->settings(), 'enabled' => false]);

        (new AIServiceProvider($this->app))->boot();

        $this->assertFalse($this->app->make(ProviderRegistryInterface::class)->has('elevenlabs'));
    }

    public function test_elevenlabs_registers_alongside_the_existing_providers_without_disturbing_them(): void
    {
        config()->set('ai.providers.elevenlabs', [...$this->settings(), 'enabled' => true]);
        config()->set('ai.providers.openai', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'future-model', 'models' => ['future-model'],
        ]);
        config()->set('ai.providers.claude', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.example/v1',
            'version' => 'test-version', 'default_model' => 'future-claude', 'models' => ['future-claude'],
        ]);
        config()->set('ai.providers.gemini', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://generativelanguage.googleapis.com',
            'version' => 'v1beta', 'default_model' => 'future-gemini', 'models' => ['future-gemini'],
        ]);
        config()->set('ai.providers.openrouter', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://openrouter.ai/api/v1',
            'default_model' => 'vendor-a/model-one', 'models' => ['vendor-a/model-one'],
        ]);

        (new AIServiceProvider($this->app))->boot();

        $manager = $this->app->make(ProviderManagerInterface::class);
        $factory = $this->app->make(ProviderFactoryInterface::class);

        foreach (['elevenlabs', 'openai', 'claude', 'gemini', 'openrouter'] as $key) {
            $this->assertTrue($manager->has($key));
            $this->assertSame($key, $factory->make($key)->providerName());
        }

        // The voice provider joins without displacing text routing.
        $this->assertSame(['openai', 'claude', 'gemini', 'openrouter'], $manager->forCapability(Capability::Text));
        $this->assertSame(['elevenlabs'], $manager->forCapability(Capability::Voice));
    }

    public function test_aggregate_health_isolates_elevenlabs_from_the_other_providers(): void
    {
        config()->set('ai.providers.elevenlabs', [...$this->settings(), 'enabled' => true]);
        config()->set('ai.providers.openai', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'future-model', 'models' => ['future-model'],
        ]);

        Http::fake([
            self::SUBSCRIPTION_URL => Http::response(['tier' => 'creator']),
            self::VOICE_URL => Http::response(['voice_id' => 'voice-id-one']),
            self::MODELS_URL => Http::response([['model_id' => 'model-standard']]),
            'https://api.openai.com/*' => Http::response([], 500),
        ]);

        (new AIServiceProvider($this->app))->boot();

        $health = $this->app->make(ProviderManagerInterface::class)->health();

        $this->assertTrue($health['elevenlabs']->isOperational());
        $this->assertFalse($health['openai']->isOperational());
    }
}
