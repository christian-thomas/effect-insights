<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityRuleOverrides;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessibilityRuleOverrideResource;
use App\Models\AccessibilityRuleOverride;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListAccessibilityRuleOverrides extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        $overrides = AccessibilityRuleOverride::query()
            ->orderBy('rule_id')
            ->orderBy('domain_id')
            ->get();

        return AccessibilityRuleOverrideResource::collection($overrides);
    }
}
