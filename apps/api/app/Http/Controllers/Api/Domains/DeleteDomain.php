<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\Response;

class DeleteDomain extends Controller
{
    public function __invoke(Domain $domain): Response
    {
        $domain->delete();

        return response()->noContent();
    }
}
