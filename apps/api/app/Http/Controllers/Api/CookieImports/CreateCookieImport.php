<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieImports;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCookieImportRequest;
use App\Services\Cookies\OpenCookieDatabaseImporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateCookieImport extends Controller
{
    public function __construct(private readonly OpenCookieDatabaseImporter $importer) {}

    public function __invoke(CreateCookieImportRequest $request): JsonResponse
    {
        $result = $this->importer->import($request->csv(), $request->sourceVersion());

        return new JsonResponse([
            'data' => [
                'sourceVersion' => $request->sourceVersion(),
                'created' => $result->created,
                'existing' => $result->existing,
                'skipped' => $result->skipped,
            ],
        ], Response::HTTP_CREATED);
    }
}
