<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Domain;
use App\Models\Scan;
use App\Notifications\Concerns\RoutesViaConfiguredChannels;
use App\Services\Notifications\UnknownCookieAlertService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Fires when a completed scan turns up cookies the resolver couldn't
 * categorise. Goes to the Slack channel configured in
 * services.slack.notifications.channel. Routing is handled by
 * {@see UnknownCookieAlertService}.
 */
final class UnknownCookieFound extends Notification
{
    use RoutesViaConfiguredChannels;

    /**
     * @param  array<int, string>  $unknownCookieNames
     */
    public function __construct(
        public readonly Scan $scan,
        public readonly Domain $domain,
        public readonly array $unknownCookieNames,
        public readonly string $reviewUrl,
    ) {}

    public function toSlack(object $notifiable): SlackMessage
    {
        $count = count($this->unknownCookieNames);
        $organisation = $this->domain
            ->organisation()
            ->withoutGlobalScope('organisation')
            ->first();
        $orgName = (string) ($organisation?->getAttribute('name') ?? 'unknown org');
        $fqdn = (string) $this->domain->getAttribute('fqdn');

        $listing = $this->formatCookieList();

        return (new SlackMessage)
            ->text(sprintf('%d unknown cookie%s found on %s', $count, $count === 1 ? '' : 's', $fqdn))
            ->headerBlock(sprintf(
                '%d unknown cookie%s found',
                $count,
                $count === 1 ? '' : 's',
            ))
            ->contextBlock(function (ContextBlock $block) use ($fqdn, $orgName): void {
                $block->text(sprintf('*%s* · %s', $fqdn, $orgName))->markdown();
                $block->text(sprintf('Scan `%s`', (string) $this->scan->getKey()))->markdown();
            })
            ->sectionBlock(function (SectionBlock $block) use ($listing): void {
                $block->text($listing)->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text(sprintf('<%s|Open the review queue →>', $this->reviewUrl))->markdown();
            })
            ->dividerBlock();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->unknownCookieNames);
        $fqdn = (string) $this->domain->getAttribute('fqdn');

        $message = (new MailMessage)
            ->subject(sprintf('[Effect Insight] %d unknown cookie%s on %s', $count, $count === 1 ? '' : 's', $fqdn))
            ->line(sprintf(
                'A completed scan on %s found %d cookie%s that the resolver couldn\'t categorise.',
                $fqdn,
                $count,
                $count === 1 ? '' : 's',
            ));

        foreach (array_slice($this->unknownCookieNames, 0, 10) as $name) {
            $message->line(sprintf('• %s', $name));
        }
        $rest = max(0, count($this->unknownCookieNames) - 10);
        if ($rest > 0) {
            $message->line(sprintf('…and %d more.', $rest));
        }

        return $message->action('Open the review queue', $this->reviewUrl);
    }

    private function formatCookieList(): string
    {
        $top = array_slice($this->unknownCookieNames, 0, 10);
        $rest = max(0, count($this->unknownCookieNames) - count($top));
        $lines = array_map(static fn (string $name): string => sprintf('• `%s`', $name), $top);
        if ($rest > 0) {
            $lines[] = sprintf('• _…and %d more_', $rest);
        }

        return implode("\n", $lines);
    }
}
