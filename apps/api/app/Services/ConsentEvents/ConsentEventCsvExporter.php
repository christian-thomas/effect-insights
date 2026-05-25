<?php

declare(strict_types=1);

namespace App\Services\ConsentEvents;

use App\Models\ConsentEvent;
use BackedEnum;
use Carbon\CarbonInterface;

/**
 * Owns the wire format of the consent-events CSV export — column
 * ordering, header names, and the per-row mapping from
 * {@see ConsentEvent} to scalars. Lifted out of the controller so the
 * controller stays a receive-delegate-respond shell and the column
 * contract is testable in isolation (Excel/BI integrations rely on
 * stable names + order).
 *
 * JSON columns (categories, gcm_signals) collapse to a single column
 * each via JSON encoding so the CSV stays grid-friendly.
 */
final readonly class ConsentEventCsvExporter
{
    /** @var list<string> */
    private const array COLUMNS = [
        'id',
        'occurred_at',
        'domain_id',
        'organisation_id',
        'banner_config_version',
        'anonymous_id',
        'event_type',
        'country_code',
        'categories',
        'gcm_signals',
        'banner_text_hash',
        'user_agent_hash',
        'ip_hash',
        'created_at',
    ];

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return self::COLUMNS;
    }

    /**
     * @return list<string>
     */
    public function mapRow(ConsentEvent $event): array
    {
        return [
            (string) $event->getAttribute('id'),
            $this->iso($event->getAttribute('occurred_at')),
            (string) $event->getAttribute('domain_id'),
            (string) $event->getAttribute('organisation_id'),
            (string) (int) $event->getAttribute('banner_config_version'),
            (string) $event->getAttribute('anonymous_id'),
            $this->scalar($event->getAttribute('event_type')),
            (string) ($event->getAttribute('country_code') ?? ''),
            $this->jsonOrEmpty($event->getAttribute('categories')),
            $this->jsonOrEmpty($event->getAttribute('gcm_signals')),
            (string) $event->getAttribute('banner_text_hash'),
            (string) $event->getAttribute('user_agent_hash'),
            (string) $event->getAttribute('ip_hash'),
            $this->iso($event->getAttribute('created_at')),
        ];
    }

    private function iso(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return $value === null ? '' : (string) $value;
    }

    private function scalar(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? '' : (string) $value;
    }

    private function jsonOrEmpty(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }
}
