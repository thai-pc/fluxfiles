# FluxFiles — Roadmap nâng cấp 6 tháng

> **Document version:** 1.0  
> **Last updated:** 26/05/2026  
> **Owner:** thai-pc  
> **Status:** Draft for review

---

## Executive Summary

### Tình trạng hiện tại
FluxFiles là một file manager PHP có technical capabilities tốt (cross-disk operations, BYOB encryption, AI tagging, JWT scoping) nhưng adoption gần như zero (~1 install Packagist, 1 GitHub star). Vấn đề không phải code quality mà là **visibility + một số foundation features hiện đại chưa có** (URL transformations, Tus protocol, Eloquent integration).

### Vision sau 6 tháng
FluxFiles trở thành **lựa chọn mặc định cho dev PHP/Laravel** khi cần file manager hiện đại với multi-cloud + AI + MCP-native. Target: 1000+ GitHub stars, 500+ Packagist installs, ecosystem plugin 3+, có case study production thật.

### 3 nguyên tắc chiến lược xuyên suốt
1. **Visibility trước Feature** — không ship feature cho 0 user.
2. **Differentiate, không bắt chước** — focus moat thật (cross-disk, BYOB, MCP), không cố thay Cloudinary.
3. **Stop conditions thật** — biết khi nào pause feature, dồn marketing.

### Phân bổ thời gian đề xuất
| Phase | Mục đích | Thời lượng | % effort |
|-------|----------|-----------|----------|
| Phase 0 | Visibility foundation | 2-3 tuần | 10% |
| Phase 1 | Transform pipeline + Tus | 5-6 tuần | 20% |
| Phase 2 | Laravel ecosystem | 4 tuần | 15% |
| Phase 3 | MCP + AI first-mover | 4 tuần | 15% |
| Phase 4 | Differentiation deep | 4-5 tuần | 20% |
| Phase 5 | Collaboration & polish | 4-6 tuần | 20% |

---

## Phase 0 — Visibility Foundation

**Thời lượng:** 2-3 tuần  
**Goal:** Trước khi ship feature mới, đảm bảo bất kỳ dev nào nghe đến FluxFiles có thể evaluate trong 5 phút.

### Strategic rationale
Skip phase này = mọi feature sau ship cho 0 audience. ROI thấp nhất. Phase này **bắt buộc đầu tiên** vì các phase sau đều dựa vào kênh phân phối được tạo ở đây.

### Scope chi tiết

**Task 0.1 — Demo site công khai**
- Deploy `demo.fluxfiles.dev` (hoặc subdomain GitHub Pages nếu chưa có domain)
- Multi-user setup với 3-5 demo account (`demo1@`, `demo2@`, password đơn giản)
- Seed sẵn ~50 file mẫu (ảnh, PDF, doc) ở mỗi disk
- Cron job reset toàn bộ data mỗi giờ
- Hiển thị banner "Demo resets every hour" rõ ràng
- Enable cả 3 disk: local, S3 (free tier Backblaze hoặc demo bucket), R2
- Test isolation: user A không thấy file user B

**Task 0.2 — Docker official image**
- Dockerfile multi-stage (PHP 8.3 + nginx + composer install)
- Push lên Docker Hub: `fluxfiles/fluxfiles:latest`, tag theo version
- `docker-compose.yml` example với Redis (cho rate limit) + volume mount
- README section "Try in 30 seconds" với 1 dòng `docker run`
- Test trên 3 môi trường: Linux, macOS, Windows (WSL)

**Task 0.3 — Video demo 90 giây**
- Script flow: open empty browser → upload 3 ảnh → AI tag tự động → cross-copy 1 ảnh sang R2 → share link → done
- Tool: ScreenStudio hoặc Loom + edit nhẹ
- Voice-over tiếng Anh + subtitle tiếng Việt
- Upload YouTube (main) + Twitter (clip 30s cắt)
- Embed top của README

**Task 0.4 — Landing page chuyên nghiệp**
- Build trên `fluxfiles.dev` hoặc GitHub Pages
- Hero: 1 câu tagline + demo video embed + "Get Started" + "Live Demo"
- 3 USP với icon: Multi-cloud, BYOB, AI-native
- Comparison table honest: FluxFiles vs Spatie vs unisharp vs Uppy+S3
- Code snippet 3 dòng để show ease of use
- Customer logos slot (giữ trống, fill khi có user)
- Newsletter signup (Buttondown hoặc Mailchimp free)

**Task 0.5 — Comparison content**
- Blog post 1: "FluxFiles vs Spatie Media Library — when to use which"
- Blog post 2: "Migrating from unisharp/laravel-filemanager to FluxFiles"
- Blog post 3: "Self-hosted alternative to Cloudinary in PHP"
- Mỗi post 800-1500 từ, có code example thật, không nói xấu đối thủ
- SEO: focus 1 keyword/post, internal linking

**Task 0.6 — Decision: Rebrand hay giữ tên?**
- Research SEO collision: search "fluxfiles", "flux files", trên Google/DuckDuckGo
- Đánh giá impact của Flux AI model + idkwhoami/flux-files trên ranking
- 2 lựa chọn:
  - **Giữ FluxFiles:** mua thêm domain SEO (getfluxfiles.com, fluxfiles.io), viết content chiếm SERP
  - **Rebrand:** chọn tên mới (FluxStorage, AssetForge, MediaFlux, etc.), migrate trong khi user base còn nhỏ
- Quyết định: timeline 1 tuần research, document lý do, không lùi sau đó

### Out of scope (đừng làm trong phase này)
- Tính năng mới
- Refactor code
- Performance optimization
- Tests mới

### Deliverables
- [ ] Demo site live + URL trong README
- [ ] Docker image trên Docker Hub
- [ ] Video YouTube + embedded
- [ ] Landing page deployed
- [ ] 3 blog posts published
- [ ] Decision document về tên (Notion/Markdown)

### Success metrics
- GitHub stars: từ 1 lên **≥ 50**
- Packagist installs: từ 1 lên **≥ 20**
- Demo site có ≥ **100 unique visitors** trong tuần đầu sau launch
- Có ≥ **3 người ngoài** comment/issue trên GitHub

### Risks & mitigations
| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Demo site bị abuse upload junk | Cao | Trung | Cron reset 1h + rate limit aggressive + file size limit 5MB |
| Docker image phình to >500MB | Trung | Thấp | Multi-stage build + alpine base + strip dev dependencies |
| Video làm xong nhưng không ai xem | Trung | Cao | Cross-post Twitter, Reddit r/PHP, r/laravel, HN Show |
| Quyết định rebrand quá muộn sau khi có user | Thấp | Rất cao | Quyết trong tuần 1 phase 0 |

### Effort estimate
- Demo site: 2-3 ngày
- Docker: 1 ngày
- Video: 2 ngày (script + record + edit)
- Landing page: 3-4 ngày
- Blog posts: 4-5 ngày (3 posts × 1.5 ngày)
- Rebrand decision: 1-2 ngày research
- **Tổng:** ~14-18 ngày, buffer 2-3 tuần là vừa

---

## Phase 1 — Transform Pipeline + Tus Protocol

**Thời lượng:** 5-6 tuần  
**Goal:** Đóng gap critical với Cloudinary/Directus về asset pipeline. Đây là USP rõ ràng nhất có thể positioning.

### Strategic rationale
Hiện tại FluxFiles chỉ generate 3 variant cố định (thumb/medium/large) khi upload. Đây là pattern legacy. Modern stack expect URL-based transformations on-the-fly + resumable upload qua Tus. Không có 2 thứ này thì không thể claim "modern" trong content marketing.

### Scope chi tiết

**Task 1.1 — On-the-fly URL transformations**
- Endpoint mới: `GET /api/fm/transform`
- Params support: `w`, `h`, `q` (1-100), `fit` (cover/contain/inside/outside), `fm` (auto/jpg/png/webp/avif), `dpr` (1/2/3), `blur`, `rotate`, `flip` (h/v/both)
- Auth qua JWT giống endpoint khác
- Response: stream image binary trực tiếp, không JSON wrap
- Headers: đúng Content-Type, `Cache-Control: public, max-age=31536000, immutable`, `Vary: Accept`
- Validation: reject combination vô lý (w=10000, q=999)

**Task 1.2 — Format=auto negotiation**
- Đọc `Accept` header
- Priority: `image/avif` > `image/webp` > `image/jpeg`
- Fallback graceful nếu Intervention Image không hỗ trợ AVIF
- Test với 5 browser: Chrome, Safari, Firefox, Edge, mobile Safari iOS

**Task 1.3 — Preset system**
- Config file `config/transforms.php` với mode `all|none|presets`
- Default mode `presets` cho production safety
- Preset spec: `{w, h, fit, fm, q, additional_transforms}`
- Endpoint accept `?preset=avatar` thay raw params
- Trong mode `presets`, raw params trả 403
- Admin UI (basic) liệt kê preset hiện có

**Task 1.4 — Variant cache system**
- Cache path: `_variants/{disk}/{hash[0:2]}/{hash[2:4]}/{full-hash}.{ext}`
- Hash key: `sha1(disk + key + serialize(params) + file_mtime)`
- Check cache trước khi process
- Cache hit → stream từ disk, skip Intervention Image hoàn toàn
- Cache miss → generate, save, stream
- Cron command `bin/fluxfiles cleanup-variants` xóa variant orphan (file gốc đã xóa) hoặc không truy cập >30 ngày
- Atime tracking qua last-modified của variant file

**Task 1.5 — Tus protocol server**
- Composer require `ankitpokhrel/tus-php`
- Endpoint `/api/fm/tus/{any}` mount Tus server
- Storage adapter map vào FluxFiles disk hiện tại
- Event hook `tus-server.upload.complete` trigger pipeline (EXIF, variants, AI tag, webhook)
- Update SDK frontend: file >50MB dùng tus-js-client, <50MB giữ XHR
- Resume capability test: ngắt mạng giữa upload, refresh, continue
- CORS preflight đúng cho Tus headers

**Task 1.6 — EXIF/IPTC/ICC auto-extract**
- Library: `lsolesen/pel` hoặc PHP built-in `exif_read_data()`
- Trên upload, extract: camera, lens, ISO, aperture, shutter, taken_at, GPS, orientation
- Lưu vào metadata sidecar (local) hoặc S3 object metadata (cloud)
- Auto-rotate ảnh nếu orientation ≠ 1 (lưu vào original, regenerate variants)
- GPS coordinates decode ra `{lat, lng}` format

**Task 1.7 — Privacy default cho EXIF**
- Strip GPS khỏi variants public-facing tự động
- Giữ GPS trong metadata internal (admin xem được)
- Config option `transform.strip_gps_from_variants = true` (default)
- Document rõ trong README + privacy section

**Task 1.8 — Focal points**
- Thêm field `focal_point: {x: 0-1, y: 0-1}` vào metadata
- Endpoint `POST /api/fm/focal-point` với `{disk, key, x, y}`
- UI: trong image detail view, click trên ảnh để set focal point
- Transform logic: nếu có focal point + crop, crop quanh điểm đó thay center
- Default về `{0.5, 0.5}` (center) nếu chưa set

### Out of scope
- Advanced Sharp passthrough (để Phase sau, security boundary phức tạp)
- AI smart crop (Phase 3)
- Video transformations
- PDF preview generation

### Deliverables
- [ ] `/api/fm/transform` endpoint full functional
- [ ] Preset config + admin doc
- [ ] Variant cache với cleanup cron
- [ ] Tus server tích hợp, resume tested
- [ ] EXIF auto-extract working trên 5 ảnh test (DSLR, iPhone, Android, screenshot, scanner)
- [ ] Focal point UI + API
- [ ] Updated SDK với tus-js-client
- [ ] Blog post "Self-hosted Cloudinary alternative in PHP"
- [ ] Demo site update với feature mới

### Success metrics
- Blog post launch HN: ≥ **top 30 trang chủ**
- GitHub stars sau Phase 1: ≥ **200**
- Packagist installs: ≥ **50**
- Có ≥ **1 PR contribution thật** từ external dev
- Demo site có URL `?preset=` được show trên Twitter ít nhất 1 lần

### Risks & mitigations
| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Variant cache phình storage | Cao | Trung | Hard limit per-user, cron cleanup, monitoring |
| First-request slow (sync generate) | Cao | Cao | Document rõ, optional pre-warm queue, accept tradeoff |
| Tus integration phá chunk upload cũ | Trung | Cao | Feature flag, rollback path, test cả 2 flow |
| EXIF library security CVE | Thấp | Cao | Pin version, monitor advisories, có exif disable flag |
| Sharp/Intervention v3 incompat một số format | Trung | Trung | Fallback chain: AVIF → WebP → JPG → PNG, test matrix đầy đủ |

### Effort estimate
- Transform endpoint + params: 1 tuần
- Preset system + format negotiation: 3-4 ngày
- Variant cache + cleanup: 3-4 ngày
- Tus integration: 1-1.5 tuần
- EXIF extract + auto-rotate: 3-4 ngày
- Focal points: 2-3 ngày
- Docs + blog + demo update: 3-4 ngày
- **Tổng:** ~5-6 tuần

### Dependencies
- Phase 0 hoàn tất (cần kênh marketing cho blog post)
- Composer package `ankitpokhrel/tus-php` >= v2.0
- Intervention Image v3 confirmed work với AVIF

---

## Phase 2 — Laravel Ecosystem

**Thời lượng:** 4 tuần  
**Goal:** Stop bleeding user vào tay Spatie Media Library. Mở rộng từ "iframe file manager" sang "native Laravel media library".

### Strategic rationale
80% dev PHP file manager là dev Laravel. Hiện họ chọn Spatie thay vì FluxFiles vì Spatie có Eloquent integration. Có integration này = mở thị trường 40M+ downloads của Spatie. Đây là phase ROI cao nhất về growth.

### Scope chi tiết

**Task 2.1 — Eloquent trait `HasFluxFiles`**
- Trait expose API: `$model->attachFile($file)->toCollection('avatars')`
- Polymorphic relation: 1 model có nhiều file, 1 file thuộc 1 model
- Migration tự động: bảng `fluxfiles_attachments` (model_type, model_id, file_uuid, collection, sort_order)
- Eloquent relationship: `$user->files()`, `$user->files('avatars')`
- Helper methods: `firstFile($collection)`, `latestFile($collection)`, `fileCount($collection)`

**Task 2.2 — Queue-based variant generation**
- Move variant generation từ sync sang queue
- Job class `GenerateVariantsJob` push vào queue trên upload
- Support Redis, database, sync (test) queue drivers
- Upload response trả ngay với placeholder URL (LQIP base64)
- WebSocket/SSE notify khi variants ready (optional)
- Retry policy: 3 lần, exponential backoff

**Task 2.3 — Filament v5 native component**
- Package `fluxfiles/filament`
- Custom field `FluxFilesField` cho Filament form
- Resource panel hiển thị file gallery
- Bulk actions: delete, move, tag
- Không dùng iframe — render trực tiếp trong Filament UI
- Tận dụng Filament's modal, notification, action system

**Task 2.4 — Migration guides**
- Guide 1: "From Spatie Media Library to FluxFiles"
  - Artisan command `fluxfiles:migrate-from-spatie` tự động map data
  - Compatibility matrix: feature nào Spatie có mà FluxFiles không, ngược lại
  - Honest assessment: khi nào nên giữ Spatie
- Guide 2: "From unisharp/laravel-filemanager to FluxFiles"
  - Tương tự, mapping path conventions
  - Drop-in replacement cho 80% use case
- Guide 3: "From Filament native upload to FluxFiles + Filament"
  - Lợi ích: multi-cloud, AI tagging, cross-disk

**Task 2.5 — Artisan commands**
- `fluxfiles:install` — copy config, migration, generate secret
- `fluxfiles:cleanup-variants` — variant cache cleanup
- `fluxfiles:audit-export` — export audit log ra CSV
- `fluxfiles:health` — kiểm tra config, disk access, AI key, JWT secret strength
- `fluxfiles:tinker-token` — generate test token cho dev local

**Task 2.6 — Laravel best practices**
- Service provider auto-discovery
- Config publishing đầy đủ
- Test suite với Pest/PHPUnit cho Laravel package
- Compatibility: Laravel 10, 11, 12, 13
- Document upgrade path khi Laravel release version mới

### Out of scope
- Filament v4 backward compat (sunset)
- Nova adapter (separate phase nếu có demand)
- Inertia.js specific integration

### Deliverables
- [ ] `fluxfiles/laravel` package v2 với Eloquent trait
- [ ] `fluxfiles/filament` package mới
- [ ] Queue-based variant generation
- [ ] 3 migration guides
- [ ] 5 artisan commands
- [ ] Test suite Laravel 11+ pass
- [ ] Blog post "Spatie vs FluxFiles — an honest comparison"
- [ ] Demo Laravel app trên GitHub (https://github.com/thai-pc/fluxfiles-laravel-demo)

### Success metrics
- Laravel package installs: từ ~10 lên **≥ 100**
- GitHub stars Laravel package: ≥ **30**
- Có ≥ **1 dev viết blog post review** (tiếng Anh hoặc Việt)
- Filament package installs: ≥ **30**
- Có ≥ **3 issue/discussion thật** từ external user

### Risks & mitigations
| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Eloquent integration conflict với Spatie nếu user dùng cả 2 | Trung | Trung | Namespace rõ ràng, document coexistence pattern |
| Queue drivers khác nhau gây inconsistency | Trung | Cao | Test với sync, database, Redis trên CI |
| Filament v5 API breaking trong minor version | Cao | Trung | Pin Filament version, document breaking |
| Migration tool corrupted data của user | Thấp | Rất cao | Dry-run mode mặc định, backup warning, test với 10 fixture |

### Effort estimate
- Eloquent trait + migration: 1 tuần
- Queue-based variants: 4-5 ngày
- Filament component: 1 tuần
- Migration guides + tools: 1 tuần
- Artisan commands: 2-3 ngày
- Test suite + docs: 3-4 ngày
- **Tổng:** ~4 tuần

### Dependencies
- Phase 1 hoàn tất (variant generation logic)
- Filament v5 stable release (đã có)

---

## Phase 3 — First-mover MCP + AI

**Thời lượng:** 4 tuần  
**Goal:** Chiếm tiêu đề "First MCP-native self-hosted file manager". Window này đang mở năm 2026, 6 tháng nữa sẽ đóng.

### Strategic rationale
MCP (Model Context Protocol) đang là chuẩn mới để LLM tương tác với external systems. Directus đã có MCP native. Trong PHP file manager segment, **chưa ai làm**. First-mover advantage có thể đem lại burst traffic lớn nếu launch đúng cách.

### Scope chi tiết

**Task 3.1 — MCP server stdio transport**
- Implementation theo MCP spec mới nhất (pin version)
- Tools expose ban đầu:
  - `list_files(disk, path)` — list directory
  - `search_files(query, disk?, limit?)` — full-text search
  - `read_metadata(disk, key)` — get file metadata
  - `get_file_url(disk, key, preset?)` — get transformed URL
  - `get_quota(disk)` — current usage
- Auth qua JWT trong env var `FLUXFILES_MCP_TOKEN`
- Scope theo `prefix` claim giống web API
- Logging stderr, không stdout (stdout là protocol channel)
- Document setup với Claude Desktop config

**Task 3.2 — MCP server HTTP transport**
- Cho remote agents (Cursor, Continue.dev, custom)
- Endpoint `/api/fm/mcp`
- JSON-RPC over HTTP với SSE streaming
- Auth same JWT
- Rate limit riêng cho MCP traffic
- CORS config cho agent IDE

**Task 3.3 — MCP write tools (gated)**
- Optional, enable qua config:
  - `upload_file(disk, path, content_base64)` — chỉ với perm `write`
  - `delete_file(disk, key)` — chỉ với perm `delete`
  - `move_file(disk, from, to)` — chỉ với perm `write`
  - `ai_tag_file(disk, key)` — chỉ với perm `write`
- Default disabled trong production
- Mỗi tool có safety: confirm pattern, dry-run flag

**Task 3.4 — AI smart crop**
- Endpoint `POST /api/fm/ai-smart-crop`
- Body: `{disk, key, aspect_ratio}` (e.g. "1:1", "16:9", "4:5")
- Gửi ảnh lên Claude/OpenAI vision API
- Prompt: "Identify the main subject. Return normalized coordinates {x, y, w, h} for optimal crop to {aspect_ratio}"
- Parse response, validate coords trong [0, 1]
- Set focal point + suggest crop region
- UI: nút "Smart crop" trong image editor

**Task 3.5 — AI bulk operations**
- Endpoint `POST /api/fm/ai-bulk`
- Body: `{action: 'tag'|'describe'|'detect-duplicates', keys: [...]}`
- Run qua queue, progress streamed qua SSE
- UI: select multi → "AI tag all" → progress bar realtime
- Duplicate detection: similarity score qua image embedding (CLIP-like)
- Cost estimate hiển thị trước khi confirm

**Task 3.6 — MCP launch campaign**
- Blog post: "Claude can now manage your self-hosted files"
- Video demo 2 phút: Claude Desktop → ask về files → FluxFiles MCP trả response
- Submit Hacker News (Tuesday/Wednesday morning PT)
- Reddit: r/selfhosted, r/LocalLLaMA, r/PHP, r/laravel
- Twitter thread với screenshots
- Submit Anthropic's MCP server registry (nếu có)
- Email Vietnamese dev community

### Out of scope
- MCP client (FluxFiles consume MCP từ outside) — không scope
- Custom AI models (chỉ wrap Claude/OpenAI)
- Voice/audio interactions

### Deliverables
- [ ] MCP server stdio + HTTP
- [ ] Tools read-only + write-gated
- [ ] AI smart crop endpoint + UI
- [ ] Bulk AI operations với SSE progress
- [ ] Launch blog post + video
- [ ] Submitted: HN, Reddit (×4), Twitter thread
- [ ] MCP server đăng ký Anthropic registry

### Success metrics
- HN launch: **top 10** trang chủ ≥ 4 tiếng
- GitHub stars: từ ~200 lên **≥ 1000** trong tuần sau launch
- MCP package installs (nếu tách): ≥ **100**
- Có ≥ **1 SaaS founder reach out** hỏi BYOB integration
- Có ≥ **5 user reach out** dùng FluxFiles với Claude Desktop

### Risks & mitigations
| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| MCP spec thay đổi breaking sau launch | Cao | Cao | Pin spec version, có upgrade plan documented |
| AI smart crop cost cao | Trung | Trung | Cache results, rate limit per user, cost cap config |
| HN launch fail (không lên trang chủ) | Trung | Cao | Backup plan: Reddit + Twitter campaign, repost sau 1 tuần |
| MCP write tools bị abuse | Trung | Rất cao | Default disabled, perm gating, audit log mọi action |
| Race condition concurrent AI requests | Trung | Trung | Queue serialize per user, timeout 30s |

### Effort estimate
- MCP stdio: 1 tuần
- MCP HTTP: 4-5 ngày
- AI smart crop: 3-4 ngày
- Bulk AI ops + SSE: 1 tuần
- Launch material (blog, video, posts): 1 tuần
- **Tổng:** ~4 tuần

### Dependencies
- Phase 1 hoàn tất (focal point system, transform pipeline)
- AI provider key (đã có Claude/OpenAI integration)
- HN account có karma đủ để submit

---

## Phase 4 — Differentiation Deep

**Thời lượng:** 4-5 tuần  
**Goal:** Build moat thực sự ở features dev enterprise/team expect, mà các đối thủ OSS khác không có.

### Strategic rationale
Sau Phase 3 đã có visibility burst, giờ phải có depth để giữ user. Phase này build features mà sau khi dev evaluate sẽ nói "ok, FluxFiles serious cho team work, không phải toy".

### Scope chi tiết

**Task 4.1 — Folders as first-class entities**
- Folders có metadata riêng: title, description, cover image, tags, default permissions
- Endpoint mới:
  - `GET /api/fm/folder?disk=&path=` — get folder metadata
  - `PUT /api/fm/folder` — update folder metadata
  - `DELETE /api/fm/folder` — delete folder (cascade to files)
- Sidecar `_fluxfiles/folders.json` (local) hoặc S3 object metadata
- UI: click folder → drawer hiện metadata editor
- Folder cover image trong list view

**Task 4.2 — File UUID stable URLs**
- Mỗi file có UUID v4 stable, lưu trong metadata khi upload
- Lookup table `_fluxfiles/uuid-map.json` (local) hoặc S3 metadata field
- Endpoint mới:
  - `GET /api/fm/asset/{uuid}` — redirect 302 đến current path
  - `GET /api/fm/asset/{uuid}/transform?preset=` — direct transform
- Lợi: rename/move không break external links
- Migration: backfill UUID cho file cũ qua artisan command

**Task 4.3 — Webhooks outbound**
- Config:
  ```php
  'webhooks' => [
      ['url' => '...', 'events' => [...], 'secret' => '...'],
  ]
  ```
- Events: `file.uploaded`, `file.deleted`, `file.renamed`, `file.moved`, `folder.created`, `quota.exceeded`, `ai_tag.completed`, `transform.generated`
- HMAC-SHA256 signature header `X-FluxFiles-Signature`
- Retry policy: 3 lần, exponential backoff
- Queue-based, không block main request
- Admin UI quản lý webhook + xem delivery log

**Task 4.4 — Custom metadata schema**
- Config define field types:
  ```php
  'metadata_schema' => [
      'copyright' => ['type' => 'string', 'required' => true],
      'license'   => ['type' => 'enum', 'options' => ['CC0', 'CC-BY', 'Proprietary']],
      'expires'   => ['type' => 'datetime'],
      'reviewed'  => ['type' => 'boolean', 'default' => false],
  ]
  ```
- Field types support: string, text, enum, boolean, datetime, number, json
- UI tự render form theo schema
- API validate khi save
- Search filter theo custom field
- Schema migration: thêm field không phá data cũ

**Task 4.5 — Filter-based permissions**
- Mở rộng JWT claims:
  ```json
  {
    "perms": ["read", "write"],
    "prefix": "company/",
    "filters": {
      "read":   {"metadata.reviewed": true},
      "delete": {"metadata.copyright_owner": "{{user.id}}"}
    }
  }
  ```
- Filter engine evaluate condition trên file metadata
- Operators: `=`, `!=`, `in`, `not_in`, `contains`, `>`, `<`
- Template variables: `{{user.id}}`, `{{user.role}}`, `{{now}}`
- Test với 10+ scenarios phức tạp

**Task 4.6 — Image editor expand**
- Thêm features inline:
  - Rotate 90°/180°/270°
  - Flip horizontal/vertical
  - Brightness/contrast/saturation slider
  - Sharpen filter
  - Grayscale conversion
- Save mode: overwrite vs save-as-new (như Directus)
- Save-as-new inherit folder + base filename, suffix `-edited-N`
- Save-as-new KHÔNG inherit custom metadata (title, tags) — empty start

**Task 4.7 — Activity log per file**
- Tách audit log thành per-file timeline
- Endpoint `GET /api/fm/activity?disk=&key=`
- Track: uploaded, viewed (presign URL generated), downloaded, renamed, moved, edited, tagged, deleted, restored
- UI: drawer "Activity" trong file detail
- Filter theo user, action, date range

### Out of scope
- Comments per file (Phase 5)
- @mentions
- Email notifications
- External federation

### Deliverables
- [ ] Folder entity với metadata + UI
- [ ] UUID stable URLs + backfill command
- [ ] Webhooks system với admin UI
- [ ] Custom metadata schema + validation
- [ ] Filter-based permissions trong JWT
- [ ] Image editor expanded
- [ ] Per-file activity log + UI
- [ ] Case study blog post (nếu có user thật)

### Success metrics
- Có ≥ **1 case study production** (dù nhỏ)
- Dependents Packagist: ≥ **5**
- GitHub stars: ≥ **1500**
- Có ≥ **10 issue/discussion** từ external user (engagement)

### Risks & mitigations
| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Filter permission engine bug → security hole | Trung | Rất cao | Extensive test suite, security review, public bug bounty soft launch |
| Custom metadata schema over-engineered | Cao | Trung | Start 4 field types, add khi có demand thật |
| UUID migration phá data cũ | Trung | Cao | Dry-run mặc định, backup warning, rollback path |
| Webhook delivery bị spam | Thấp | Trung | Rate limit per URL, blacklist failure URLs |

### Effort estimate
- Folders entity: 1 tuần
- UUID URLs + migration: 4-5 ngày
- Webhooks: 1 tuần
- Custom metadata schema: 1 tuần
- Filter-based permissions: 4-5 ngày
- Image editor expand: 3-4 ngày
- Activity log: 3-4 ngày
- **Tổng:** ~4-5 tuần

### Dependencies
- Phase 2 (queue system) cho webhooks async
- Phase 3 (visibility) để case study có chance happening

---

## Phase 5 — Collaboration & Polish

**Thời lượng:** 4-6 tuần  
**Goal:** Mở rộng từ "single dev tool" sang "team-ready tool". Phase này mở cửa thị trường lớn hơn.

### Strategic rationale
Solo dev market đã cover ở Phase 1-4. Phase 5 mở SaaS founder, team, agency, content creator market. Đây là thị trường có budget thật, có thể dẫn tới sponsor/paid support sau này.

### Scope chi tiết

**Task 5.1 — Public share links**
- Endpoint `POST /api/fm/share`
- Body: `{disk, key, expires_at?, password?, max_downloads?, allow_view?}`
- Trả về `{share_url, share_token, qr_code_svg}`
- Endpoint public `GET /s/{token}` — render preview + download
- Endpoint `DELETE /api/fm/share/{id}` revoke link
- Admin UI: list shared links, status, expiration

**Task 5.2 — Trash/Recycle bin**
- Soft delete thay vì hard delete (config option)
- Retention period configurable (default 30 ngày)
- Endpoint `GET /api/fm/trash` — list deleted
- Endpoint `POST /api/fm/restore` — restore từ trash
- Auto cleanup cron sau retention expire
- UI: tab "Trash" trong sidebar
- Restore preserve original path

**Task 5.3 — Comments per file (basic)**
- Endpoint `POST/GET/DELETE /api/fm/comments?disk=&key=`
- Markdown support trong comment
- User attribution qua JWT sub
- Notification placeholder (Phase sau có email/webhook)
- UI: drawer "Comments" trong file detail

**Task 5.4 — Plugin/hook system**
- Hook registration API:
  ```php
  FluxFiles::on('beforeUpload', $callback);
  FluxFiles::on('afterUpload', $callback);
  // ...
  ```
- Hook list: beforeUpload, afterUpload, beforeDelete, afterDelete, beforeTransform, afterTransform, metadataExtracted, beforeShare, afterShare
- Plugin manifest format
- Plugin discovery: scan `plugins/` directory
- Document API stable promise

**Task 5.5 — Reference plugins**
- Plugin 1: Background remover (rembg local hoặc remove.bg API)
- Plugin 2: Virus scan (ClamAV integration)
- Plugin 3: Watermark on upload (configurable text/image)
- Plugin 4: HEIC to JPEG auto-conversion
- Mục đích: eat-your-own-dogfood, show off plugin API

**Task 5.6 — Adapter mở rộng**
- Svelte adapter (`@fluxfiles/svelte`)
- Next.js example với App Router + Server Actions
- Astro integration example
- Pin priority: Svelte > Astro > Next.js (theo growth trend)

**Task 5.7 — Realtime updates**
- SSE endpoint `/api/fm/events?disk=&path=`
- Push events khi: new file, deleted, renamed trong path
- UI: 2 tab cùng folder thấy nhau realtime
- Optional, behind feature flag (resource intensive)

**Task 5.8 — Polish & docs cuối roadmap**
- Comprehensive docs site (VitePress hoặc Mintlify)
- API reference auto-generated từ code
- 5 tutorials end-to-end: SaaS setup, blog CMS, ecommerce assets, team workspace, AI photo library
- Performance benchmark vs competitors
- Security audit (paid hoặc community)

### Out of scope
- Real-time collaborative editing (Google Docs-like)
- Video calls/meetings
- File chat (chỉ comments)
- Full DAM enterprise features (rights management chi tiết)

### Deliverables
- [ ] Share links với password/expiry
- [ ] Trash + auto-cleanup
- [ ] Comments system
- [ ] Plugin API + 4 reference plugins
- [ ] Svelte adapter + Next.js example
- [ ] SSE realtime (optional flag)
- [ ] Docs site mới
- [ ] 5 tutorials end-to-end
- [ ] Security audit document

### Success metrics
- GitHub stars: ≥ **2000**
- Plugin third-party (không phải bạn viết): ≥ **3**
- GitHub contributors: ≥ **5**
- Discord/Slack community: ≥ **50 thành viên active**
- Có ≥ **2 case study production** (1 SaaS, 1 agency/team)
- Có ≥ **1 sponsor** (dù nhỏ) hoặc paid support inquiry

### Risks & mitigations
| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Plugin API bị abuse load malicious code | Trung | Rất cao | Sandbox, manifest review, signed plugins option |
| Share links public bị spam upload junk | Trung | Trung | Rate limit, anonymous read-only by default |
| Realtime SSE drain resources | Cao | Trung | Feature flag, connection limit per user, document warning |
| Docs site rebuild tốn quá nhiều time | Trung | Trung | Time-box 1 tuần, scope đầu vào 70%, polish sau |

### Effort estimate
- Share links: 1 tuần
- Trash + comments: 1 tuần
- Plugin system + 4 plugins: 1.5-2 tuần
- Svelte + Next.js adapter: 4-5 ngày
- Realtime SSE: 3-4 ngày
- Docs + tutorials: 1.5 tuần
- **Tổng:** ~4-6 tuần

### Dependencies
- Phase 4 (folder entity, UUID URLs) cho share links
- Phase 3 (visibility) cho community growth

---

## Cross-cutting Activities (xuyên suốt 6 tháng)

Đây là việc **không thuộc phase nào** nhưng phải làm liên tục.

### Marketing rhythm

- **Mỗi 2 tuần:** 1 changelog post trên blog + Twitter/Bluesky/Mastodon
- **Mỗi phase release:** Minor version với release notes + demo GIF
- **Mỗi tháng:** 1 blog post chuyên sâu (tutorial, comparison, case study)
- **Mỗi quarter:** 1 deep technical post + cross-post Dev.to, Medium, Hashnode

### Competitive intelligence

- **Mỗi tuần (30 phút):** Check GitHub releases của uppy, spatie/laravel-medialibrary, mwguerra/filemanager, directus
- **Mỗi tháng (1 giờ):** Update internal competitive matrix doc, đánh giá có gì mới cần priority
- **Setup RSS feed reader** cho blog của Cloudinary, Filerobot, Transloadit, Anthropic

### Community building

- **Tạo Discord server** từ Phase 1, dù chỉ có 5 người
- **Office hours hàng tuần** 1 giờ trên Discord/Twitter Space để Q&A
- **GitHub Discussions** active reply trong 48h
- **Recognize contributor** trong release notes, README

### Analytics & feedback

- **Demo site:** Plausible Analytics (không Google) để track funnel
- **Docs site:** track top searched queries → identify gap
- **GitHub:** monitor issue/PR velocity, response time
- **Monthly retrospective** với chính mình: what worked, what didn't

### Backup & resilience

- **Code:** GitHub + GitLab mirror
- **Docker images:** Docker Hub + GitHub Container Registry
- **Domain:** DNS với 2 providers (CloudFlare + Vercel)
- **Demo site:** automated backup hằng ngày
- **Bus factor mitigation:** document deployment, secrets management, contact list

---

## Stop Conditions — biết khi nào pause feature

Đây là phần dev solo dễ bỏ qua, nhưng quan trọng nhất để tránh burnout.

### Stop condition 1: Adoption không tăng

- **Sau Phase 1**, nếu Packagist installs < 50 → **pause Phase 2 trong 2 tuần**, dồn vào marketing
- **Sau Phase 3**, nếu GitHub stars < 200 → **pause Phase 4 trong 1 tháng**, focus docs + content
- **Sau Phase 4**, nếu chưa có case study thật → **pause Phase 5 trong 2 tuần**, outreach cộng đồng

### Stop condition 2: Burnout signals

- Skip 2 ngày standup/commit liên tiếp không lý do
- Không trả lời issue >7 ngày
- Code quality drop (test fail tăng, bug regression)
→ **Pause 2 tuần ship feature**, chỉ maintain + reply issue. Recovery quan trọng hơn velocity.

### Stop condition 3: Tech debt threshold

- Test coverage < 50%
- Open critical bug > 5
- Performance regression detected
→ **Dành 1 sprint cho cleanup**, không feature mới.

### Stop condition 4: Strategic pivot

- Nếu có inquiry từ enterprise/SaaS đáng giá → có thể pivot priorities
- Nếu Anthropic/Vercel/Cloudflare reach out partnership → re-evaluate roadmap
- Major MCP/standard change → pause để align

---

## Risk Register tổng

| Risk | Phase | Likelihood | Impact | Status |
|------|-------|-----------|--------|--------|
| Burnout dev solo | Tất cả | Cao | Rất cao | Mitigation: stop conditions |
| Bus factor = 1 | Tất cả | Cao | Rất cao | Document everything, find 1 co-maintainer trong Phase 3 |
| Tên FluxFiles SEO collision | Phase 0 | Cao | Trung | Quyết định rebrand tuần 1 |
| MCP spec breaking change | Phase 3+ | Trung | Cao | Pin version, upgrade plan |
| Adoption không tăng dù feature đủ | Phase 2-4 | Trung | Cao | Stop conditions + marketing pivot |
| Security CVE | Tất cả | Trung | Rất cao | Security review mỗi quarter |
| Anthropic/competitor ra feature trùng | Phase 3 | Cao | Trung | Differentiate by self-host + multi-cloud |
| Cost AI API tăng đột biến | Phase 3+ | Trung | Trung | Rate limit, cost cap, fallback model |

---

## Resource & Tools Required

### Infrastructure
- Domain: fluxfiles.dev (~$15/năm)
- Demo site hosting: VPS DigitalOcean/Hetzner ($5-10/tháng)
- CDN: Cloudflare free tier
- Docker Hub: free tier đủ
- GitHub: free tier
- Email: ProtonMail / Fastmail ($5/tháng)

### Software/Services
- Plausible Analytics ($9/tháng)
- Newsletter: Buttondown free tier
- Docs hosting: Vercel/Netlify free tier
- Video edit: ScreenStudio ($89 one-time) hoặc Descript free
- Design: Figma free
- AI API: Anthropic + OpenAI (~$50-100/tháng tùy usage)

### Estimated total monthly burn
- Phase 0-1: ~$20/tháng
- Phase 2-3: ~$50/tháng (AI usage tăng)
- Phase 4-5: ~$80-100/tháng (demo traffic + AI)

### Time commitment
- Phase 0: 2-3 tuần full-time hoặc 4-5 tuần part-time
- Phase 1-5: 4-6 tuần each, full-time hoặc 8-10 tuần part-time
- **Tổng 6 tháng full-time** hoặc **9-12 tháng part-time** (15-20h/tuần)

---

## Tracking & Reporting

### Weekly check-in template
```
Week N - YYYY/MM/DD
Phase: [current phase]

✅ Done this week:
- ...

🚧 In progress:
- ...

📌 Next week:
- ...

📊 Metrics:
- GitHub stars: X (+Y)
- Packagist installs: X (+Y)
- Demo visitors: X

🚨 Blockers:
- ...

💡 Learnings:
- ...
```

### Monthly retrospective
- What worked well?
- What didn't?
- Should I adjust roadmap?
- Energy level (1-10)?
- Stop condition triggered?

### Quarterly strategic review
- Hit goals của quarter?
- Roadmap còn relevant không?
- Competitive landscape đổi gì?
- Pivot needed?

---

## Appendix A: Competitive Landscape Snapshot

| Đối thủ | Segment | FluxFiles position |
|---------|---------|-------------------|
| unisharp/laravel-filemanager | Direct (Laravel) | Đua, phải vượt với Eloquent + modern stack |
| elFinder | Direct (legacy PHP) | Vượt về stack, học archive + watermark |
| mwguerra/filemanager | Direct (Filament) | Đua, phải có Filament native sớm |
| Spatie Media Library | Adjacent (Eloquent) | Coexist, position cho non-Eloquent + multi-cloud |
| Uppy + S3 | Indirect (DIY) | Compete bằng "full package" trải nghiệm |
| Directus Files | Aspirational | Học pattern, không compete trực tiếp |
| Cloudinary | Aspirational | Học URL transform, position self-host alternative |
| Filerobot | Aspirational | Học AI features + image editor |
| Nextcloud | Khác segment | Không compete, học share UX |

---

## Appendix B: First 14-day Sprint Plan (Phase 0)

**Week 1:**
- Day 1-2: Demo site setup, seed data, multi-user
- Day 3: Docker image build + push
- Day 4-5: Landing page draft
- Weekend: Rebrand decision research

**Week 2:**
- Day 1-2: Video script + record
- Day 3: Video edit + upload
- Day 4-5: Blog post 1 (Spatie comparison)
- Weekend: Cross-post + community announcement

**Week 3 (buffer):**
- Polish, fix bugs from demo
- Blog post 2-3
- Newsletter setup
- Launch on Twitter, HN Show, Reddit

---

## Sign-off

**Reviewed by:** _______________  
**Approved date:** _______________  
**Next review:** End of Phase 0 (~3 tuần sau approval)

---

*Tài liệu này là living document — update sau mỗi phase retrospective.*
