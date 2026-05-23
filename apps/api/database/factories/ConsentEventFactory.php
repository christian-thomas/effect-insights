<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConsentEventType;
use App\Models\ConsentEvent;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConsentEvent>
 */
class ConsentEventFactory extends Factory
{
    protected $model = ConsentEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $statistics = fake()->boolean();
        $marketing = fake()->boolean();

        return [
            'id' => (string) Str::uuid(),
            'domain_id' => Domain::factory(),
            'banner_config_version' => 1,
            'anonymous_id' => 'anon_'.Str::random(16),
            'categories' => [
                'necessary' => true,
                'preferences' => fake()->boolean(),
                'statistics' => $statistics,
                'marketing' => $marketing,
            ],
            'gcm_signals' => [
                'analytics_storage' => $statistics ? 'granted' : 'denied',
                'ad_storage' => $marketing ? 'granted' : 'denied',
                'ad_user_data' => $marketing ? 'granted' : 'denied',
                'ad_personalization' => $marketing ? 'granted' : 'denied',
                'functionality_storage' => 'granted',
                'personalization_storage' => 'denied',
                'security_storage' => 'granted',
            ],
            'country_code' => fake()->randomElement(['GB', 'FR', 'DE', 'US', null]),
            'user_agent_hash' => 'ua_'.Str::random(20),
            'ip_hash' => 'ip_'.Str::random(20),
            'event_type' => fake()->randomElement([
                ConsentEventType::Granted,
                ConsentEventType::Denied,
                ConsentEventType::Changed,
            ]),
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'banner_text_hash' => 'btxt_v1',
        ];
    }
}
