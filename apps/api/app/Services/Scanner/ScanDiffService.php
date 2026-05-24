<?php

declare(strict_types=1);

namespace App\Services\Scanner;

use App\Enums\FindingType;
use App\Enums\ScanStatus;
use App\Models\Scan;

/**
 * Computes a cookie-policy diff between a completed scan and the most
 * recent prior completed scan on the same domain. Surfaces:
 *
 *  - `new`              — cookies present in the current scan that weren't
 *                          in the previous one (vendor drift, fresh ad
 *                          partner, etc.)
 *  - `removed`          — cookies in the previous scan that didn't appear
 *                          in the current one
 *  - `categoryChanged`  — cookies present in both but with a different
 *                          category resolution (typically caused by a new
 *                          override or canonical pattern landing)
 *
 * Per-finding dedup uses the cookie `name` only. Two scans of the same
 * domain may observe the same cookie on multiple URLs; we collapse to
 * one row per name with the first-seen URL preserved.
 */
final class ScanDiffService
{
    /**
     * @return array{
     *   previousScanId: string,
     *   previousFinishedAt: string|null,
     *   new: array<int, array{name: string, category: string|null, firstSeenUrl: string|null}>,
     *   removed: array<int, array{name: string, category: string|null, firstSeenUrl: string|null}>,
     *   categoryChanged: array<int, array{name: string, previousCategory: string|null, currentCategory: string|null}>,
     * }|null
     */
    public function compare(Scan $current): ?array
    {
        if ($current->getAttribute('status') !== ScanStatus::Completed) {
            return null;
        }

        $previous = Scan::query()
            ->where('domain_id', $current->getAttribute('domain_id'))
            ->where('id', '!=', $current->getKey())
            ->where('status', ScanStatus::Completed->value)
            ->where('finished_at', '<=', $current->getAttribute('finished_at'))
            ->latest('finished_at')
            ->first();

        if ($previous === null) {
            return null;
        }

        $currentByName = $this->collectCookieFindings($current);
        $previousByName = $this->collectCookieFindings($previous);

        $new = [];
        foreach ($currentByName as $name => $finding) {
            if (! isset($previousByName[$name])) {
                $new[] = $finding;
            }
        }

        $removed = [];
        foreach ($previousByName as $name => $finding) {
            if (! isset($currentByName[$name])) {
                $removed[] = $finding;
            }
        }

        $categoryChanged = [];
        foreach ($currentByName as $name => $finding) {
            if (! isset($previousByName[$name])) {
                continue;
            }
            $previousCategory = $previousByName[$name]['category'] ?? null;
            $currentCategory = $finding['category'] ?? null;
            if ($previousCategory === $currentCategory) {
                continue;
            }
            $categoryChanged[] = [
                'name' => $name,
                'previousCategory' => $previousCategory,
                'currentCategory' => $currentCategory,
            ];
        }

        return [
            'previousScanId' => (string) $previous->getKey(),
            'previousFinishedAt' => $previous->getAttribute('finished_at')?->toIso8601String(),
            'new' => $new,
            'removed' => $removed,
            'categoryChanged' => $categoryChanged,
        ];
    }

    /**
     * @return array<string, array{name: string, category: string|null, firstSeenUrl: string|null}>
     */
    private function collectCookieFindings(Scan $scan): array
    {
        $byName = [];
        $findings = $scan->findings()
            ->where('type', FindingType::Cookie->value)
            ->orderBy('name')->oldest()
            ->get();

        foreach ($findings as $finding) {
            $name = (string) $finding->getAttribute('name');
            if ($name === '') {
                continue;
            }
            if (isset($byName[$name])) {
                continue;
            }
            $category = $finding->getAttribute('category');
            $byName[$name] = [
                'name' => $name,
                'category' => is_object($category) && property_exists($category, 'value')
                    ? $category->value
                    : $category,
                'firstSeenUrl' => $finding->getAttribute('first_seen_url'),
            ];
        }

        return $byName;
    }
}
