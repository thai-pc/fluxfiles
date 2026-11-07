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
  if (u === '' || /^[a-z][a-z0-9+.-]*:/i.test(u)) return u;
  try {
    return new URL(u, endpoint || window.location.href).href;
  } catch {
    return u;
  }
}

/**
 * Normalise a FluxFiles `onSelect` entry. Same rules as the CKEditor 4 / TinyMCE
 * plugins: prefer `permanent_url`, refuse a preview-only selection (its only view
 * is a short-lived token URL, so `img_srcset` is never inserted either), warn about presigned (expiring) URLs.
 */
export function fileInfo(file: FluxFile, endpoint = ''): FileInfo {
  // Absolute and permanent only: an older core returns a root-relative url that
  // would resolve against the HOST page, and an /img|stream ?token= URL expires
  // (and would persist a short-lived token into saved content).
  let url = absUrl(file.permanent_url || file.url || '', endpoint);
  if (TOKEN_URL.test(url)) url = '';
  const name = file.name || file.basename || file.path || '';
  const meta = file.meta || {};
  const embeddable = url !== '';
  if (!embeddable) {
    console.warn(
      '[FluxFiles] "' + name + '" is preview-only (no embeddable URL, e.g. a watermark overlay with allow_download=false), so it was not inserted. Burn in the watermark (Watermark editor, Apply) or issue a downloadable token to embed it.'
    );
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
