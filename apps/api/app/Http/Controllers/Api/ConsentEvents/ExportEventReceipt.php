<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ConsentEvents;

use App\Http\Controllers\Controller;
use App\Models\ConsentEvent;
use App\Models\User;
use App\Services\ConsentEvents\ConsentReceiptBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExportEventReceipt extends Controller
{
    public function __construct(
        private readonly ConsentReceiptBuilder $builder,
    ) {}

    public function __invoke(ConsentEvent $event, Request $request): Response
    {
        $format = (string) $request->input('format', 'json');

        if (! in_array($format, ['json', 'pdf'], true)) {
            return response()->json(
                ['message' => 'format must be json or pdf'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var User|null $user */
        $user = $request->user();
        $receipt = $this->builder->forEvent($event, $user);
        $filename = sprintf('consent-receipt-%s', $event->getKey());

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('consent-receipts.pdf', [
                'receipt' => $receipt,
                'mode' => 'event',
            ]);

            return $pdf->download(sprintf('%s.pdf', $filename));
        }

        return response()->json($receipt, Response::HTTP_OK, [
            'Content-Disposition' => sprintf('attachment; filename="%s.json"', $filename),
        ]);
    }
}
