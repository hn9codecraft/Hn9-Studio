<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AI\Support\ProviderErrorSanitizer;
use Tests\TestCase;

final class ProviderErrorSanitizerTest extends TestCase
{
    public function test_it_redacts_openai_style_secrets_from_vendor_messages(): void
    {
        $message = ProviderErrorSanitizer::message('Incorrect API key provided: sk-live-should-never-leak');

        $this->assertStringNotContainsString('sk-live-should-never-leak', $message);
        $this->assertStringContainsString('[redacted]', $message);
    }

    public function test_it_redacts_secret_context_keys(): void
    {
        $context = ProviderErrorSanitizer::context([
            'api_key' => 'sk-test-openai-key',
            'provider' => 'openai',
        ]);

        $this->assertSame('[redacted]', $context['api_key']);
        $this->assertSame('openai', $context['provider']);
    }
}
