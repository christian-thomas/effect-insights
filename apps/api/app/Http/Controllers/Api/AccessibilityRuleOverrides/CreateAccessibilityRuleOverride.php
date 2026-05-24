<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityRuleOverrides;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAccessibilityRuleOverrideRequest;
use App\Http\Resources\AccessibilityRuleOverrideResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateAccessibilityRuleOverride extends Controller
{
    public function __invoke(CreateAccessibilityRuleOverrideRequest $request): JsonResponse
    {
        return AccessibilityRuleOverrideResource::make($request->persist())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
