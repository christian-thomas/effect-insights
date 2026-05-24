<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ConsentEvents;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsentEvents\ExportVisitorReceiptRequest;
use App\Models\ConsentEvent;
use App\Models\Domain;
use App\Models\User;
use App\Services\ConsentEvents\ConsentReceiptBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class ExportVisitorReceipt extends Controller
{
    public function __construct(
        private readonly ConsentReceiptBuilder $builder,
    ) {}

    public function __invoke(ExportVisitorReceiptRequest $request): Response
    {
        $anonymousId = (string) $request->validated('anonymous_id');
        $domainId = (string) $request->validated('domain_id');

        $domain = Domain::query()->where('id', $domainId)->firstOrFail();

        $events = ConsentEvent::query()
            ->where('anonymous_id', $anonymousId)
            ->where('domain_id', $domain->getKey())
            ->oldest('occurred_at')
            ->get();

        if ($events->isEmpty()) {
            return response()->json(
                ['message' => 'no consent events found for the given anonymous_id + domain_id'],
                Response::HTTP_NOT_FOUND,
            );
        }

        /** @var User|null $user */
        $user = $request->user();
        $receipt = $this->builder->forVisitor($anonymousId, $domain, $events, $user);
        $filename = sprintf('consent-receipt-visitor-%s', $anonymousId);

        if ($request->desiredFormat() === 'pdf') {
            $pdf = Pdf::loadView('consent-receipts.pdf', [
                'receipt' => $receipt,
                'mode' => 'visitor',
            ]);

            return $pdf->download(sprintf('%s.pdf', $filename));
        }

        return response()->json($receipt, Response::HTTP_OK, [
            'Content-Disposition' => sprintf('attachment; filename="%s.json"', $filename),
        ]);
    }
}
