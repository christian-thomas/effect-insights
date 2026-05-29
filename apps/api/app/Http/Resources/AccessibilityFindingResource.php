<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccessibilityFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccessibilityFinding
 */
class AccessibilityFindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'accessibilityScanId' => $this->getAttribute('accessibility_scan_id'),
            'ruleId' => $this->getAttribute('rule_id'),
            'impact' => $this->getAttribute('impact'),
            'description' => $this->getAttribute('description'),
            'helpText' => $this->getAttribute('help_text'),
            'helpUrl' => $this->getAttribute('help_url'),
            'url' => $this->getAttribute('url'),
            'tags' => $this->getAttribute('tags'),
            'nodes' => $this->getAttribute('nodes'),
            // Null when the scanner couldn't resolve the element or the
            // model column was never set. Admin renders an <img> against
            // this URL — bytes stream from the a11y_screenshots disk via
            // ShowFindingScreenshot, which inherits the tenant scope.
            'screenshotUrl' => $this->getAttribute('screenshot_key') !== null
                ? route('accessibility-findings.screenshot', $this->getKey())
                : null,
            // Computed at request time by ShowAccessibilityScan via
            // AccessibilityRuleMuteResolver. False when the controller
            // hasn't attached the flag so the field is always present.
            'muted' => (bool) $this->getAttribute('muted'),
        ];
    }
}
