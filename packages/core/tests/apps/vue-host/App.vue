<script setup lang="ts">
import { ref } from 'vue';
import { FluxFiles } from '@fluxfiles/vue';

const params = new URLSearchParams(location.search);
const TOKEN = params.get('token') || '';
// Overridable via ?endpoint= so the Playwright harness can point at the booted core port.
const ENDPOINT = params.get('endpoint') || 'http://localhost:8088';
const picked = ref<any>(null);
const isReady = ref(false);
</script>

<template>
  <div style="font-family: sans-serif; padding: 12px">
    <h2>Vue Host</h2>
    <div data-testid="ready-flag">{{ isReady ? 'READY' : 'WAIT' }}</div>
    <pre data-testid="picked" style="background: #eee; padding: 8px">{{ picked ? JSON.stringify(picked, null, 2) : '(no selection yet)' }}</pre>
    <div style="height: 500px; margin-top: 12px; border: 1px solid #ccc">
      <FluxFiles
        :endpoint="ENDPOINT"
        :token="TOKEN"
        disk="local"
        mode="browser"
        height="100%"
        @ready="isReady = true"
        @select="(file) => (picked = file)"
      />
    </div>
  </div>
</template>
