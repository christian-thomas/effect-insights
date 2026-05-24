<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AccessibilityScan;
use App\Models\Domain;
use App\Notifications\Concerns\RoutesViaConfiguredChannels;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Fires when an accessibility scan throws. Sibling to {@see ScanFailed}
 * (cookie scanner) — same block-kit shape so on-call gets a consistent
 * reading experience, but routes link to the accessibility-scan detail
 * page rather than the cookie-scan one.
 */
final class AccessibilityScanFailed extends Notification
{
    use RoutesViaConfiguredChannels;

    public function __construct(
        public readonly AccessibilityScan $scan,
        public readonly Domain $domain,
        public readonly string $errorMessage,
        public readonly string $errorClass,
        public readonly string $detailUrl,
    ) {}

    public function toSlack(object $notifiable): SlackMessage
    {
        $organisation = $this->domain
            ->organisation()
            ->withoutGlobalScope('organisation')
            ->first();
        $orgName = (string) ($organisation?->getAttribute('name') ?? 'unknown org');
        $fqdn = (string) $this->domain->getAttribute('fqdn');
        $trigger = $this->scan->getAttribute('trigger')?->value ?? 'unknown';

        return (new SlackMessage)
            ->text(sprintf('Accessibility scan failed on %s — %s', $fqdn, $this->errorMessage))
            ->headerBlock(sprintf('Accessibility scan failed on %s', $fqdn))
            ->contextBlock(function (ContextBlock $block) use ($fqdn, $orgName, $trigger): void {
                $block->text(sprintf('*%s* · %s', $fqdn, $orgName))->markdown();
                $block->text(sprintf('Scan `%s` · trigger: %s', (string) $this->scan->getKey(), $trigger))->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text(sprintf("`%s`\n```%s```", $this->errorClass, $this->errorMessage))->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text(sprintf('<%s|Open scan in admin →>', $this->detailUrl))->markdown();
            })
            ->dividerBlock();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fqdn = (string) $this->domain->getAttribute('fqdn');

        return (new MailMessage)
            ->subject(sprintf('[Tracewatch] Accessibility scan failed on %s', $fqdn))
            ->line(sprintf('An accessibility scan on %s failed with the following error:', $fqdn))
            ->line(sprintf('%s: %s', $this->errorClass, $this->errorMessage))
            ->action('Open scan in admin', $this->detailUrl);
    }
}
