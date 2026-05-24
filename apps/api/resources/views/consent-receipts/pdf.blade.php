@php
    /** @var array<string, mixed> $receipt */
    /** @var string $mode */
    $events = $mode === 'event' ? [$receipt['event']] : $receipt['events'];
    $bannerConfigs = $mode === 'event'
        ? [(string) ($receipt['event']['bannerConfigVersion'] ?? '') => $receipt['bannerConfig']]
        : $receipt['bannerConfigs'];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Consent Receipt</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #1a1a1a; }
        h1 { font-size: 18pt; margin: 0 0 4pt 0; }
        h2 { font-size: 13pt; margin: 18pt 0 6pt 0; padding-bottom: 3pt; border-bottom: 1pt solid #ccc; }
        h3 { font-size: 11pt; margin: 12pt 0 4pt 0; }
        p { margin: 0 0 4pt 0; }
        table { width: 100%; border-collapse: collapse; margin: 4pt 0; }
        th, td { border: 1pt solid #ddd; padding: 4pt 6pt; text-align: left; vertical-align: top; }
        th { background: #f4f4f4; font-weight: bold; width: 30%; }
        .muted { color: #666; font-size: 9pt; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 9pt; word-break: break-all; }
        .pill { display: inline-block; padding: 1pt 6pt; border-radius: 8pt; font-size: 8pt; font-weight: bold; }
        .pill-granted { background: #e6f4ea; color: #1a7f37; }
        .pill-denied { background: #fdecea; color: #b3261e; }
        .footer { margin-top: 24pt; padding-top: 8pt; border-top: 1pt solid #ccc; font-size: 9pt; color: #555; }
    </style>
</head>
<body>
    <h1>Consent Receipt</h1>
    <p class="muted">Format version {{ $receipt['formatVersion'] }} · Generated {{ $receipt['generatedAt'] }}</p>

    <h2>Organisation &amp; domain</h2>
    <table>
        <tr><th>Organisation</th><td>{{ $receipt['organisation']['name'] ?? '—' }} <span class="mono">({{ $receipt['organisation']['id'] ?? '' }})</span></td></tr>
        <tr><th>Domain</th><td>{{ $receipt['domain']['fqdn'] ?? '' }} <span class="mono">({{ $receipt['domain']['publicKey'] ?? '' }})</span></td></tr>
        @if ($mode === 'visitor')
            <tr><th>Anonymous visitor</th><td class="mono">{{ $receipt['anonymousId'] }}</td></tr>
            <tr><th>Event count</th><td>{{ $receipt['eventCount'] }}</td></tr>
        @endif
    </table>

    <h2>{{ $mode === 'event' ? 'Consent event' : 'Consent events' }}</h2>
    @foreach ($events as $event)
        <h3>Event {{ $loop->iteration }} — {{ $event['eventType'] }} @ {{ $event['occurredAt'] }}</h3>
        <table>
            <tr><th>Event ID</th><td class="mono">{{ $event['id'] }}</td></tr>
            <tr><th>Anonymous ID</th><td class="mono">{{ $event['anonymousId'] }}</td></tr>
            <tr><th>Event type</th><td>{{ $event['eventType'] }}</td></tr>
            <tr><th>Occurred at</th><td>{{ $event['occurredAt'] }}</td></tr>
            <tr><th>Country</th><td>{{ $event['countryCode'] ?? '—' }}</td></tr>
            <tr><th>Banner config version</th><td>{{ $event['bannerConfigVersion'] }} (text hash <span class="mono">{{ $event['bannerTextHash'] }}</span>)</td></tr>
            <tr><th>UA hash</th><td class="mono">{{ $event['userAgentHash'] }}</td></tr>
            <tr><th>IP hash</th><td class="mono">{{ $event['ipHash'] }}</td></tr>
            <tr><th>Categories</th><td>
                @foreach (($event['categories'] ?? []) as $category => $granted)
                    <span class="pill {{ $granted ? 'pill-granted' : 'pill-denied' }}">{{ $category }}: {{ $granted ? 'granted' : 'denied' }}</span>
                @endforeach
            </td></tr>
            <tr><th>GCM signals</th><td>
                @foreach (($event['gcmSignals'] ?? []) as $signal => $state)
                    <span class="pill {{ $state === 'granted' ? 'pill-granted' : 'pill-denied' }}">{{ $signal }}: {{ $state }}</span>
                @endforeach
            </td></tr>
        </table>
    @endforeach

    <h2>Banner configuration snapshot</h2>
    @foreach ($bannerConfigs as $version => $bannerConfig)
        <h3>Version {{ $bannerConfig['version'] ?? $version }} <span class="muted">({{ $bannerConfig['snapshotNote'] ?? 'unavailable' }})</span></h3>
        @if (($bannerConfig['snapshotNote'] ?? null) === 'unavailable')
            <p class="muted">The historical banner configuration for this version could not be located.</p>
        @else
            <table>
                <tr><th>Layout</th><td>{{ $bannerConfig['layout'] ?? '—' }}</td></tr>
                <tr><th>Default locale</th><td>{{ $bannerConfig['localeDefault'] ?? '—' }}</td></tr>
                <tr><th>Locales enabled</th><td>{{ implode(', ', $bannerConfig['localesEnabled'] ?? []) }}</td></tr>
                <tr><th>Material version</th><td>{{ $bannerConfig['materialVersion'] ?? '—' }}</td></tr>
                <tr><th>Published at</th><td>{{ $bannerConfig['publishedAt'] ?? '—' }}</td></tr>
                <tr><th>GCM defaults</th><td>
                    @foreach (($bannerConfig['gcmDefaults'] ?? []) as $signal => $state)
                        <span class="pill {{ $state === 'granted' ? 'pill-granted' : 'pill-denied' }}">{{ $signal }}: {{ $state }}</span>
                    @endforeach
                </td></tr>
                <tr><th>Geo rules</th><td class="mono">{{ json_encode($bannerConfig['geoRules'] ?? [], JSON_UNESCAPED_SLASHES) }}</td></tr>
                <tr><th>Behaviour</th><td class="mono">{{ json_encode($bannerConfig['behaviour'] ?? [], JSON_UNESCAPED_SLASHES) }}</td></tr>
            </table>
        @endif
    @endforeach

    <div class="footer">
        <p><strong>Signature algorithm:</strong> {{ $receipt['signatureAlgorithm'] }}</p>
        <p class="mono"><strong>Signature:</strong> {{ $receipt['signature'] }}</p>
        <p>Verify by re-computing HMAC-SHA-256 over the canonical-JSON of this receipt (keys sorted recursively, list order preserved, signature + signatureAlgorithm fields stripped) using the operator's CONSENT_RECEIPT_SIGNING_KEY.</p>
    </div>
</body>
</html>
