<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>FluxFiles Laravel Host</title>
</head>
<body style="font-family: sans-serif; padding: 12px">
  <h2>Laravel Host (proxy mode)</h2>
  <div data-testid="ready-flag">WAIT</div>
  <pre data-testid="picked" style="background:#eee;padding:8px">(no selection yet)</pre>

  {{-- The real adapter component: mints a token via FluxFilesManager, loads the
       SDK from the proxy (/fluxfiles.js), and embeds /public/index.html. --}}
  <div style="height:500px;margin-top:12px;border:1px solid #ccc">
    <x-fluxfiles
      disk="local"
      mode="browser"
      height="100%"
      :onSelect="'(f) => { document.querySelector(\'[data-testid=picked]\').textContent = JSON.stringify(f); }'"
    />
  </div>

  <script>
    // The SDK forwards iframe events as window messages; flip READY on FM_READY.
    window.addEventListener('message', (e) => {
      if (e && e.data && e.data.type === 'FM_READY') {
        document.querySelector('[data-testid=ready-flag]').textContent = 'READY';
      }
    });
  </script>
</body>
</html>
