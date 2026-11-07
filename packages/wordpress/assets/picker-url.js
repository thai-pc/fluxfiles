/**
 * Which URL of a picked file may be saved into a post (shared by the block, the classic
 * editor button and the media-modal integration). Mirrors the editor adapters
 * (tinymce / ckeditor): prefer `permanent_url`, never persist an expiring
 * /img|/stream ?token= link (private local / SFTP disks), only http(s).
 * Every refusal message is plain text; callers must render it as text (alert(),
 * wp.data notices, textContent), never as HTML.
 */
(function (root) {
    function absUrl(u, endpoint) {
        if (typeof u !== 'string') return '';
        // Browsers ignore leading whitespace/control chars: strip them before the scheme check.
        var t = u.replace(/^[\u0000- ]+/, '');
        if (t === '') return '';
        var r = null;
        try { r = new URL(t); } catch (e) {
            // Root-relative (the local disk's `/storage/uploads/x`): resolve against the
            // origin that serves the UI, then against this page.
            try { r = new URL(t, endpoint || window.location.href); } catch (e2) {
                try { r = new URL(t, window.location.href); } catch (e3) { return ''; }
            }
        }
        return (r.protocol === 'http:' || r.protocol === 'https:') ? r.href : '';
    }
    function isTokenUrl(u) {
        return /\/(img|stream)(\.php)?\?([^#]*&)?token=/.test(u);
    }
    /** @return {{url:string, reason:string}} reason: '' | 'preview' | 'unsafe' | 'token' */
    function resolve(file, endpoint) {
        var raw = (file && (file.permanent_url || file.url)) || '';
        var url = absUrl(raw, endpoint);
        var reason = raw === '' ? 'preview' : (url === '' ? 'unsafe' : (isTokenUrl(url) ? 'token' : ''));
        // A presigned S3/R2 URL is allowed (same as the editor adapters) but expires.
        if (!reason && /[?&](X-Amz-|Signature=)/.test(url) && window.console) {
            console.warn('[FluxFiles] Inserting a presigned (expiring) URL; it will break when it expires. Use a public disk or a public URL for embeds.');
        }
        return { url: reason ? '' : url, reason: reason };
    }
    function refusalMessage(reason, name, i18n) {
        var __ = (i18n && i18n.__) || function (s) { return s; };
        var sprintf = (i18n && i18n.sprintf) || function (s, n) { return s.replace('%s', n); };
        if (reason === 'token') {
            /* translators: %s: file name */
            return sprintf(__('"%s" is stored on private storage, so it has no permanent link and was not inserted. A link would stop working after an hour. Use a public disk or set a public URL for the disk.', 'fluxfiles'), name);
        }
        if (reason === 'unsafe') {
            /* translators: %s: file name */
            return sprintf(__('"%s" has a URL that is not http(s), so it was not inserted.', 'fluxfiles'), name);
        }
        /* translators: %s: file name */
        return sprintf(__('"%s" is preview-only (no embeddable URL), so it was not inserted.', 'fluxfiles'), name);
    }
    root.FluxFilesPickerUrl = { resolve: resolve, refusalMessage: refusalMessage };
})(window);
