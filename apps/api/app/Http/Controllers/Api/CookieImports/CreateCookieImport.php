<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieImports;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCookieImportRequest;
use App\Services\Cookies\InvalidCookieImportFormat;
use App\Services\Cookies\OpenCookieDatabaseImporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateCookieImport extends Controller
{
    public function __construct(private readonly OpenCookieDatabaseImporter $importer) {}

    public function __invoke(CreateCookieImportRequest $request): JsonResponse
    {
        try {
            $result = $this->importer->import($request->csv(), $request->sourceVersion());
        } catch (InvalidCookieImportFormat $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], InvalidCookieImportFormat::STATUS);
        }

        if ($result->created === 0 && $result->existing === 0 && $result->skipped > 0) {
            // Every row was skipped — likely an OCD release with category
            // labels we don't understand, or pathological data. Tell the
            // operator clearly rather than implying success.
            return response()->json([
                'message' => sprintf(
                    'No rows could be imported. %d row(s) were skipped — usually because the Category column uses values outside the recognised set (Functional, Analytics, Performance, Marketing, Advertising, Preferences, Personalization, Strictly Necessary).',
                    $result->skipped,
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => [
                'sourceVersion' => $request->sourceVersion(),
                'created' => $result->created,
                'existing' => $result->existing,
                'skipped' => $result->skipped,
            ],
        ], Response::HTTP_CREATED);
    }
}
