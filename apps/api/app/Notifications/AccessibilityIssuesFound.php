<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AccessibilityScan;
use App\Models\Domain;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Fires when an accessibility scan completes with violations at or
 * above the configured threshold (default: Serious). Routed through
 * {@see \App\Services\Notifications\AccessibilityAlertService}.
 *
 * Slack message shape mirrors {@see UnknownCookieFound} so on-call
 * sees a consistent reading experience between the three alert types
 * (unknown cookies, scan failures, accessibility issues).
 */
final class AccessibilityIssuesFound extends Notification
{
    /**
     * @param  array<string, int>  $byImpact  keyed by impact, total counts
     */
    public function __construct(
        public readonly AccessibilityScan $scan,
        public readonly Domain $domain,
        public readonly array $byImpact,
        public readonly int $total,
        public readonly string $detailUrl,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $organisation = $this->domain
            ->organisation()
            ->withoutGlobalScope('organisation')
            ->first();
        $orgName = (string) ($organisation?->getAttribute('name') ?? 'unknown org');
        $fqdn = (string) $this->domain->getAttribute('fqdn');

        $summary = sprintf(
            'Critical %d · Serious %d · Moderate %d · Minor %d',
            $this->byImpact['critical'] ?? 0,
            $this->byImpact['serious'] ?? 0,
            $this->byImpact['moderate'] ?? 0,
            $this->byImpact['minor'] ?? 0,
        );

        return (new SlackMessage)
            ->text(sprintf('%d accessibility violation%s on %s', $this->total, $this->total === 1 ? '' : 's', $fqdn))
            ->headerBlock(sprintf(
                '%d accessibility violation%s found',
                $this->total,
                $this->total === 1 ? '' : 's',
            ))
            ->contextBlock(function (ContextBlock $block) use ($fqdn, $orgName): void {
                $block->text(sprintf('*%s* · %s', $fqdn, $orgName))->markdown();
                $block->text(sprintf('Scan `%s`', (string) $this->scan->getKey()))->markdown();
            })
            ->sectionBlock(function (SectionBlock $block) use ($summary): void {
                $block->text($summary)->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text(sprintf('<%s|Open scan in admin →>', $this->detailUrl))->markdown();
            })
            ->dividerBlock();
    }
}
