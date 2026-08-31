# Feature Deep Dives

Internals and advanced usage for a few features that are more than a
one-liner in the root README. Each section below is linked from the README
next to its short intro/example — read this when you need the mechanics, not
just the happy path.

## On-demand WebP & AVIF

FluxFiles generates three **fixed WebP variants** on upload (`thumb` 150 /
`medium` 768 / `large` 1920 px) — use `file.variants.<size>.url` and you're
already serving WebP. For **arbitrary sizes on demand** (responsive images,
exact widths), each image entry also carries an **`img_base`** URL (see the
root README's *On-demand WebP & AVIF* section for the request example).

- **AVIF or WebP, negotiated for free.** With the default `format=auto`, the
  endpoint reads the browser's `Accept` header and serves **AVIF** when accepted
  (smallest, on builds with GD AVIF support), else **WebP**, else the original
  untouched for ancient clients. AVIF and WebP cache as separate files and the
  response sends `Vary: Accept`. Force one with `&format=avif` / `&format=webp`.
  _(This is core/free — there is no paid "AVIF" tier.)_
- **First request** converts + caches into the file's `_variants/` directory (so
  the existing delete/trash cleanup invalidates it for free); later requests serve
  the cache. The cache key is stamped with the source mtime, so a re-upload never
  re-matches a stale image.
- **Width** is rounded to 100px and clamped to `webp_max_width`; **quality** snaps
  to `60`/`75`/`80`/`90`. This bounds the number of cache variants per file
  (no unbounded growth from `?width=801,802,…`).
- **Box sizing (optional):** add `&height=` with `&fit=cover` (crop to fill the
  `width×height` box) or `&fit=contain` (default — scale to fit, keep aspect).
  Add `&dpr=2` (or `3`) to multiply the requested size for retina screens. None
  of these ever upsize past the source.
- **SVG and animated GIFs are never converted** (served as-is); `webp_enabled: false`
  disables the endpoint entirely (no `img_base`).

> `img_base` carries a short-lived per-file token in the query string (an `<img>`
> can't send an `Authorization` header) — the same tradeoff as the media stream
> token: single-file scope + short TTL. It mints only when the disk config wires a
> stream secret, which core-standalone always does and the Laravel/WordPress
> proxies now do too (`GET /api/fm/img` is proxied by both).

## Responsive `srcset`

On top of `img_base`, every image entry also gets a ready-to-use **`img_srcset`**
string, so the host can drop a responsive image straight from `list()` (see the
root README for the markup example).

- The candidate widths come from the token's **`srcset_widths`** ladder (default
  `[320, 640, 768, 1024, 1366, 1920]`, snapped to 100px and clamped to
  `webp_max_width`). Each is a cached WebP from the same `/api/fm/img` endpoint.
- Widths are **capped at the image's natural width** (read from the stored
  dimensions — no extra I/O), and the source width itself is always offered, so a
  browser never requests an upscale. Images narrower than 100px get no `img_srcset`.
- Set the **`srcset_sizes`** claim to also emit an **`img_sizes`** attribute;
  otherwise the host supplies its own `sizes`. The standalone UI already wires
  `srcset`/`sizes` onto its detail-panel and lightbox previews.
- Rides the exact same gate as `img_base` (proxied by both Laravel and WordPress too).

## Watermark

FluxFiles watermarks images the way the rest of the industry does — two modes for
two jobs. **For almost everyone it's the first one (burn-in).** The second is an
advanced mode only for selling images.

### Watermark editor (burn-in) — the normal way

Open an image → the **Watermark** tab in the detail panel (free) → drag a logo or
text to any position, resize the logo by its handle, set opacity → **Apply**
(replace the file) or **Save as copy**. It **burns the watermark into the image**
(`POST /api/fm/watermark`), exactly like a Photoshop export or a WordPress
watermark plugin. The file now *is* a watermarked image, so **every consumer
carries it** — the picker, a download, an `<img>` you insert into TinyMCE /
CKEditor / Summernote. Extension is preserved; variants regenerate.

**Non-destructive — the original is kept.** Applying in place snapshots the true
original to `_fluxfiles/originals/`, so it's safe and reversible: re-opening the
editor re-positions from the clean original (it never stacks a second mark), and a
**Remove watermark** button (`POST /api/fm/watermark/remove`) restores the
original byte-for-byte. (Like Image Watermark / Easy Watermark / Envira — never
lose the original.)

> ✅ **Want a watermarked image in your content / blog / CMS? This is it.** Pick it
> in the editor and the inserted `<img>` shows the watermark. Nothing else to set.

**Adapter support (burn-in):** works **everywhere** — it's a normal write through
`POST /api/fm/watermark`, which **all** adapters proxy (Laravel, WordPress) or
reach via the embedded UI (React, Vue, the TinyMCE/CKEditor/Summernote pickers).

### Advanced: preview protection for selling images (overlay)

Only if you're building a **stock-photo / photographer store** (Shutterstock,
Getty, Pixieset…): show watermarked **previews** but never hand out the clean file
until purchase. The watermark is applied **on the fly when serving** (`/api/fm/img`)
— the source file is never modified. Enable it with token claims (off by default):

```php
$token = fluxfiles_token([
    'user'   => 'user-42',
    'perms'  => ['read'],
    'disks'  => ['local'],
    'prefix' => 'users/42',
    'claims' => [
        'webp_enabled'      => true,
        'watermark_enabled' => true,
        'watermark_type'    => 'text',          // or 'logo'
        'watermark_text'    => '© Acme Corp',
        'watermark_position'=> 'bottom-right',
        'watermark_opacity' => 0.6,
    ],
]);
```

- Enabling `watermark_enabled` **automatically makes the token preview-only**
  (`allow_download` is forced off): `list()` serves only the watermarked `img_base`,
  the clean `url`/`permanent_url`/`variants` are withheld, GET presign → `403`, zip
  is disabled, and the UI marks such images with a **"Preview"** badge. The clean
  original is never served. To grant it later (after purchase), issue a **separate
  token without the watermark**.
- **Logo watermark:** upload a transparent PNG as a normal file and set
  `watermark_type => 'logo'` + `watermark_logo_path`. A missing/unsafe path falls
  back to text (never a clean image). Watermarked WebPs are cached in `_variants/`.
- **Adapters:** `/api/fm/img` itself is now proxied by both Laravel and WordPress
  (gated-local media streaming and on-demand WebP work through the proxy — see the
  sections above), but the overlay **compositing** was intentionally not ported
  there. So `watermark_enabled` is still forwarded only by token minters that
  target a standalone core — `embed.php`, `@fluxfiles/node`, and the **Laravel
  adapter in `standalone` mode**. The **WordPress** plugin and **Laravel proxy
  mode** drop the claim: forwarding it would mint a preview-only token (overlay
  forces `allow_download` off) whose `/img` request the proxy's handler would
  serve unwatermarked — worse than not forwarding it. Use the **burn-in** route
  there instead, which is proxied.

**How customers actually see the preview** — you don't *embed* it (the `img_base`
token is short-lived, made for live rendering, not for saving into content). You
render it **fresh on each page load**, like every stock site:

```html
<!-- Your own gallery/product page: call list(), render img_base live -->
<img :src="endpoint + file.img_base + '&width=800'"
     :srcset="endpoint + file.img_srcset" :sizes="'100vw'" :alt="file.name">
```

```js
// or with the SDK helper:
const src = FluxFiles.imgUrl(file, { width: 800 });   // = img_base + &width=800
```

…or just let customers **browse inside the embedded FluxFiles UI** with a
preview-only token — the cards, detail panel and lightbox already render the
watermarked images and hide download.

| | Burn-in editor (normal) | Overlay (advanced, selling) |
|---|---|---|
| Where | Written into the file bytes | Applied at serve time via `/api/fm/img` |
| Clean original | Replaced (or saved as a copy) | Kept, but never served (preview-only) |
| Insert into an editor / download | **Yes** | **No** — show it live in your gallery instead |
| Best for | Branding, putting marked images in content | A stock-photo / photo-seller store |

> The two are **mutually exclusive per token**: a token with the overlay enabled
> (`watermark_enabled`) is preview-only, so the burn-in editor (and crop) are hidden
> in the UI and `POST /api/fm/watermark` returns `409 watermark_overlay_active` —
> burning a second mark into a file you can't download would just double-watermark
> it. Burn in with a normal, downloadable token instead.

## SSH terminal — security model

When the disk is SFTP, FluxFiles can open a built-in **terminal** (see the root
README's *SSH terminal* section for the enabling claim and basic usage). This
section covers the threat model behind its guardrails.

> 🔒 **This grants shell access as the SSH user.** A shell can't be safely
> sandboxed by filtering its input; the real boundary is the **SSH account's OS
> permissions** — use a **least-privilege user**. FluxFiles adds:
> - **`allow_terminal` claim, default `false`** — must be opted in deliberately.
> - SFTP disks only, requires the **`write`** permission, **audited per command**.
> - A server **kill-switch** `FLUXFILES_TERMINAL_DISABLED=true`, a per-command
>   timeout `FLUXFILES_TERMINAL_TIMEOUT` (default 30s), and a 2 MB output cap.
> - A **catastrophic-command guardrail** (`rm -rf /`, `mkfs`, fork bomb,
>   `chmod -R 777 /`, …) → a two-step confirm in the UI. This is an *accident*
>   guard, not a security boundary; opt out with `FLUXFILES_TERMINAL_CONFIRM=false`.
>
> ⚠️ **The terminal is NOT confined to the disk's `root`.** Unlike file operations
> (which are sandboxed to the SFTP disk `root`), the terminal is a **real shell** —
> the user can `cd /` and reach anything the SSH account can on the **whole server**
> (`cat /etc/passwd`, other tenants' folders, …). The `root` is only the terminal's
> *starting* directory, not a fence. **So if you enable `allow_terminal`, give that
> SFTP disk a dedicated least-privilege SSH account** scoped at the OS level (or a
> chroot/jailed user) — never a shared or `root`-level account.
>
> 💡 **Why the dangerous-command list is intentionally small:** it's an *accident*
> guard for a trusted operator (like a "Are you sure?" dialog), **not** a filter
> against a malicious user. Text matching can't stop a determined shell user
> (`r''m -rf /`, `$(echo rm) -rf /`, `RM=rm; $RM …` all evade any pattern), and
> someone with shell access already has the SSH account's full rights anyway. A
> broad list would just false-positive on normal commands (e.g. `rm -rf node_modules`),
> training users to click through — so it flags only the few obvious catastrophes.
> The actual defense is: terminal off by default + a least-privilege SSH account +
> `write` perm + the server kill-switch.
>
> **Shared hosting without a shell** (`internal-sftp` / a forced command) is
> detected automatically and shown a clear "this host doesn't allow a terminal
> (SFTP only)" message — the feature degrades instead of hanging. Note: FluxFiles
> only ever uses the SFTP **subsystem** for file ops; the shell is opened *only*
> for this opt-in terminal, so a shell-less SFTP account is otherwise unaffected.
> The terminal is proxied by the Laravel/WordPress adapters too, on the same
> gate and guardrails as core-standalone.
