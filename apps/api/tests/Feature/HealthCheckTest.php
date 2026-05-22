<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_200(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    public function test_health_endpoint_contains_application_marker(): void
    {
        $response = $this->get('/up');

        $response->assertSee('Application up');
    }
}
