<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zymox Bundles</title>
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    {{-- App Bridge must be the first script and must not be async/deferred --}}
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    @if($entry)
        @foreach($entry['css'] ?? [] as $css)
            <link rel="stylesheet" href="{{ $assets }}/{{ $css }}">
        @endforeach
    @endif
</head>
<body>
    <div id="root"></div>

    @if($entry)
        <script>
            window.__ZB__ = { apiBase: '/api/shopify-app', basename: '/shopify-app' };
        </script>
        <script type="module" src="{{ $assets }}/{{ $entry['file'] }}"></script>
    @else
        <div style="font-family: system-ui, sans-serif; padding: 2rem; max-width: 640px">
            <h2>Zymox Bundles app is not built yet</h2>
            <p>Run the following, then reload:</p>
            <pre style="background:#f4f4f4;padding:1rem;border-radius:6px">cd shopify-bundle-app/web
npm install
npm run build</pre>
        </div>
    @endif
</body>
</html>
