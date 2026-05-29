import React, { useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { FluxFiles } from '@fluxfiles/react';

const TOKEN = new URLSearchParams(location.search).get('token') || '';

function App() {
  const ref = useRef<any>(null);
  const [picked, setPicked] = useState<any>(null);
  const [ready, setReady] = useState(false);

  return (
    <div style={{ fontFamily: 'sans-serif', padding: 12 }}>
      <h2>React Host</h2>
      <div data-testid="ready-flag">{ready ? 'READY' : 'WAIT'}</div>
      <pre data-testid="picked" style={{ background: '#eee', padding: 8 }}>
        {picked ? JSON.stringify(picked, null, 2) : '(no selection yet)'}
      </pre>
      <button data-testid="refresh-btn" onClick={() => ref.current?.refresh()}>Refresh</button>
      <div style={{ height: 500, marginTop: 12, border: '1px solid #ccc' }}>
        <FluxFiles
          ref={ref}
          endpoint="http://localhost:8088"
          token={TOKEN}
          disk="local"
          mode="browser"
          height="100%"
          onReady={() => setReady(true)}
          onSelect={(file) => setPicked(file)}
        />
      </div>
    </div>
  );
}

createRoot(document.getElementById('root')!).render(<App />);
