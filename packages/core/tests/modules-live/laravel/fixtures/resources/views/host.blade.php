<!doctype html>
<html lang="en">
<head><meta charset="utf-8" /><title>FluxFiles Laravel host (live modules)</title></head>
<body style="font-family: sans-serif; margin: 0; padding: 8px">
  <div data-testid="ready-flag">WAIT</div>
  {{-- The real adapter component: FluxFilesManager mints the JWT server-side from
       :overrides (the adapter's own named-claim forwarding, not the raw `claims`
       escape hatch), the SDK comes from /fluxfiles.js and the UI from
       /public/index.html — all served by this Laravel app. --}}
  <div style="height: 760px">
    <x-fluxfiles disk="local" mode="browser" height="100%" :overrides="$overrides" />
  </div>
  <script>
    window.addEventListener('message', (e) => {
      if (e && e.data && e.data.type === 'FM_READY') document.querySelector('[data-testid=ready-flag]').textContent = 'READY';
    });
  </script>
</body>
</html>
