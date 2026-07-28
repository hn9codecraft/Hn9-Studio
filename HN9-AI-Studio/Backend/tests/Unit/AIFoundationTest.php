<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\DTOs\ProviderResponseDTO;
use App\AI\Requests\TextRequest;
use App\AI\Responses\ErrorResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\UsageResponse;
use App\AI\Support\Capability;
use App\AI\Support\HealthStatus;
use App\AI\Support\Modality;
use PHPUnit\Framework\TestCase;

class AIFoundationTest extends TestCase
{
    public function test_capability_dto_reports_supported_capabilities(): void
    {
        $dto = new ProviderCapabilityDTO(
            key: 'alpha',
            name: 'Alpha',
            version: '1.0.0',
            text: true,
            streaming: true,
            models: ['alpha-1'],
        );

        $this->assertTrue($dto->supports(Capability::Text));
        $this->assertTrue($dto->supports(Capability::Streaming));
        $this->assertFalse($dto->supports(Capability::Video));
        $this->assertSame([Capability::Text, Capability::Streaming], $dto->capabilities());
        $this->assertContains('text', $dto->toArray()['capabilities']);
    }

    public function test_modality_maps_to_capability(): void
    {
        $this->assertSame(Capability::Text, Modality::Text->capability());
        $this->assertSame(Capability::Voice, Modality::Voice->capability());
    }

    public function test_health_status_operational_flag(): void
    {
        $this->assertTrue(HealthStatus::Healthy->isOperational());
        $this->assertTrue(HealthStatus::Degraded->isOperational());
        $this->assertFalse(HealthStatus::Unavailable->isOperational());
        $this->assertFalse(HealthStatus::Unknown->isOperational());
    }

    public function test_text_request_normalises_to_array(): void
    {
        $request = new TextRequest(prompt: 'Hello', model: 'alpha-1', maxTokens: 100);

        $this->assertSame(Modality::Text, $request->modality());
        $this->assertSame('alpha-1', $request->model());
        $this->assertSame('Hello', $request->toArray()['prompt']);
    }

    public function test_provider_request_dto_from_request(): void
    {
        $dto = ProviderRequestDTO::fromRequest(new TextRequest(prompt: 'Hi', model: 'm'), 'alpha');

        $this->assertSame(Modality::Text, $dto->modality);
        $this->assertSame('m', $dto->model);
        $this->assertSame('alpha', $dto->providerKey);
    }

    public function test_provider_response_dto_wraps_success_and_failure(): void
    {
        $success = ProviderResponseDTO::success(
            new TextResponse(text: 'done', model: 'm'),
            'alpha',
            new UsageResponse(promptTokens: 3, completionTokens: 5, totalTokens: 8),
        );
        $this->assertTrue($success->success);
        $this->assertSame('done', $success->payload['text']);
        $this->assertSame(8, $success->usage?->totalTokens);

        $failure = ProviderResponseDTO::failure(
            Modality::Text,
            new ErrorResponse(message: 'boom', code: 'x'),
            'alpha',
        );
        $this->assertFalse($failure->success);
        $this->assertSame('boom', $failure->error?->message);
    }

    public function test_provider_config_dto_from_array_defaults(): void
    {
        $config = ProviderConfigDTO::fromArray('alpha', ['default_model' => 'alpha-1']);

        $this->assertSame('alpha', $config->key);
        $this->assertSame('alpha-1', $config->defaultModel);
        $this->assertSame(30, $config->timeout);
        $this->assertSame(2, $config->maxRetries);
    }
}
