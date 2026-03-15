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
    });
})();
</script>
