<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ConsentEvents\PurgeExpiredConsentEventsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Deletes consent events past their tenant's configured retention period.
 *
 * Idempotent — re-running with the same data is a no-op once the rows
 * are gone. Scheduled daily by routes/console.php (02:00, offset from
 * the archive job at 03:00 so the two never race for the same rows).
 */
#[Signature('consent-events:purge')]
#[Description('Delete consent events past each organisation\'s consent_retention_days window.')]
class PurgeConsentEvents extends Command
{
    public function handle(PurgeExpiredConsentEventsService $service): int
    {
        $results = $service->purge();

        if ($results === []) {
            $this->info('No organisations have a retention window configured. Nothing to purge.');

            return SymfonyCommand::SUCCESS;
        }

        $totalDeleted = 0;
        foreach ($results as $result) {
            $totalDeleted += $result['deleted'];
            $this->line(sprintf(
                'org=%s deleted=%d threshold=%s',
                $result['organisationId'],
                $result['deleted'],
                $result['threshold'],
            ));
        }

        $this->info(sprintf(
            'Purged %d consent event(s) across %d organisation(s).',
            $totalDeleted,
            count($results),
        ));

        return SymfonyCommand::SUCCESS;
    }
}
