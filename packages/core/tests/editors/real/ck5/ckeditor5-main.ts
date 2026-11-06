import {
  ClassicEditor, Essentials, Paragraph, Link, Image, ImageInsert, ImageUpload, Base64UploadAdapter, ImageTextAlternative,
} from 'ckeditor5';
import 'ckeditor5/ckeditor5.css';
import { FluxFiles } from '@fluxfiles/ckeditor5';

// NOTE: deliberately no <script src="/fluxfiles.js"> on this page, so the adapter
// falls back to the SDK it bundles (`import 'fluxfiles'`): the npm-consumer path.
const params = new URLSearchParams(location.search);
const token = params.get('token') || '';
const endpoint = params.get('endpoint') || '';
// ?imageInsert=1 loads the ImageInsert UI so the "Insert image via FluxFiles" entry exists.
const withImageInsert = params.get('imageInsert') === '1';
// ?listIntegration=1 adds 'fluxfiles' to image.insert.integrations (see the spec: without it the entry never renders).
const listIntegration = params.get('listIntegration') === '1';

ClassicEditor.create(document.getElementById('ed') as HTMLElement, {
  // CKEditor 5's documented open-source mode (since v44). No commercial key.
  licenseKey: 'GPL',
  plugins: [
    Essentials, Paragraph, Link, Image, ImageTextAlternative, FluxFiles,
    ...(withImageInsert ? [ImageInsert, ImageUpload, Base64UploadAdapter] : []),
  ],
  toolbar: withImageInsert ? ['fluxfiles', 'insertImage'] : ['fluxfiles'],
  fluxfiles: { endpoint, token },
  ...(listIntegration ? { image: { insert: { integrations: ['upload', 'url', 'fluxfiles'] } } } : {}),
} as never).then((editor: any) => {
  (window as any).__editor = editor;
  (window as any).__edReady = true;
});
