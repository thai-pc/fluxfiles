@php
    $manager = app(\FluxFiles\Laravel\FluxFilesManager::class);
    // tokenForUser() = the per-user claims resolver (config `claims_resolver`)
    // with this tag's :overrides merged on top.
    $token = $manager->tokenForUser($overrides);
    // The default refresh hook re-mints from a ticket bound to this user + these
    // overrides, so an expired token comes back with the SAME scope — never the
    // broader config defaults. Sent as a header, never in the URL.
    $refreshTicket = $onTokenRefresh ? null : $manager->refreshTicketForUser($overrides);
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
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-FluxFiles-Refresh-Ticket': @json($refreshTicket),
                },
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) { return (j && j.data && j.data.token) ? j.data.token : null; })
                .catch(function () { return null; });
        },
        @endif
    });
})();
</script>
