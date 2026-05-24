<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\A11yImpact;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class A11yImpactTest extends TestCase
{
    #[Test]
    public function critical_is_at_least_serious_moderate_and_minor(): void
    {
        $this->assertTrue(A11yImpact::Critical->isAtLeast(A11yImpact::Critical));
        $this->assertTrue(A11yImpact::Critical->isAtLeast(A11yImpact::Serious));
        $this->assertTrue(A11yImpact::Critical->isAtLeast(A11yImpact::Moderate));
        $this->assertTrue(A11yImpact::Critical->isAtLeast(A11yImpact::Minor));
    }

    #[Test]
    public function serious_meets_serious_minor_and_moderate_but_not_critical(): void
    {
        $this->assertFalse(A11yImpact::Serious->isAtLeast(A11yImpact::Critical));
        $this->assertTrue(A11yImpact::Serious->isAtLeast(A11yImpact::Serious));
        $this->assertTrue(A11yImpact::Serious->isAtLeast(A11yImpact::Moderate));
        $this->assertTrue(A11yImpact::Serious->isAtLeast(A11yImpact::Minor));
    }

    #[Test]
    public function minor_only_meets_itself(): void
    {
        $this->assertFalse(A11yImpact::Minor->isAtLeast(A11yImpact::Critical));
        $this->assertFalse(A11yImpact::Minor->isAtLeast(A11yImpact::Serious));
        $this->assertFalse(A11yImpact::Minor->isAtLeast(A11yImpact::Moderate));
        $this->assertTrue(A11yImpact::Minor->isAtLeast(A11yImpact::Minor));
    }
}
