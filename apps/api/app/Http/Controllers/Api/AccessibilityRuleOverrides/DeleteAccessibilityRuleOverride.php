<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityRuleOverrides;

use App\Http\Controllers\Controller;
use App\Models\AccessibilityRuleOverride;
use Illuminate\Http\Response;

class DeleteAccessibilityRuleOverride extends Controller
{
    public function __invoke(AccessibilityRuleOverride $accessibilityRuleOverride): Response
    {
        $accessibilityRuleOverride->delete();

        return response()->noContent();
    }
}
