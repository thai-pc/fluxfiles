<script setup lang="ts">
import { ref } from 'vue';
import { FluxFiles, FluxFilesModal } from '@fluxfiles/vue';

const params = new URLSearchParams(location.search);
const TOKEN = params.get('token') || '';
// Overridable via ?endpoint= so the Playwright harness can point at the booted core port.
const ENDPOINT = params.get('endpoint') || 'http://localhost:8088';
// ?ui=modal renders the FluxFilesModal wrapper (for chrome/theme checks).
const UI = params.get('ui') || 'embed';
// ?theme=dark|light|auto — forwarded to the modal so its chrome can be checked.
const THEME = (params.get('theme') as 'dark' | 'light' | 'auto' | null) || undefined;
const picked = ref<any>(null);
const isReady = ref(false);
</script>

<template>
  <div style="font-family: sans-serif; padding: 12px">
    <h2>Vue Host</h2>
    <div data-testid="ready-flag">{{ isReady ? 'READY' : 'WAIT' }}</div>

    <FluxFilesModal
      v-if="UI === 'modal'"
      :open="true"
      :endpoint="ENDPOINT"
      :token="TOKEN"
      disk="local"
      mode="browser"
      :theme="THEME"
      @ready="isReady = true"
      @close="() => {}"
    />

    <template v-else>
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
    </template>
  </div>
</template>
