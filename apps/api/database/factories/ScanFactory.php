<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scan>
 */
class ScanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => ScanStatus::Queued,
            'trigger' => ScanTrigger::Manual,
            'urls_discovered' => 0,
            'urls_scanned' => 0,
        ];
    }
}
