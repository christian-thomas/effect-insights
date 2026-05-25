<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ConsentEvents;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReceiptDownloadResponse;
use App\Http\Responses\ReceiptFormat;
use App\Models\ConsentEvent;
use App\Models\User;
use App\Services\ConsentEvents\ConsentReceiptBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExportEventReceipt extends Controller
{
    public function __construct(
        private readonly ConsentReceiptBuilder $builder,
    ) {}

    public function __invoke(ConsentEvent $event, Request $request): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        return ReceiptDownloadResponse::for(
            receipt: $this->builder->forEvent($event, $user),
            format: ReceiptFormat::fromRequestValue($request->string('format')->toString() ?: null),
            filenameBase: sprintf('consent-receipt-%s', $event->getKey()),
            mode: 'event',
        );
    }
}
