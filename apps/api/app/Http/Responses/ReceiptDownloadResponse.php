<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a consent-receipt payload as either signed JSON (the
 * regulator-verifiable artefact) or a human-readable PDF rendered
 * through dompdf. Both ExportEventReceipt and ExportVisitorReceipt
 * delegate to this — previously they each inlined the same fork.
 *
 * The `mode` (`event` | `visitor`) is purely a hint to the PDF
 * template — the JSON branch ignores it.
 */
final class ReceiptDownloadResponse
{
    /**
     * @param  array<string, mixed>  $receipt  Builder output — same shape for both modes.
     */
    public static function for(
        array $receipt,
        ReceiptFormat $format,
        string $filenameBase,
        string $mode,
    ): Response {
        return match ($format) {
            ReceiptFormat::Pdf => self::pdf($receipt, $filenameBase, $mode),
            ReceiptFormat::Json => self::json($receipt, $filenameBase),
        };
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private static function pdf(array $receipt, string $filenameBase, string $mode): Response
    {
        return Pdf::loadView('consent-receipts.pdf', [
            'receipt' => $receipt,
            'mode' => $mode,
        ])->download(sprintf('%s.pdf', $filenameBase));
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private static function json(array $receipt, string $filenameBase): Response
    {
        return response()->json($receipt, Response::HTTP_OK, [
            'Content-Disposition' => sprintf('attachment; filename="%s.json"', $filenameBase),
        ]);
    }
}
