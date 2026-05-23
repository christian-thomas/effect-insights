<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CookieCategory;
use App\Models\CookieOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CookieOverride>
 */
class CookieOverrideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name_pattern' => fake()->unique()->regexify('[a-z]{3,8}'),
            'category' => fake()->randomElement([
                CookieCategory::Necessary,
                CookieCategory::Preferences,
                CookieCategory::Statistics,
                CookieCategory::Marketing,
            ]),
            'purpose' => fake()->sentence(),
            'justification' => fake()->sentence(),
        ];
    }
}
