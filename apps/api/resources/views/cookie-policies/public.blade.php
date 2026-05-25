@php
    /** @var array<string, mixed> $policy */
    $domain = $policy['domain'];
    $categories = (array) $policy['categories'];
@endphp
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <title>Cookies in use — {{ $domain['fqdn'] }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
      :root { color-scheme: light dark; }
      body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        color: #111827; background: #ffffff;
        margin: 0; padding: 1.5rem;
        line-height: 1.5;
      }
      @media (prefers-color-scheme: dark) {
        body { color: #f3f4f6; background: #0b0f1a; }
        table { border-color: #1f2937; }
        thead { background: #111827; }
        tbody tr:nth-child(even) { background: #0f172a; }
        .meta { color: #9ca3af; }
      }
      h1 { font-size: 1.4rem; margin: 0 0 0.25rem; }
      h2 { font-size: 1.1rem; margin: 1.75rem 0 0.25rem; }
      .meta { color: #6b7280; font-size: 0.85rem; }
      .desc { color: #4b5563; font-size: 0.9rem; margin: 0.25rem 0 0.75rem; }
      .empty {
        padding: 1rem; border: 1px dashed #d1d5db; border-radius: 6px;
        color: #6b7280; font-size: 0.95rem;
      }
      table {
        width: 100%; border-collapse: collapse;
        border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;
        font-size: 0.9rem;
      }
      thead { background: #f3f4f6; }
      th, td { padding: 0.5rem 0.6rem; text-align: left; vertical-align: top; }
      th { font-weight: 600; }
      tbody tr:nth-child(even) { background: #f9fafb; }
      code { font-family: "SFMono-Regular", Menlo, monospace; font-size: 0.85rem; }
      .party-third { color: #b45309; font-weight: 600; }
      .party-first { color: #047857; font-weight: 600; }
    </style>
  </head>
  <body>
    <h1>Cookies in use on {{ $domain['fqdn'] }}</h1>
    <p class="meta">Generated automatically from the latest site scan · {{ $policy['generatedAt'] }}</p>

    @if ($categories === [])
      <p class="empty">No cookies have been detected yet. This page will populate after the first scan completes.</p>
    @else
      @foreach ($categories as $bucket)
        <h2>{{ $bucket['label'] }}</h2>
        <p class="desc">{{ $bucket['description'] }}</p>
        <table>
          <thead>
            <tr>
              <th>Cookie name</th>
              <th>Party</th>
              <th>Domain</th>
              <th>Purpose</th>
            </tr>
          </thead>
          <tbody>
            @foreach ((array) $bucket['cookies'] as $cookie)
              @php
                $party = (string) ($cookie['party'] ?? '') ?: 'first';
                $partyClass = $party === 'third' ? 'party-third' : 'party-first';
              @endphp
              <tr>
                <td><code>{{ $cookie['name'] }}</code></td>
                <td class="{{ $partyClass }}">{{ ucfirst($party) }}-party</td>
                <td>{{ $cookie['domainSetOn'] ?? '' }}</td>
                <td>{{ $cookie['purpose'] ?? '' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endforeach
    @endif
  </body>
</html>
