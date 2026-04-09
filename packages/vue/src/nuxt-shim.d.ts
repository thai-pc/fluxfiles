declare module '#app' {
  import type { App } from 'vue';

  interface NuxtApp {
    vueApp: App;
  }

  export function defineNuxtPlugin(
    plugin: (nuxtApp: NuxtApp) => void
  ): (nuxtApp: NuxtApp) => void;
}
