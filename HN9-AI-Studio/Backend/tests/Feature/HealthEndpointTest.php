<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_status_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'version',
                'environment',
                'timestamp',
                'services' => ['database'],
            ])
            ->assertJson([
                'status' => 'ok',
                'version' => config('app.version'),
                'environment' => config('app.env'),
                'services' => ['database' => true],
            ]);
    }

    public function test_health_endpoint_is_publicly_accessible(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
    }
}
