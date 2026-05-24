<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Shared `via()` implementation for the platform's notifications.
 *
 * Notifications fire via on-demand routing (`Notification::route(...)`)
 * so the notifiable is always an {@see AnonymousNotifiable}. Each
 * route entry on the notifiable corresponds to one channel — we just
 * return that list. Alert services decide which channels to attach
 * based on per-org config (Slack channel + comma-separated mail
 * recipients).
 *
 * Notifications that use this trait must implement the relevant
 * `toSlack($notifiable)` / `toMail($notifiable)` methods.
 */
trait RoutesViaConfiguredChannels
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof AnonymousNotifiable) {
            // Defensive — every alert service in this codebase uses
            // anonymous routing. Returning empty short-circuits the
            // notification dispatcher safely.
            return [];
        }

        $channels = [];
        if ($notifiable->routeNotificationFor('slack') !== null) {
            $channels[] = 'slack';
        }
        if ($notifiable->routeNotificationFor('mail') !== null) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
