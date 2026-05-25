<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ConsentEvents;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsentEvents\ExportVisitorReceiptRequest;
use App\Http\Responses\ReceiptDownloadResponse;
use App\Http\Responses\ReceiptFormat;
use App\Models\Domain;
use App\Models\User;
use App\Services\ConsentEvents\ConsentReceiptBuilder;
use Symfony\Component\HttpFoundation\Response;

class ExportVisitorReceipt extends Controller
{
    public function __construct(
        private readonly ConsentReceiptBuilder $builder,
    ) {}

    public function __invoke(ExportVisitorReceiptRequest $request): Response
    {
        $anonymousId = (string) $request->validated('anonymous_id');
        $domain = Domain::query()->whereKey($request->validated('domain_id'))->firstOrFail();
        /** @var User|null $user */
        $user = $request->user();

        return ReceiptDownloadResponse::for(
            receipt: $this->builder->forVisitorByDomain($anonymousId, $domain, $user),
            format: ReceiptFormat::fromRequestValue($request->desiredFormat()),
            filenameBase: sprintf('consent-receipt-visitor-%s', $anonymousId),
            mode: 'visitor',
        );
    }
}
