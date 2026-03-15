import { defineNuxtPlugin } from '#app';
import FluxFiles from './FluxFiles.vue';
import FluxFilesModal from './FluxFilesModal.vue';

export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.component('FluxFiles', FluxFiles);
  nuxtApp.vueApp.component('FluxFilesModal', FluxFilesModal);
});

export { useFluxFiles } from './useFluxFiles';
