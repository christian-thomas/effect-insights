<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    #[Test]
    public function health_endpoint_returns_200(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    #[Test]
    public function health_endpoint_contains_application_marker(): void
    {
        $response = $this->get('/up');

        $response->assertSee('Application up');
    }
}
