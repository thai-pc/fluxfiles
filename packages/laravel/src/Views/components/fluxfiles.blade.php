@php
    $manager = app(\FluxFiles\Laravel\FluxFilesManager::class);
    $token = $manager->tokenForUser($overrides);
    $endpoint = $manager->endpoint();
    $containerId = 'fluxfiles-' . uniqid();
@endphp

<div id="{{ $containerId }}" style="width:{{ $width }};height:{{ $height }}"></div>

<script src="{{ $endpoint }}/fluxfiles.js"></script>
<script>
(function() {
    FluxFiles.open({
        endpoint: @json($endpoint),
        token: @json($token),
        disk: @json($disk),
        mode: @json($mode),
        multiple: @json($multiple),
        container: "#{{ $containerId }}",
        @if($onSelect)
        onSelect: {!! $onSelect !!},
        @endif
        @if($onClose)
        onClose: {!! $onClose !!},
        @endif
        @if($onTokenRefresh)
        onTokenRefresh: {!! $onTokenRefresh !!},
        @else
        // Default: re-mint a JWT from the Laravel session when the embedded one
        // expires, so the iframe's "Try again" recovers without a page reload.
        // Returns null on failure (e.g. the session is also gone) → the UI then
        // shows the auth screen and a reload sends the user through login.
        onTokenRefresh: function () {
            return fetch(@json($tokenUrl), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) { return (j && j.data && j.data.token) ? j.data.token : null; })
                .catch(function () { return null; });
        },
        @endif
    });
})();
</script>
