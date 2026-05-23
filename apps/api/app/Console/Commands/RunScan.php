<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Services\Scanner\ScanRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

#[Signature('scans:run {domain : Domain UUID} {--url=* : One or more URLs to crawl (repeatable)}')]
#[Description('Run a scan against the given domain. URLs default to the apex if none provided.')]
class RunScan extends Command
{
    public function handle(ScanRunner $runner): int
    {
        $domainId = (string) $this->argument('domain');
        $domain = Domain::query()
            ->withoutGlobalScope('organisation')
            ->find($domainId);

        if ($domain === null) {
            $this->error(sprintf('Domain %s not found', $domainId));

            return SymfonyCommand::FAILURE;
        }

        /** @var array<int, string> $urls */
        $urls = (array) $this->option('url');
        if ($urls === []) {
            $urls = [sprintf('https://%s/', $domain->getAttribute('fqdn'))];
        }

        $scan = $runner->run($domain, $urls, ScanTrigger::Manual);

        $this->info(sprintf(
            'Scan %s finished in status %s (%d findings).',
            $scan->getKey(),
            $scan->getAttribute('status')->value,
            $scan->findings()->count(),
        ));

        return SymfonyCommand::SUCCESS;
    }
}
