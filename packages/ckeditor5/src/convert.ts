import type { FluxFile } from 'fluxfiles';

/** A selected file normalised into what the editor insert path needs. */
export interface FileInfo {
  url: string;
  name: string;
  isImage: boolean;
  /** `false` for a preview-only selection (no permanent_url/url) that must not be saved into content. */
  embeddable: boolean;
  alt: string;
  title: string;
  width: number | null;
  height: number | null;
}

const IMAGE_EXT = /\.(jpe?g|png|gif|webp|svg|bmp|ico|avif)$/i;
const TOKEN_URL = /\/(img|stream)(\.php)?\?([^#]*&)?token=/;

function absUrl(u: string, endpoint: string): string {
  // Leading whitespace/control chars are ignored by browsers: strip them before
  // the scheme check so " javascript:..." cannot slip through.
  // eslint-disable-next-line no-control-regex
  const t = String(u || '').replace(/^[\u0000-\u0020]+/, '');
  if (t === '') return '';
  let r: URL;
  try {
    r = new URL(t, endpoint || window.location.href);
  } catch {
    try {
      r = new URL(t, window.location.href);
    } catch {
      return '';
    }
  }
  // Only http(s) may reach saved content (drops javascript:, data:, vbscript:, ...).
  return r.protocol === 'http:' || r.protocol === 'https:' ? r.href : '';
}

/** Why a selection cannot be inserted; `''` when it can. */
export type Refusal = '' | 'preview' | 'unsafe' | 'token';

export function refusalMessage(reason: Refusal, name: string): string {
  if (reason === 'token') {
    return '"' + name + '" is only available through an expiring link (private disk without a public URL), so it was not inserted. Use a public disk or set public_url.';
  }
  if (reason === 'unsafe') return '"' + name + '" has a URL that is not http(s), so it was not inserted.';
  return '"' + name + '" is preview-only (no embeddable URL, e.g. a watermark overlay with allow_download=false), so it was not inserted. Burn in the watermark (Watermark editor, Apply) or issue a downloadable token to embed it.';
}

/**
 * Normalise a FluxFiles `onSelect` entry. Same rules as the CKEditor 4 / TinyMCE
 * plugins: prefer `permanent_url`, refuse a preview-only selection (its only view
 * is a short-lived token URL, so `img_srcset` is never inserted either), warn about presigned (expiring) URLs.
 */
export function fileInfo(file: FluxFile, endpoint = '', notify?: (message: string) => void): FileInfo {
  // Absolute and permanent only: an older core returns a root-relative url that
  // would resolve against the HOST page, and an /img|stream ?token= URL expires
  // (and would persist a short-lived token into saved content).
  const raw = file.permanent_url || file.url || '';
  let url = absUrl(raw, endpoint);
  const name = file.name || file.basename || file.path || '';
  const meta = file.meta || {};
  const reason: Refusal = raw === '' ? 'preview' : url === '' ? 'unsafe' : TOKEN_URL.test(url) ? 'token' : '';
  if (reason) url = '';
  const embeddable = url !== '';
  if (!embeddable) {
    const msg = refusalMessage(reason, name);
    console.warn('[FluxFiles] ' + msg);
    notify?.(msg);
  } else if (/[?&](X-Amz-|Signature=)/.test(url)) {
    console.warn('[FluxFiles] Inserting a presigned (expiring) URL, it will break when the URL expires. Use a public disk or public_url for embeds.');
  }
  const isImage = (!!file.mime && file.mime.indexOf('image/') === 0) || IMAGE_EXT.test(name);
  return {
    url,
    name,
    isImage,
    embeddable,
    alt: meta.alt_text || name,
    title: meta.title || name,
    width: file.width || null,
    height: file.height || null,
  };
}

/** `onSelect` hands over one file, an array, or folders; keep only real files. */
export function selectedFiles(payload: FluxFile | FluxFile[] | null | undefined): FluxFile[] {
  const list = Array.isArray(payload) ? payload : payload ? [payload] : [];
  return list.filter((f) => f && !f.is_dir);
}
