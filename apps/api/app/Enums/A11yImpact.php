<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * axe-core impact levels. Mirrors the strings axe emits on each
 * violation node so we can store and filter by impact without
 * translation.
 *
 * Order matters for thresholding — `Critical` is more severe than
 * `Minor`. The default Slack alert threshold is `Serious`, meaning
 * any Serious or Critical violation fires a notification.
 */
enum A11yImpact: string
{
    case Critical = 'critical';
    case Serious = 'serious';
    case Moderate = 'moderate';
    case Minor = 'minor';

    /**
     * Returns true when this impact is at or above the supplied
     * threshold. Used by the alert service to decide whether a
     * scan with violations at this level should ping Slack.
     */
    public function isAtLeast(self $threshold): bool
    {
        return $this->severityRank() <= $threshold->severityRank();
    }

    /**
     * Lower number = more severe. Inverted from natural reading
     * order so a < comparison reads naturally as "more severe than".
     */
    private function severityRank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Serious => 1,
            self::Moderate => 2,
            self::Minor => 3,
        };
    }
}
