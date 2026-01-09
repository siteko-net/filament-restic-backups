@php
    $payload = $record['raw'] ?? $record ?? [];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
@endphp

<pre class="rb-pre">{{ $json }}</pre>
