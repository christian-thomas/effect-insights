<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether the banner exposes per-category toggles ('categorised') or
 * collapses to a simple Accept-all / Reject-all decision ('basic').
 *
 * Basic mode is appropriate when:
 *   - The site has only essential cookies + one analytics category, so a
 *     per-category UI is more friction than the visitor needs.
 *   - Local copy norms favour a single binary decision (e.g. cookie
 *     banners that are styled as a regulatory notice rather than a
 *     consent panel).
 *
 * Categorised remains the default — it gives the visitor more control
 * and matches GDPR Article 7's "as specific as informed consent" bar.
 */
enum BannerGranularity: string
{
    case Basic = 'basic';
    case Categorised = 'categorised';
}
