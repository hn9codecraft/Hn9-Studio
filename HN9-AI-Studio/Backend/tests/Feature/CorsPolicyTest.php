<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * M12: CORS uses an explicit origin allowlist, never a wildcard, and never
 * shares credentials (the SPA uses bearer tokens).
 */
class CorsPolicyTest extends TestCase
{
    private const ALLOWED = ['http://127.0.0.1:5173', 'https://studio.example.test'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cors.allowed_origins' => self::ALLOWED]);
    }

    public function test_preflight_from_an_allowed_origin_is_accepted(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://studio.example.test',
            'Access-Control-Request-Method' => 'PATCH',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/v1/auth/profile');

        $response->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', 'https://studio.example.test');
        $this->assertStringContainsString('PATCH', (string) $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsStringIgnoringCase('authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function test_allowed_dev_origin_receives_the_header_on_a_real_request(): void
    {
        $this->withHeaders(['Origin' => 'http://127.0.0.1:5173'])
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173');
    }

    public function test_unlisted_origin_is_not_allowed(): void
    {
        $preflight = $this->withHeaders([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login');

        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));

        $response = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_shipped_config_has_no_wildcards_or_credentials(): void
    {
        $config = require base_path('config/cors.php');

        $this->assertSame(['api/*'], $config['paths']);
        $this->assertNotEmpty($config['allowed_origins']);
        $this->assertNotContains('*', $config['allowed_origins']);
        $this->assertNotContains('*', $config['allowed_methods']);
        $this->assertNotContains('*', $config['allowed_headers']);
        $this->assertSame([], $config['allowed_origins_patterns']);
        $this->assertFalse($config['supports_credentials']);

        foreach ($config['allowed_origins'] as $origin) {
            $this->assertMatchesRegularExpression('#^https?://[^/*]+$#', $origin);
        }
    }
}
