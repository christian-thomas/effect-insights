<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ConsentArchive\ArchiveConsentEventsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

#[Signature('consent-events:archive {--days=90 : Drain rows older than this many days} {--batch=500 : D1 read batch size}')]
#[Description('Sync rows older than the retention window from Cloudflare D1 into the MySQL consent_events archive, then delete them from D1.')]
class ArchiveConsentEvents extends Command
{
    public function handle(ArchiveConsentEventsService $service): int
    {
        $days = (int) $this->option('days');
        $batch = (int) $this->option('batch');

        $result = $service->archive($days, $batch);

        $this->info(sprintf(
            'Archive complete. cutoff=%s upserted=%d deleted=%d skipped=%d',
            $result->cutoff,
            $result->upserted,
            $result->deleted,
            $result->skipped,
        ));

        return SymfonyCommand::SUCCESS;
    }
}
