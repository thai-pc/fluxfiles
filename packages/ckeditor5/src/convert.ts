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
  srcset: string;
  sizes: string;
}

const IMAGE_EXT = /\.(jpe?g|png|gif|webp|svg|bmp|ico|avif)$/i;

/**
 * Normalise a FluxFiles `onSelect` entry. Same rules as the CKEditor 4 / TinyMCE
 * plugins: prefer `permanent_url`, refuse a preview-only selection (its only view
 * is a short-lived token URL), warn about presigned (expiring) URLs.
 */
export function fileInfo(file: FluxFile): FileInfo {
  const url = file.permanent_url || file.url || '';
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
    // Only a permanent URL set can carry a srcset; img_srcset is built from img_base.
    srcset: isImage ? file.img_srcset || '' : '',
    sizes: isImage ? file.img_sizes || '' : '',
  };
}

/** `onSelect` hands over one file, an array, or folders; keep only real files. */
export function selectedFiles(payload: FluxFile | FluxFile[] | null | undefined): FluxFile[] {
  const list = Array.isArray(payload) ? payload : payload ? [payload] : [];
  return list.filter((f) => f && !f.is_dir);
}
