<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which axe-core findings contribute to the alert threshold count.
 * Sibling of {@see A11yImpact} — both control the alert-decision
 * surface but on different axes.
 */
enum A11yAlertScope: string
{
    /**
     * Every finding above the impact threshold counts. Default —
     * preserves the pre-feature behaviour for any org that hasn't
     * explicitly opted in to filtering.
     */
    case All = 'all';

    /**
     * Only WCAG-conformance findings count. Best-practice findings
     * (axe tag 'best-practice' with no `wcag*` tag) are dropped
     * before counting, so a scan that finds only best-practice
     * issues at-or-above the threshold stays silent.
     */
    case WcagOnly = 'wcag_only';
}
