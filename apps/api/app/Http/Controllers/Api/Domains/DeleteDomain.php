<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Cloudflare\CloudflareKvClientContract;
use Illuminate\Http\Response;

class DeleteDomain extends Controller
{
    public function __construct(
        private readonly CloudflareKvClientContract $kv,
    ) {}

    public function __invoke(Domain $domain): Response
    {
        // Take the KV record offline first — once the row is gone there's
        // no way to map back to the public_key, so KV would leak forever.
        $this->kv->deleteValue($domain->getAttribute('public_key'));

        $domain->delete();

        return response()->noContent();
    }
}
