# WordPress plugin: folders in the native Media Library + importer from other folder plugins

Status: design, not implemented. Date: 2026-11-08. Scope: the **free** WordPress plugin
(`packages/wordpress`, heading to WordPress.org). No core (`packages/core`) change, no paid module.

Two related features:

- **(A) Media Library folders.** A folder tree in `upload.php` (grid and list) and in every
  `wp.media` modal, including the ones the block editor opens. You drag items into folders and
  filter the view by folder. It links to FluxFiles' own storage folders in one direction, at
  defined moments only (§2.3).
- **(B) Folder importer.** Brings the folder structure over from FileBird, plus HappyFiles, WP Media
  Folder, Real Media Library, Media Library Folders, Enhanced Media Library and any attachment
  taxonomy, so a site can switch to FluxFiles without losing its structure.

> **Key decision (§2): virtual-first hybrid.** Media Library folders are a **WordPress-side
> virtual taxonomy on attachments**, like FileBird. They never move bytes and never change a URL.
> FluxFiles' real storage tree feeds it in **one direction and only at defined moments**: when a
> FluxFiles file is attached, and on an explicit import. The reverse direction is the opt-in
> `wp fluxfiles migrate --layout=folders`. There is no live two-way sync and no storage move from
> the grid.

---

## 1. Problem and who pays

- **Persona.** A WordPress site owner or agency who uses (or is looking at) a folder plugin and
  wants S3/R2 offload. They organise media in the core Media Library grid and in the block
  editor's media modal, not in a separate file-manager screen.
- **The gap** (`docs/COMPETITOR-RESEARCH-2026-11.md`, gap table and opportunity #4):
  - FileBird, WP Media Folder (WPMF), Real Media Library (RML) and HappyFiles all put folders
    *inside* `wp.media`. FluxFiles doesn't.
  - Our readme FAQ admits it: "If folders inside the Media Library grid are what you need,
    FileBird is built for that."
  - The landing page's "Media folders ✓" row is read as in-grid folders, so it is currently
    misleading.
  - This is the main objection on WordPress.org: "I'd still need FileBird."
- **Market.** Folder plugins sell at $39–69/yr for one site and $99–199/yr for unlimited sites,
  with lifetime deals at $59–499 (FileBird, WPMF, RML, HappyFiles; sources [1]–[4] in the research
  doc). The segment is crowded, so this is a **conversion lever, not a SKU**.
- **Free or paid: free/core of the WordPress plugin.** That follows the research doc's
  recommendation and WordPress.org guideline 5 (no locked functionality). Nothing here touches
  `ModuleRegistry`. A later Pro tie-in ("share this folder" through the `share` module) is
  open question 9.

## 2. Architecture: the core design question

### 2.1 Options weighed

| Criterion | (1) Virtual taxonomy (FileBird model) | (2) Mirror of real storage folders | (3) Virtual-first hybrid (**chosen**) |
|---|---|---|---|
| URL stability | Perfect: moving to a folder changes no URL | **Breaks.** Every move changes the object key, so the URL changes. Posts embed literal `<img src>` and `srcset` in `post_content`, so each move needs a search-replace. CDN caches, hotlinks and SEO break | Same as (1). Bytes only move on an explicit, opt-in `migrate --layout=folders` |
| Native attachments in `uploads/YYYY/MM/` (most of a typical library) | Works | **Not applicable.** They aren't in FluxFiles storage at all until `wp fluxfiles migrate` | Works |
| Cost on S3/R2 | 1 row in `term_relationships` | S3 has no rename. Each file costs CopyObject + DeleteObject, ×4 with the WebP variants, plus metadata. Dragging 1,000 images is about 8,000 API calls in a web request | Same as (1) |
| Offload (`_fluxfiles_url`) | Unaffected | Would need re-pointing on every move. Today a move in the FluxFiles UI does **not** update `_fluxfiles_key`/`_fluxfiles_url` (`FluxFilesApi::handleMove` never touches attachments), so (2) would widen an existing gap | Unaffected |
| 50k+ attachments | Indexed `term_relationships` join; counts in `term_taxonomy.count` | Listing the storage tree per click (S3 `ListObjectsV2`) | Same as (1) |
| Multisite | Per-site tables, isolated for free | Per-site storage root (`sites/<id>`); OK | Same as (1) |
| Import fidelity from FileBird and the others | 1:1, since every competitor is virtual | Would force byte moves to import a structure | 1:1 |
| Stateless/storage-resident grain | Needs care: the data lives in the WP DB. See §2.2 | Fits best on paper | As (1), plus storage feeds it |

(2) is rejected. It breaks URLs on every drag, which is the one thing a Media Library must
never do, and it can't organise the native attachments that make up most libraries. (1) on
its own ignores the FluxFiles storage tree that users already built. (3) keeps (1)'s
guarantees and adds the storage tree as a seed.

### 2.2 Why WP DB storage is consistent with the grain

`.claude/CLAUDE.md` says metadata about **storage files** travels with the storage, and that we
must not add new stateful server dependencies. This feature respects both:

- **What is stored is metadata about WordPress attachment posts, not about storage objects.** An
  attachment's folder sits next to its title and alt text, in `wp_posts`, `wp_postmeta` and the
  core taxonomy tables. Most of these attachments have no FluxFiles object behind them.
- **No new table and no new server dependency.** It uses WordPress's own `wp_terms`,
  `wp_term_taxonomy`, `wp_term_relationships` and `wp_termmeta`. That data travels with every
  WordPress backup, export and `wp db export`, exactly like the attachments it describes.
- **Nothing goes into `_fluxfiles/`, and nothing into the JWT.** There are no claims, no
  scheduler and no background job. Every operation fires on the request that causes it. Large
  imports are client-driven batches with a stateless cursor (§6.4).
- **The FluxFiles storage tree stays the source of truth for physical layout.** The Media
  Library tree is a view-organisation layer over WordPress objects.
- **UI state stays client-side.** Expanded nodes, the last folder and the sidebar width live in
  `localStorage` (key `fluxfiles-mf:<blog_id>`), not in user meta.

### 2.3 The three bridges to FluxFiles storage (one-way, explicit)

1. **Attach-time placement.** Option `fluxfiles_media_folders_mirror`, default on.
   - When `FluxFilesAttachments::findOrCreate()` creates a **new** attachment for a FluxFiles
     file, the attachment is filed into a virtual folder that mirrors the file's storage
     directory: `FluxFiles/<dir>/<subdir>`, with a `<disk>` segment only when more than one disk
     is configured. Missing folders are created on the way.
   - It runs once, at creation. Later moves on either side are never synced.
   - New action `fluxfiles_attachment_created( int $id, string $disk, string $relKey )`, where
     `relKey` is the key with the tenant prefix removed (`$claims->unscopePath()` in
     `handleAttach`).
   - Filter `fluxfiles_media_folders_mirror_path` lets a site rename the segments or drop them.
2. **Import from FluxFiles storage.** `wp fluxfiles media-folders import --from=fluxfiles` and the
   admin importer. This is the backfill for (1): it rebuilds the storage directories as virtual
   folders and files every existing FluxFiles-backed attachment by `_fluxfiles_disk`/`_fluxfiles_key`.
3. **Folders → storage layout** (phase 3): `wp fluxfiles migrate --layout=folders`.
   - When native attachments are migrated into a bucket, the object key becomes
     `<prefix>/<folder path>/<id>-<name>`. Each folder segment goes through
     `sanitize_file_name()` and the result through `FluxFilesAttachments::isUnsafeKey()`.
   - This is the only place where a folder affects bytes, and it happens once, during a
     migration that already changes the attachment's backing URL.

## 3. Configuration

**No new JWT claims and no new env vars.** These are WordPress-only settings, stored as WP
options on Settings → FluxFiles, in the same style as `fluxfiles_offload` and
`fluxfiles_replace_picker`, which `docs/reference/CONFIG.md` doesn't list either.

| Option | Type / values | Default | Validation |
|---|---|---|---|
| `fluxfiles_media_folders` | `'1'` / `'0'` | `'1'` | `=== '1'`. The filter `fluxfiles_media_folders_enabled` wins |
| `fluxfiles_media_folders_mirror` | `'1'` / `'0'` | `'1'` | attach-time placement, §2.3 (1) |
| `fluxfiles_mf_import_lock` | int (unix expiry), internal, `autoload=no` | absent | created with an atomic `add_option` |

All three go into `uninstall.php`'s `$options` list. The existing smoke test asserts that every
registered option is listed there. The data itself (terms) is removed only with the opt-in (§5).

**New hooks** (documented in `packages/wordpress/HOOKS.md`):

| Hook | Type | Purpose |
|---|---|---|
| `fluxfiles_attachment_created` | action `(int $id, string $disk, string $relKey)` | fired by the bridge on a new attachment |
| `fluxfiles_media_folders_enabled` | filter `bool` | turn the UI on or off per request or screen |
| `fluxfiles_media_folders_capability` | filter `string`, default `upload_files` | who may create, rename or move folders |
| `fluxfiles_media_folders_conflicts` | filter `array<pluginBasename,label>` | known folder plugins that pause our UI (§7) |
| `fluxfiles_media_folders_import_sources` | filter `FolderSourceInterface[]` | register more importer sources |
| `fluxfiles_media_folders_mirror_path` | filter `string[] $segments, string $disk, string $relKey` | customise attach-time placement |
| `fluxfiles_media_folders_frontend` | filter `bool`, default `false` | also load the tree in front-end `wp.media` (BuddyPress and the like) |

## 4. Data model

- **Taxonomy** `fluxfiles_media_folder` (22 characters; the limit is 32). It's registered on
  `init` for `attachment`:
  - `hierarchical => true`, `public => false`, `show_ui => false`, `show_in_menu => false`
  - `show_admin_column => false` (we add our own column), `show_in_nav_menus => false`,
    `show_tagcloud => false`
  - `rewrite => false`, `query_var => false` (see §6.1 for why)
  - `show_in_rest => false` in v1 (open question 7)
  - `update_count_callback => '_update_generic_term_count'`. Attachments have status
    `inherit`, and the default callback counts only `publish`.
  - `capabilities`: all four (`manage_terms`, `edit_terms`, `delete_terms`, `assign_terms`) map
    to `upload_files`, which `fluxfiles_media_folders_capability` can change.
- **Folder** = a term. Its id is `term_id`, its name is `name`, its parent is `parent`
  (0 = top level). WordPress makes slugs unique and they are never shown. Term meta:
  - `fluxfiles_order` (int): order among siblings. Missing means sort by name.
  - `fluxfiles_import_src` (string, e.g. `filebird:123`, `happyfiles:45`): the idempotency key
    for re-running an import.
  - `fluxfiles_storage_path` (string, e.g. `local:photos/2026`): set on folders created by
    attach-time placement or by `--from=fluxfiles`, so they are found again rather than
    duplicated.
- **Membership is exclusive.** Each attachment is in at most one folder, matching what FileBird,
  HappyFiles and WPMF present. Every write goes through `wp_set_object_terms($id, [$term],
  TAX, false)`, which replaces the old folder. Our REST routes are the only write path,
  because `show_in_rest` and `show_ui` are off.
- **"Uncategorized"** is the absence of a term: a virtual node with id `0`, which can't be
  renamed or deleted. **"All files"** is the virtual id `-1`, meaning no filter.
- **Limits:** a name is 1–200 characters after `sanitize_text_field()`; depth is at most 32;
  sibling names must be unique, compared case-insensitively (`folder_name_taken`).

## 5. Storage layout, deactivation and uninstall

- **Storage:** none. Nothing new under `_fluxfiles/`. Bytes only move under
  `migrate --layout=folders` (§2.3, bridge 3).
- **Deactivation:** nothing is deleted. The Media Library goes back to stock and the terms stay
  in place for a later reactivation.
- **Uninstall** (`uninstall.php`, already a per-site loop on multisite):
  - The three options are **always** removed.
  - The folder structure is user data. Like files and metadata, it is removed **only** when
    `fluxfiles_delete_data_on_uninstall` is set. In that case the uninstaller registers the
    taxonomy, deletes every `fluxfiles_media_folder` term in batches of 500 with
    `wp_delete_term()` (which removes term meta and relationships), and calls
    `clean_taxonomy_cache()`.
  - Attachments are never touched. Source-plugin data (FileBird tables and the like) is never
    touched.

## 6. UI

All of it is zero-build JS, in keeping with the plugin's no-bundler rule: `assets/media-folders.js`
and `assets/media-folders.css`. Dependencies are WordPress's own `media-views`, `wp-api-fetch`,
`wp-i18n`, `wp-a11y`, `jquery-ui-draggable` and `jquery-ui-droppable` (guideline 13: no bundled
copies). Strings go through `wp.i18n.__(…, 'fluxfiles')` + `wp_set_script_translations()`.
Configuration comes from `wp_localize_script('FluxFilesFoldersCfg')`: the REST root, a
`wp_rest` nonce, the capabilities and the blog id. There are no inline `<script>` blocks.

### 6.1 Shared tree component and the query hook

- **One tree view** (`FF.FolderTree`, a `wp.Backbone.View`) is used in all three hosts. It shows
  All files, Uncategorized, then the folders with direct counts. It has a toolbar (New folder,
  collapse all) and a search box that filters the tree on the client.
- **Grid and modal.** We wrap `wp.media.view.AttachmentsBrowser.prototype.initialize` and
  `createToolbar`: call the original, then insert the tree as a left column (`.ff-mf-sidebar`)
  and toggle `.ff-mf-has-sidebar` on the browser. That covers `upload.php?mode=grid` and every
  `wp.media` frame (Featured image; the core Image, Gallery, Cover, Media & Text, File, Video and
  Audio blocks; the Customizer; page-builder modals that use `wp.media`). On frames narrower
  than 900 px, the sidebar collapses to an `AttachmentFilters` select.
- **Filtering.** The tree calls `collection.props.set({ fluxfiles_folder: id })`. WordPress's
  `wp_ajax_query_attachments()` strips query keys that are neither on its allowlist nor the
  `query_var` of an attachment taxonomy, and then applies `ajax_query_attachments_args`
  (checked against wordpress-develop trunk). We keep `query_var` off so that we control:
  - `include_children => false`
  - the Uncategorized case: `tax_query` `NOT EXISTS`
  - id-based terms (not slugs)

  So our `ajax_query_attachments_args` filter reads `absint($_REQUEST['query']['fluxfiles_folder'])`
  itself (it is an `int`, so there is nothing else to sanitise) and adds the `tax_query`. Search,
  type, date filters and pagination combine with it unchanged.
- **Uploads go into the current folder.** In the grid, the drop zone and the modal Upload tab
  call `uploader.param('fluxfiles_folder', id)`. The `add_attachment` action reads it on
  `async-upload.php`/`upload-attachment` (nonce `media-form`, already checked by core). It then
  checks `current_user_can('edit_post', $id)` and that the term belongs to our taxonomy, and
  assigns the folder.
- **Attachment details.** A "Folder: X · Move…" row, added through `attachment_fields_to_edit`
  plus a small JS handler. It works in the modal and on `post.php` for attachments.

### 6.2 List mode (`upload.php?mode=list`)

- **Sidebar.** The same tree, mounted beside `.wp-list-table` through a container printed in
  `admin_footer-upload.php` and moved into place by JS.
- **Selecting a folder** navigates to `?fluxfiles_folder=<id>`. A `pre_get_posts` hook (main
  query, `upload.php` only, `absint`) applies the same `tax_query`.
- **No-JS / a11y fallback.** A `restrict_manage_posts` dropdown with the same filter.
- **Column** "Folder" (`manage_media_columns` / `manage_media_custom_column`).
- **Bulk action** "Move to folder":
  - `bulk_actions-upload` adds the action, and a target `<select>` is printed next to it with
    `restrict_manage_posts` (the "Change role to…" pattern).
  - `handle_bulk_actions-upload` checks `check_admin_referer('bulk-media')` and `edit_post`
    per id, then assigns and redirects back with a count.
- **Drag a row** onto a tree node by its handle, using `jquery-ui-draggable`.

### 6.3 Drag and drop, keyboard and bulk

- **Items.** In grid, modal and list views you can drag one item, or the whole selection in bulk
  select mode, onto a tree node. A drag ghost shows "N items". When you drop:
  1. `POST /media-folders/assign`
  2. Optimistic count update
  3. Items move out of the filtered view
  4. Rollback and a notice on failure
- **Folders.** Dropping onto a node reparents the folder; dropping between nodes reorders it.
  Dropping a folder into its own descendant is refused on the client and the server
  (`folder_cycle`).
- **Single-pointer alternative** (WCAG 2.2 SC 2.5.7, dragging movements). Every drag has a
  non-drag equivalent:
  - a "Move to folder…" button in the bulk-select toolbar and in Attachment details, which opens
    an accessible folder picker dialog;
  - the list-mode bulk action;
  - a context menu on each tree node (button `aria-haspopup="menu"`, also on Shift+F10) with
    New subfolder, Rename, Move…, Delete.
- **Delete a folder:**
  - A confirmation dialog states the number of subfolders and files.
  - The folder and its subfolders are removed, and the files move to **Uncategorized**.
    Attachments are never deleted.
  - Unfiling another user's attachment requires `edit_others_posts`. Without it, a folder that
    holds others' files returns `403 folder_has_foreign_items`. This mirrors core's
    `assertOwnsTree` for `owner_only`.

### 6.4 Block editor

- **The media modal** opened by core blocks is covered by §6.1 at no extra cost.
- **Inserter "Media" tab** (phase 3). Register a single `registerInserterMediaCategory`
  (`core/block-editor`) entry, "FluxFiles folders". Its `fetch(query)` calls `/wp/v2/media` with
  our `fluxfiles_folder` param, which is added via `rest_attachment_collection_params` and
  `rest_attachment_query`. A folder is chosen by search text, because the inserter can't render
  a tree.
- **The "FluxFiles Image" block** and the existing opt-in "From FluxFiles" button are unchanged.
  They pick from storage, and attach-time placement files the result.

### 6.5 Accessibility, RTL and i18n

- **Tree semantics.** `role="tree"` / `treeitem` / `group`, with `aria-expanded`, `aria-level`,
  `aria-setsize` and `aria-posinset`.
- **Keyboard.** Roving `tabindex`. Arrow keys expand, collapse and move; Home and End jump; you
  can type ahead to a folder; Enter filters; F2 renames.
- **Focus and announcements.**
  - Focus returns to the invoking control after a dialog closes.
  - Moves, creates and errors are announced through `wp.a11y.speak()`.
  - Counts are announced in labels ("Photos, 128 files").
- **Contrast and RTL.** Admin colour-scheme variables give WCAG AA contrast. CSS logical
  properties handle RTL.
- **i18n.**
  - All strings use the `fluxfiles` text domain, translated through translate.wordpress.org
    language packs.
  - The iframe UI's 16 bundled languages don't cover these WordPress-side strings (open
    question 8).
  - No core `lang/*.json` change is needed, because no core error code is added.

## 7. Endpoints (WordPress REST, `fluxfiles/v1`)

These are **WordPress-only routes**, authenticated by cookie + `X-WP-Nonce` (`wp_rest`), never
by the JWT. They are not under `/api/fm/`, because they have no core counterpart and are not
proxied. They use the plugin's existing envelope `{data, error, error_code}`. Add them to
`.claude/api-map.md` under the WordPress-only section.

| Method + path | Body / params | Response `data` | Permission |
|---|---|---|---|
| `GET /media-folders` | — | `{folders:[{id,name,parent,order,count}], all:int, uncategorized:int}`: a flat list, which the client builds into a tree | `upload_files` |
| `POST /media-folders` | `{name, parent?:int}` | the folder | capability filter (default `upload_files`) |
| `PATCH /media-folders/(?P<id>\d+)` | `{name?, parent?, order?}` | the folder | capability filter. A reparent checks for cycles via `get_ancestors()` |
| `DELETE /media-folders/(?P<id>\d+)` | — | `{deleted_folders:int, unfiled:int}` | capability filter, plus `edit_others_posts` if it holds others' items |
| `POST /media-folders/assign` | `{folder:int (0 = Uncategorized), attachments:int[] ≤ 500}` | `{moved:int, skipped:[{id, code}]}` | `upload_files`, plus `edit_post` per id (failures go into `skipped` with `forbidden_attachment` / `not_attachment`) |
| `POST /media-folders/reorder` | `{parent:int, ids:int[] ≤ 1000}` | `{ok:true}` | capability filter |
| `GET /media-folders/import/sources` | — | `[{id, label, active:bool, folders:int, items:int, per_user:bool}]` | `manage_options` |
| `POST /media-folders/import` | `{source, dry_run:bool, phase:'folders'\|'assign', cursor:string\|null, options:{on_conflict:'keep'\|'replace', owners:'shared'\|'all', strip_root:bool}}` | `{phase, cursor, done:bool, stats:{…}}` | `manage_options` |

**Counts.**
- Folder counts come from `term_taxonomy.count`, which is O(folders).
- `uncategorized` is one `COUNT(*) … NOT EXISTS` query over non-trash attachments. It's cached
  in the object cache / a transient `fluxfiles_mf_uncat` (the transient is cleared on uninstall
  like the usage transients) and invalidated on assign, upload, delete and import.
- `all` comes from `wp_count_attachments()`.

**Error codes.** These are WordPress-side and translated in PHP with `__()`. They are **not**
core codes, so they need no `lang/*.json` ×16.

| Code | HTTP | When |
|---|---|---|
| `folder_not_found` | 404 | the id is not a term of our taxonomy |
| `folder_name_invalid` | 400 | the name is empty or longer than 200 characters after sanitising |
| `folder_name_taken` | 409 | a sibling has the same name (case-insensitive) |
| `folder_cycle` | 409 | a reparent into itself or a descendant |
| `folder_depth_exceeded` | 400 | deeper than 32 levels |
| `folder_has_foreign_items` | 403 | a delete that would unfile others' attachments without `edit_others_posts` |
| `too_many_items` | 413 | more than 500 ids (assign) or 1,000 (reorder) |
| `forbidden_attachment` / `not_attachment` | per item | inside `skipped[]` |
| `import_source_missing` | 404 | unknown source, or its tables or taxonomy are absent |
| `import_locked` | 409 | another import is running (the lock hasn't expired) |

## 8. Importer (B)

### 8.1 Sources: what each plugin stores (verified against source where noted)

The plugin data is always read **read-only with raw `$wpdb`**. A deactivated source plugin no
longer registers its taxonomy, so `get_terms()` would fail with `invalid_taxonomy`, while the
rows are still there. Table names are `$wpdb->prefix` + a literal, quoted with `%i`. They are
never taken from the request.

| Source (`id`) | Folders | Membership | Order | Notes |
|---|---|---|---|---|
| **FileBird** (`filebird`) | table `{prefix}fbv`: `id, name, parent (0 = root), type, ord, created_by` (verified, `filebird/trunk/includes/Install.php`, 6.5.9) | `{prefix}fbv_attachment_folder (folder_id, attachment_id)`, PK on both | `ord ASC` | `created_by = 0` is the shared tree. When `njt_fbv_folder_per_user = '1'`, each user has a private tree (`Model/Folder.php`). FileBird's **post-type** folders are separate taxonomies `fbv_pt_tax_<pt>` and are ignored |
| **HappyFiles** (`happyfiles`) | taxonomy `happyfiles_category` (per FileBird's importer, `ImportController.php`) | `term_relationships` | term-meta order key **unverified**; falls back to name | Pro post-type folders are separate taxonomies and are ignored |
| **WP Media Folder** (`wpmf`) | taxonomy `wpmf-category` (FileBird importer; JoomUnited forum) | `term_relationships` | term meta `wpmf_order` (forum; verify) | No free version, so it can't be installed in CI (fixture, §10). It may use a root term: `strip_root` drops a single top-level root that holds everything (**verify on a real install**). Cloud-synced (Drive/S3) folders import membership only |
| **Real Media Library** (`realmedia`) | table `{prefix}realmedialibrary`: `id, parent (-1 = root), name, ord, type, owner, …` (verified, `real-media-library-lite/trunk/inc/Activator.php`) | `{prefix}realmedialibrary_posts (attachment, fid, isShortcut, nr)` | `ord`, then `nr` | Rows with `isShortcut ≠ 0` are shortcuts (copies) and are skipped and reported. `type` (folder / collection / gallery, **verify** values) imports as plain folders |
| **Media Library Folders** (MaxGalleria, `wpmlf`) | posts of type `mgmlp_media_folder` (`ID`, `post_title`); parent via `{prefix}mgmlp_folders (post_id, folder_id)` (per FileBird's `WPMediaLibraryFolders.php`) | `mgmlp_folders` rows whose `post_id` is an attachment | none | MLF moves physical files into real folders under `uploads/`, so the URLs already follow them. We only mirror the structure. `strip_root` drops its top "uploads" node |
| **Enhanced Media Library** (`enhanced`) | taxonomy `media_category` | `term_relationships` | name | — |
| **Generic** (`taxonomy:<name>`) | any taxonomy present in `term_taxonomy` with `^[a-z0-9_-]{1,32}$` | `term_relationships` (attachments only) | name | Covers Folders by Premio `media_folder`, Wicked Folders `wf_attachment_folders`, MLA `attachment_category`, Mediamatic `mediamatic_wpfolder` and `feml-folder` (the same list FileBird's own importer reads) |
| **FluxFiles storage** (`fluxfiles`) | the directories of `_fluxfiles_key` of FluxFiles-backed attachments | postmeta `_fluxfiles_disk`/`_fluxfiles_key` | name | bridge 2 (§2.3). The folder meta `fluxfiles_storage_path` deduplicates |

Each source implements `FolderSourceInterface`:

- `id(): string`, `label(): string`
- `detect(): ?array{folders:int, items:int, per_user:bool}`
- `folders(?string $cursor, int $limit): array{rows: list<array{src_id, parent_src_id, name, order, owner}>, cursor: ?string}`
- `memberships(?string $cursor, int $limit): array{rows: list<array{attachment_id, src_id}>, cursor: ?string}`

`fluxfiles_media_folders_import_sources` lets third parties add sources.

### 8.2 Algorithm (idempotent, dry-run, resumable)

1. **Lock.** `add_option('fluxfiles_mf_import_lock', time()+120, '', 'no')`. If the option
   exists and hasn't expired, answer `409 import_locked`. Each batch renews the lock and the
   end of the run deletes it.
2. **Folders phase.** Sources are read in batches of 1,000 and sorted parents-first.
   - Map `src_id` to our `term_id` through the `fluxfiles_import_src` meta (`<source>:<src_id>`),
     loaded once per batch with a single `get_terms` call and a meta query on our own small
     taxonomy.
   - A folder that is already mapped is reused.
   - Otherwise, an existing sibling with the same name is **merged** (the src meta is added to it).
   - Otherwise a new term is created, with the decoded name (`wp_specialchars_decode`, then
     `sanitize_text_field`).
   - `owners: 'shared'` (the default) skips FileBird per-user trees and RML folders whose
     `owner` is not 0. `'all'` puts each user's tree under a top-level folder named after the
     user's `display_name` (open question 3).
3. **Assign phase.** Membership rows are read in batches of 500, ordered by `attachment_id`; the
   cursor is the last id.
   - Rows are skipped when the attachment doesn't exist or isn't an attachment
     (`skipped_missing`), or is a shortcut (`skipped_shortcut`).
   - If an attachment is in several source folders (taxonomy sources allow that), the first by
     source order wins and `multi_folder` is counted.
   - If the attachment is already in one of our folders, `on_conflict: 'keep'` (the default)
     leaves it as it is (`kept`). That way a re-run never undoes reorganising done by hand.
     `'replace'` overwrites.
   - Writes run under `wp_defer_term_counting(true)`, and the touched terms are recounted with
     `wp_update_term_count_now()` at the end of each batch.
4. **Budget.** Each request stops at 500 assignments or 5 s, whichever comes first, and returns
   `{cursor, done:false}`. That suits shared hosts with a 30 s `max_execution_time`.
5. **Dry run** runs the same code path with writes swapped for counters, mapping new folders to
   synthetic negative ids. Stats:
   `{folders_created, folders_merged, folders_reused, assigned, kept, skipped_missing, skipped_shortcut, multi_folder}`.
6. **Idempotent and resumable.** A re-run, or a resume after a closed tab, recomputes from the
   source and the meta map, so the only state is the client's cursor. No queue, no cron.
7. **Undo.** `wp fluxfiles media-folders reset --imported-from=<source> [--dry-run]` deletes
   the terms carrying that source's import meta and unfiles their attachments. The source data
   was never touched, so going back to FileBird means deactivating FluxFiles folders and
   reactivating FileBird.

### 8.3 Front ends

- **WP-CLI.** A new class `FluxFilesMediaFoldersCli`, registered as `fluxfiles media-folders`
  beside the existing `fluxfiles` command:
  - `wp fluxfiles media-folders sources`
  - `wp fluxfiles media-folders import --from=<id|taxonomy:name> [--dry-run] [--on-conflict=keep|replace] [--owners=shared|all] [--strip-root] [--yes]`.
    It uses `\WP_CLI\Utils\make_progress_bar` over the same batch function with no time budget.
    A real run prompts with `WP_CLI::confirm()` unless `--yes` is given.
  - `wp fluxfiles media-folders reset --imported-from=<id> [--dry-run]`
  - `wp fluxfiles media-folders stats`
  - On multisite, `--url=<site>` handles one site. `--network` loops over sites in batches of
    100, like `uninstall.php`.
- **Admin.** Media → "Import folders" (`add_media_page`). It appears only when a source is
  detected; otherwise there is a link from Settings → FluxFiles.
  1. Pick a source.
  2. A mandatory dry-run preview shows the stats, plus a sample of the first 20 folder paths.
  3. Choose the options.
  4. Import, with a progress bar driven by looping `POST /media-folders/import` until
     `done: true`.
  5. Closing the tab is safe; "Resume" simply re-runs the import.

## 9. Coexistence with FileBird and the others

Running two folder UIs at once means two `AttachmentsBrowser` patches, two sidebars and two
drag handlers fighting over the same drop.

- **Detection.** If a known folder plugin is active, our grid and modal UI stays **paused**. The
  check uses `is_plugin_active()` on a filterable list (`fluxfiles_media_folders_conflicts`):
  - `filebird/filebird.php`, `filebird-pro/filebird.php`
  - `happyfiles/happyfiles.php`, `happyfiles-pro/happyfiles-pro.php`
  - `real-media-library-lite/index.php`, `real-media-library/index.php`
  - `wp-media-folder/wp-media-folder.php`
  - `media-library-plus/maxgalleria-media-library.php`

  **Each basename must be verified at implementation.** Our REST routes, the importer and
  attach-time placement keep working while the UI is paused.
- **Notice.** One **dismissible** notice, shown only on `upload.php` and Settings → FluxFiles
  (guideline 11: limited in scope, dismissible): "FileBird is active, so FluxFiles folders are
  paused to avoid two folder panels. Import your FileBird folders, then deactivate FileBird."
  It links to the importer. Dismissal is stored in user meta `fluxfiles_mf_conflict_dismissed`,
  which is deleted on uninstall.
- **We never deactivate, modify or write to another plugin or its data.** WordPress.org has no
  explicit rule on interfering with other plugins, but guideline 9 (no dishonest conduct) makes
  this the only defensible line.
- **Polylang/WPML** duplicate attachments per language, and FileBird ships a Polylang
  compatibility class. In v1, membership is per attachment id; translations aren't synced
  (risk R5).

## 10. Security summary

- **Authorisation.**
  - Every route has a `permission_callback` and a `wp_rest` nonce.
  - Each attachment is checked with `edit_post`. An Author can file only their own uploads,
    which is the WordPress counterpart of `owner_only`.
  - Unfiling others' attachments by deleting a folder requires `edit_others_posts`.
  - The importer and reset require `manage_options`.
- **Input handling.**
  - Ids go through `absint`.
  - A folder id must belong to `fluxfiles_media_folder`. There is no path that accepts an
    arbitrary taxonomy or term id, so no cross-taxonomy writes are possible.
  - Names are sanitised and rendered with `textContent` or `esc_html` (imported names may
    carry entities or HTML).
- **Caps.** Batches are capped at 500 or 1,000. Depth is capped at 32. Request cost is
  bounded per call (§8.2).
- **SQL.** Every raw query uses `$wpdb->prepare` with `%i`/`%d`/`%s` and gets a
  `phpcs:ignore WordPress.DB.DirectDatabaseQuery` with a reason. Importer reads are one-off and
  admin-only. The Uncategorized count is cached.
- **Privacy.** FileBird per-user trees are skipped by default, so private folder names aren't
  exposed to the shared tree.
- **No outbound traffic or storage risk.** There is no outbound fetch, so there is no SSRF
  surface. No storage credentials are touched. Bytes move only in `migrate --layout=folders`,
  which reuses the existing migrate path with its stable-URL check and `isUnsafeKey()`.

## 11. Package layout

**New files** (all in the free `packages/wordpress`; nothing private):

- `includes/FluxFilesMediaFolders.php`: the taxonomy, query filters (`ajax_query_attachments_args`,
  `pre_get_posts`, `rest_attachment_query`), list-mode column and bulk action, assignment on
  upload, conflict detection, enqueue, and the listener for attach-time placement
- `includes/FluxFilesMediaFoldersApi.php`: REST routes (§7), registered on `rest_api_init`
- `includes/FluxFilesFolderImporter.php` and `includes/importers/{FolderSourceInterface,FileBirdSource,TermTaxonomySource,RealMediaLibrarySource,MediaLibraryFoldersSource,FluxFilesStorageSource}.php`
- `includes/FluxFilesMediaFoldersCli.php`
- `assets/media-folders.js`, `assets/media-folders.css`, `assets/media-folders-import.js`
- `templates/media-folders-import.php`

**Touched files:**

- `fluxfiles.php`: requires, and the CLI registration
- `FluxFilesPlugin.php`: boot
- `FluxFilesAttachments.php`: fire `fluxfiles_attachment_created`, passing `relKey` from
  `handleAttach` and from `wp fluxfiles migrate`
- `FluxFilesCli.php`: `migrate --layout=folders`, in phase 3
- `FluxFilesAdmin.php`: two settings fields
- `uninstall.php`: options, user meta, the transient, and opt-in term deletion
- `HOOKS.md`, `README.md`
- `readme.txt`:
  - add a feature bullet
  - rewrite the FAQ entry "How is this different from FileBird?" to say "Can I replace
    FileBird? Yes — import…"
  - changelog entry
  - a screenshot
- `.claude/api-map.md`
- `docs/COMPETITOR-RESEARCH-2026-11.md` follow-ups: the landing-page row becomes accurate

## 12. Test plan

- **PHP smoke** (`tests/test-wp-smoke.php` style, stubbed WP; a new file
  `tests/test-wp-media-folders.php`):
  - Taxonomy args: count callback, capabilities, `query_var` off.
  - Each REST route: the permission callback, `edit_post` per id, a foreign term id refused,
    exclusivity (assigning replaces), cycle and depth checks.
  - Delete unfiles and never deletes; `folder_has_foreign_items`.
  - The `ajax_query_attachments_args` filter for a folder, Uncategorized (`NOT EXISTS`) and All.
  - Assignment on upload ignores a foreign or nonexistent term.
  - Conflict detection pauses the enqueue.
  - Uninstall: options always go; terms go only with the opt-in.
  - The existing option-list assertion covers the new options.
- **Importer unit tests**, one fixture set per source (stubbed `$wpdb` rows):
  - mapping, parents-first ordering, a sibling merge, `strip_root`;
  - FileBird per-user skip; RML shortcut skip; multi-folder taxonomy rows;
  - **dry run writes nothing**;
  - a **second run changes nothing**;
  - `on_conflict=keep` preserves manual moves;
  - cursor resumption mid-batch; lock contention returns `409`;
  - `reset --imported-from` removes only that source's terms.
- **JS (vitest, beside `tests/block-picker.test.js`)**: tree building from the flat list,
  keyboard navigation (arrows, Home/End, type-ahead), the optimistic move and its rollback, and
  the cycle guard.
- **Real-WP e2e** (`packages/core/tests/apps/wordpress-e2e/wp-media-folders.spec.ts`, wp-env):
  - Grid: create, rename, nest; drag one item and a multi-selection; filter; Uncategorized
    count; upload into the selected folder.
  - List mode: the dropdown filter, the bulk "Move to folder" action, the column.
  - Post editor: the core Image block → Media Library modal → sidebar filters → insert.
  - Keyboard-only move through the "Move to folder…" dialog.
  - An Author (created with `wp user create`) can't move an admin's attachment.
  - Attach-time placement: pick in the FluxFiles Image block, and the attachment lands in
    `FluxFiles/<dir>`. Toggle `fluxfiles_local_private` off for this case, like the existing
    `/attach` test.
  - **Importer e2e.**
    - FileBird, HappyFiles and RML Lite are free on WordPress.org, so add them to `.wp-env.json`
      `plugins` (or install them with `wp plugin install` in setup).
    - Seed the folders with `wp db query` fixtures.
    - Run the CLI import and the admin batch UI, then assert the tree and the memberships.
    - Run it again and assert that nothing changed.
    - Assert the conflict notice is shown while FileBird is active.
    - WPMF and MLF are covered by an mu-plugin that registers `wpmf-category` and creates the
      MLF tables with fixture rows.
  - Reuse the known setup gotchas: the stale Docker bind mount (`touch`), the rate-limit
    mu-plugin and toggling `fluxfiles_local_private`.
- **Multisite e2e** (extend `wp-multisite.spec.ts`): a folder on site 2 is invisible on site 1;
  `--network` import; uninstall removes the data per site only with the opt-in.
- **Performance bench** (a manual script, not CI): load 50k attachments, 2k folders and 10 levels
  by SQL fixture into wp-env. Targets:
  - `GET /media-folders` < 300 ms
  - one filtered grid page (80 items) < 500 ms
  - the Uncategorized count < 150 ms (uncached)
  - a 50k-membership import completes on a 30 s-per-request budget
- **Plugin Check** (the existing `wp-plugin-check` CI job) must stay error-free:
  - no inline scripts; escaping on all output;
  - `wp_unslash`/sanitisers on superglobals (`$_REQUEST['query']`, `$_GET['fluxfiles_folder']`);
  - nonces on the bulk action;
  - direct-DB `phpcs:ignore`s with reasons;
  - no bundled jQuery UI (use WordPress's handles).

## 13. Phases and effort (one developer who knows the codebase)

| # | Scope | Effort |
|---|---|---|
| 0 | **Now, independent of the build:** fix the landing page's "Media folders" row (rename it to "Folder file manager") until phase 1 ships | 0.5 d |
| 1 | **MVP (A):** taxonomy and REST; the tree in the grid and every `wp.media` modal; drag-drop plus the "Move to…" dialog; All and Uncategorized; upload into folder; list-mode filter, column and bulk action; conflict pause and notice; settings; uninstall; smoke tests and e2e for the grid, modal and list | 7–9 d |
| 2 | **Importer (B):** FileBird, HappyFiles, RML, WPMF, MLF, Enhanced and generic taxonomy sources; CLI and the admin batch UI; dry-run, idempotency, reset; importer e2e with the real FileBird, HappyFiles and RML Lite | 4–5 d |
| 3 | **FluxFiles bridges:** attach-time placement plus `fluxfiles_attachment_created`; `--from=fluxfiles`; `migrate --layout=folders`; inserter media category plus the `/wp/v2/media` param | 2–3 d |
| 4 | **Hardening and launch:** a11y audit (keyboard, screen reader), RTL, the 50k bench and tuning, multisite e2e, page-builder smoke (Elementor, Beaver, Bricks modals), readme, FAQ, screenshot, changelog | 3 d |

**Total about 17–21 developer-days**, roughly four weeks with review. Phases 1+2 together form
the first WordPress.org release worth announcing ("folders + import from FileBird"). Phase 3
can follow in a minor release.

## 14. Risks

- **R1. Backbone internals are not a public API.**
  - `AttachmentsBrowser` patching is what every competitor does, but core can change it. The
    Media and Editor teams have planned a DataViews-based Media Library ("Phase 3 Media";
    DataViews is meant to replace list tables) [5][6].
  - Mitigation: all logic lives in PHP and REST; the UI adapters are thin; a future DataViews
    screen could filter by the taxonomy natively (that would need `show_in_rest`, open
    question 7).
  - Track Gutenberg releases in CI against WordPress trunk.
- **R2. Crowded market.** The goal is parity plus FluxFiles' storage, offload and import, not to
  beat FileBird on folder UX alone. Measure success by fewer "still need FileBird" objections and
  by WordPress.org reviews.
- **R3. Two UIs if detection misses a plugin.** The conflict list is filterable, and the notice
  asks users to report it.
- **R4. `term_taxonomy.count` includes trashed attachments when `MEDIA_TRASH` is on.** That's a
  rare setting; accept it, or recount with a status filter if `MEDIA_TRASH` is defined.
- **R5. Polylang/WPML attachment translations** each need filing separately. Sync is v2.
- **R6. Unverified source details:** WPMF's root term and order key, HappyFiles' order meta, the
  RML `type` values, and the plugin basenames in §9. Each must be checked against a real install
  before phase 2 is merged. The importer degrades to name order and no root-stripping when it
  is unsure.
- **R7. Existing gap, not introduced here.** Moving or renaming a file in the FluxFiles UI
  leaves bridged attachments pointing at the old key/URL. Virtual folders don't make it worse.
  It deserves its own issue (re-point attachments on move, or refuse a move of an attached file).

## 15. Open questions for the owner

1. **Default on for existing installs on update?** The recommendation is on, with the conflict
   pause. The alternative is on for new installs and off (plus a one-time notice) for upgrades,
   to avoid a surprise sidebar.
2. **Who manages folders?** `upload_files` matches FileBird, so Authors can create shared folders.
   The stricter alternative is `edit_others_posts` (Editors and up), with Authors only filing
   their own items.
3. **FileBird per-user folders.** v1 supports one shared tree. Should the importer skip
   per-user trees (the default) or import them under a per-user folder that everyone can see?
   Is native per-user folder mode wanted at all, and if so in v1 or later?
4. **Attach-time placement:** keep it default on, and is `FluxFiles/<dir>` the right root (versus
   mirroring at the top level)?
5. **`migrate --layout=folders`:** phase 3 as planned, or drop it? It's the only feature where a
   folder decides a byte location.
6. **Exclusive membership.** One folder per file, as FileBird, HappyFiles and WPMF present it.
   RML shortcuts and multi-term taxonomies collapse to their first folder on import.
7. **`show_in_rest` on the taxonomy.** Turning it on makes it easier for DataViews and headless
   setups, but lets `/wp/v2/media` assign several folders and so bypass exclusivity.
   Recommendation: off in v1, revisit with R1.
8. **Translations.** Rely on translate.wordpress.org (there are about 60 new strings), or ship
   `.mo` files for the iframe UI's 16 languages for parity on day one?
9. **Pro tie-in later.** For example, "Share this folder" or an "Upload portal into this folder"
   through the `share`/`intake` modules in FluxFiles Pro. Folders themselves stay free; confirm
   that nothing in (A) or (B) is gated.
10. **Storage-resident export of the folder tree** (for example
    `_fluxfiles/wp-media-folders.json`). The recommendation is no: it's WordPress data, and
    WordPress backups already carry it. Confirm.

## Sources

1. FluxFiles competitor research, FileBird/WPMF/RML/HappyFiles pricing and gap analysis: `docs/COMPETITOR-RESEARCH-2026-11.md` (local, gitignored).
2. FileBird tables: https://plugins.svn.wordpress.org/filebird/trunk/includes/Install.php · per-user mode: https://plugins.svn.wordpress.org/filebird/trunk/includes/Model/Folder.php · source taxonomies read by FileBird's importer: https://plugins.svn.wordpress.org/filebird/trunk/includes/Controller/Import/ImportController.php , https://plugins.svn.wordpress.org/filebird/trunk/includes/Controller/Import/Methods/RealMediaFolderImport.php , https://plugins.svn.wordpress.org/filebird/trunk/includes/Controller/Import/Methods/WPMediaLibraryFolders.php
3. Real Media Library schema: https://plugins.svn.wordpress.org/real-media-library-lite/trunk/inc/Activator.php
4. WP Media Folder taxonomy and order meta (support forum): https://www.joomunited.com/support/pre-sales-forum/pres-sale-questions-about-wp-media-folder/order-query-with-custom-order
5. Data Views as the list-table replacement, including Media: https://make.wordpress.org/core/tag/plugins/
6. Phase 3 Media Library planning: https://make.wordpress.org/core/tag/media-library/
7. `wp_ajax_query_attachments()` allowlist and `ajax_query_attachments_args`: https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-admin/includes/ajax-actions.php
8. Prior art for the inserter media category and virtual folders: https://fastly.jsdelivr.net/wp/plugins/virtual-media-folders/trunk/CONTEXT.md
9. WordPress.org plugin guidelines (5, 9, 11, 13): https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/

---

## Claims to add to `docs/reference/CONFIG.md`

**None.** This feature adds no JWT claims and no env vars. Its configuration is three
WordPress options (`fluxfiles_media_folders`, `fluxfiles_media_folders_mirror`, and the internal
`fluxfiles_mf_import_lock`) and the hooks in §3, all documented in `packages/wordpress/HOOKS.md`
and `uninstall.php`, like the plugin's other WordPress-only settings. `test-config-doc.php` is
unaffected.
