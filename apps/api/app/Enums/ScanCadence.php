<?php

declare(strict_types=1);

namespace App\Enums;

enum ScanCadence: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Never = 'never';

    /**
     * Days between scheduled scans. `Never` returns PHP_INT_MAX so the
     * "is due" check in DispatchScheduledScans always returns false.
     */
    public function intervalDays(): int
    {
        return match ($this) {
            self::Weekly => 7,
            self::Monthly => 30,
            self::Never => PHP_INT_MAX,
        };
    }

    /**
     * Stagger window — how many calendar days we spread scans across so
     * the scanner worker isn't hammered on a single day. Falls out of
     * intervalDays() for Weekly (7) and Monthly (30); Never never runs.
     */
    public function staggerWindow(): int
    {
        return match ($this) {
            self::Weekly => 7,
            self::Monthly => 28,
            self::Never => 1,
        };
    }
}
