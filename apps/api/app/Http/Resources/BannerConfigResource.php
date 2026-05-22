<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BannerConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BannerConfig
 */
class BannerConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'domainId' => $this->getAttribute('domain_id'),
            'version' => $this->getAttribute('version'),
            'isDraft' => $this->getAttribute('is_draft'),
            'isPublished' => $this->getAttribute('is_published'),
            'layout' => $this->getAttribute('layout'),
            'localeDefault' => $this->getAttribute('locale_default'),
            'localesEnabled' => $this->getAttribute('locales_enabled'),
            'colors' => $this->getAttribute('colors'),
            'copy' => $this->getAttribute('copy'),
            'gcmDefaults' => $this->getAttribute('gcm_defaults'),
            'geoRules' => $this->getAttribute('geo_rules'),
            'behaviour' => $this->getAttribute('behaviour'),
            'publishedAt' => $this->getAttribute('published_at')?->toIso8601String(),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
            'updatedAt' => $this->getAttribute('updated_at')?->toIso8601String(),
        ];
    }
}
