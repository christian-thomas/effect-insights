<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvited extends Notification
{
    use Queueable;

    public function __construct(public UserInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        $organisationName = $this->invitation->organisation->getAttribute('name');
        $acceptUrl = sprintf(
            '%s/invitations/%s',
            (string) config('app.frontend_url', config('app.url')),
            $this->invitation->getAttribute('token'),
        );

        $invitedBy = $this->invitation->invitedBy?->getAttribute('name') ?? 'A superuser';

        return (new MailMessage)
            ->subject(sprintf("You're invited to %s on Effect Insight", $organisationName))
            ->greeting('Hello,')
            ->line(sprintf('%s has invited you to join %s on Effect Insight.', $invitedBy, $organisationName))
            ->action('Accept invitation', $acceptUrl)
            ->line(sprintf(
                'This invitation expires on %s. After that you\'ll need a fresh invite from your superuser.',
                $this->invitation->getAttribute('expires_at')->toFormattedDateString(),
            ))
            ->line("If you weren't expecting this email you can ignore it — no account will be created until you click the link and complete sign-up.");
    }
}
