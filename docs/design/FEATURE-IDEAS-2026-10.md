# Đề xuất feature — 2026-10

> **Trạng thái (cập nhật 2026-10-23, đối chiếu code core-v0.2.100):** 9/10 ý tưởng đã
> ship; chỉ #5 còn ở dạng spec. Bảng dưới ghi bản phát hành và những chỗ bản đã ship
> **khác** với đề xuất gốc — nội dung đề xuất gốc ở phía dưới giữ nguyên để tham khảo,
> nên khi hai phần mâu thuẫn thì bảng này đúng.
>
> | # | Ý tưởng | Trạng thái | Ship khác đề xuất ở chỗ nào |
> |---|---|---|---|
> | 1 | tus resumable | **Đã ship** core-v0.2.97 (`e9410b9`); siết lại ở core-v0.2.100 | Phần dở dang lưu ở `<FLUXFILES_STORAGE_PATH>/tus/` trên **server** (không phải `_fluxfiles/uploads-partial/` trong storage) → multi-node cần thư mục chung hoặc sticky session. Mọi luật upload (quota, số file, trùng tên, owner_only, legal hold) kiểm tra ngay lúc **tạo**; upload gắn với `sub` + prefix + disk của token. Chỉ core standalone — proxy Laravel/WordPress không có `/tus`. Tự viết (`TusServer.php`), không dùng `tus-php`. |
> | 2 | Presigned POST | **Đã ship** core-v0.2.96 | Claim tên `allow_presign_put` (mặc định `true`; `false` → `403 presign_put_disabled`), không phải `presign_put`. R2 không có POST Object → `400 presign_post_unsupported`. |
> | 3 | `strip_exif` | **Đã ship** core-v0.2.96; zip/intake core-v0.2.97; cắt MPF/trailer core-v0.2.100 | Không re-encode bằng intervention mà cắt **lossless** ở mức container (giữ ICC + Orientation). Không có claim `strip_exif_keep_orientation` và không có badge "ảnh chứa GPS" trong panel chi tiết. |
> | 4 | Tìm file trùng | **Đã ship** core-v0.2.97 (`ce10ede`) | Endpoint là `GET /api/fm/duplicates` (không phải `/usage/duplicates`); băm SHA-256 trực tiếp (lọc theo size trước, tối đa 512 MB/lần), không dựa vào `file_hash`. Chưa có "file lâu không đụng tới". |
> | 5 | Nhiều prefix / token | **Chưa làm** — spec nháp `docs/design/MULTI-PREFIX-TOKEN-DESIGN.md`, chờ quyết định | — |
> | 6 | Retention | **Đã ship** core-v0.2.97 (`cf3d7a5`), trong module `legal-hold` | Không chạy "lazy" khi list: chạy bằng `POST /api/fm/retention/run` (mặc định `dry_run`), operator tự gọi từ scheduler. Rule là `{path, days, action: trash\|delete}`, tối đa 20 rule, 500 file/lần; cần quyền `audit` + `delete`. Không có `bin/fluxfiles retention:run`. |
> | 7 | Fixity | **Đã ship** core-v0.2.97 (`f4209f1`), trong module `audit-export` | Baseline SHA-256 riêng ở `<prefix>/_fluxfiles/fixity.json` (không dùng `file_hash`); tách thay đổi "có giải thích" (có trong audit log) với "không giải thích" (ghi audit `fixity_mismatch`); 1 GB/lần, chạy tiếp bằng cursor. Không bắn webhook riêng. |
> | 8 | MCP server | **Đã ship** `@fluxfiles/mcp` 0.1.0 (`917133e`); 0.1.1 chỉ cho https tới host không phải local | Tool: list/search/read/info/link/usage; ghi (`write_text_file`, `create_folder`) chỉ khi `FLUXFILES_MCP_WRITE=1`, trash khi thêm `FLUXFILES_MCP_DELETE=1`. Không có tool `share`. |
> | 9 | Poster PDF/video | **Đã ship** core-v0.2.97 (`6395ce9`) | Từ core-v0.2.100 claim `media_posters` **mặc định tắt** (opt-in); Ghostscript < 10.05.0 coi như không cài; gs/ffmpeg chạy với môi trường tối thiểu (`ProcessEnv::minimal()`). Tạo lúc upload, không lazy qua `/img`. |
> | 10 | UI OCR/Virus/Backup | **Đã ship** core-v0.2.96 | Virus có thêm `POST /api/fm/virus/scan` (quét file đã lưu). Backup không có dry-run. |
>
> Ký hiệu công sức: **S** ≈ 1–3 ngày, **M** ≈ 1–2 tuần, **L** > 2 tuần.

*(Nội dung gốc — bản nháp để review, trước khi làm.)*
**Trạng thái ban đầu:** chỉ là ý tưởng, chưa code gì. Mỗi mục đã được lọc qua bộ tiêu chí
của `docs/ROADMAP.md`: stateless, metadata nằm trong storage của khách, BYO-embed
nếu đã có OSS tốt, và chỉ thu phí khi không có OSS thay thế.
Đã đối chiếu để **không trùng** với các mục DONE/DROP trong ROADMAP.

## Tóm tắt ưu tiên

| # | Feature | Gói | Công sức | Vì sao nên làm |
|---|---|---|---|---|
| 1 | Upload resumable (giao thức tus) cho local/SFTP | Free | M | Gỡ trần `upload_max_filesize` của PHP; hiện chỉ S3 có multipart |
| 2 | Presigned POST với `content-length-range` | Free | S–M | Biến giới hạn size của presign PUT từ "khuyến nghị" thành bắt buộc |
| 3 | Claim `strip_exif` (xóa GPS/EXIF khi upload) | Free | S | Bảo vệ riêng tư; hiện chỉ optimize mới xóa EXIF |
| 4 | Tìm file trùng và gợi ý dọn dẹp | Free | S | Tận dụng hash sẵn có; tăng giá trị cho usage dashboard |
| 5 | Một token nhiều prefix (thư mục riêng + thư mục chung) | Free | M | Nhu cầu team rất phổ biến, hiện phải mint 2 token |
| 6 | Retention policy (tự chuyển trash, tự xóa) | Enterprise | M | Hoàn thiện bộ compliance cùng legal hold |
| 7 | Kiểm tra fixity định kỳ (phát hiện hỏng/sửa bytes) | Enterprise | S–M | Dùng lại `file_hash`; khách lưu trữ và pháp lý cần |
| 8 | MCP server `@fluxfiles/mcp` | Free (Pro cho bản hosted) | M | Cho AI agent thao tác file bằng JWT có phạm vi; kênh tiếp cận mới |
| 9 | Thumbnail cho PDF/video | Free | M | Lưới file hiện không có ảnh xem trước cho tài liệu và video |
| 10 | UI vận hành cho OCR / Virus / Backup | Paid (module sẵn) | S mỗi cái | Engine đã xong, chỉ thiếu UI; đây là phần doanh thu dễ thu nhất |

Gợi ý thứ tự: **3 → 2 → 10 → 4 → 1 → 5**, còn 6/7/8/9 tùy tín hiệu từ khách.

---

## 1. Upload resumable (tus) cho local/SFTP — Free, M

- **Vấn đề:** chunk upload hiện chỉ có S3 multipart (`ChunkUploader`). Trên disk local
  hoặc SFTP, mọi file đều phải lọt qua `upload_max_filesize`/`post_max_size`, và rớt
  mạng là phải upload lại từ đầu.
- **Đề xuất:** thêm endpoint `POST/PATCH/HEAD /api/fm/tus/*` theo giao thức tus 1.0
  (lõi + creation + termination). Mỗi part ghi vào
  `_fluxfiles/uploads-partial/<id>/`, trạng thái lưu ở
  `_fluxfiles/uploads-partial/<id>.json` (offset, size, tên, owner, hạn). Khi hoàn tất,
  file đi qua **đúng pipeline `upload()`** (ext, virus, DLP, quota, hold, variants).
- **Hợp kiến trúc:** trạng thái nằm trong storage, không cần DB hay queue. Dọn rác
  kiểu "lazy": request kế tiếp xóa các partial quá hạn.
- **Rủi ro:** quota phải tính cả partial; virus/DLP chỉ quét được khi đã đủ file (vẫn
  fail-closed). Mọi bước tus phải kiểm tra lại token.
- **Câu hỏi mở:** dùng thư viện `ankitpokhrel/tus-php` hay tự viết bản tối giản? (Bản
  tối giản ~400 dòng, ít phụ thuộc hơn.)

## 2. Presigned POST có `content-length-range` — Free, S–M

- **Vấn đề:** `FileManager::presign()` PUT không ký được kích thước (code đã ghi chú).
  Gửi `size: 1` rồi PUT 10 GB là vượt mọi giới hạn.
- **Đề xuất:** thêm `method: "POST"` trả về `{url, fields}` từ `createPresignedPost()`,
  với các điều kiện `content-length-range` theo `max_upload`, khóa cố định,
  `Content-Type` cố định theo đuôi file. Giữ PUT cho tương thích, nhưng thêm claim
  `presign_put=false` để operator tắt hẳn PUT.
- **Ảnh hưởng:** SDK/React/Vue và hai proxy phải hỗ trợ form POST. Tài liệu API.md.
- **Giá trị:** đóng nốt lỗ hổng "chỉ khuyến nghị" còn lại trong audit.

## 3. Claim `strip_exif` — Free, S

- **Vấn đề:** ảnh chụp bằng điện thoại chứa GPS. Chỉ khi optimize mới xóa EXIF.
- **Đề xuất:** claim `strip_exif` (mặc định `false`; demo bật sẵn) và
  `strip_exif_keep_orientation` (mặc định `true`, xoay ảnh theo orientation trước khi
  xóa). Chèn vào `upload()` ngay sau bước quét virus. JPEG/PNG/WebP dùng intervention
  re-encode, giữ nguyên chất lượng nếu có thể.
- **Thêm:** panel chi tiết hiện "Ảnh chứa vị trí GPS" kèm nút xóa (một lệnh ghi đè,
  tôn trọng hold/owner).
- **Phải làm kèm:** thêm claim vào `CONFIG.md`, cả 5 token builder, và i18n.

## 4. Tìm file trùng và gợi ý dọn dẹp — Free, S

- **Đề xuất:** `GET /api/fm/usage/duplicates` gom theo `file_hash` trong phạm vi
  prefix/owner, trả về các nhóm kèm dung lượng lãng phí. Bổ sung "file lớn nhất" và
  "lâu không đụng tới" (theo `modified`) vào usage dashboard. UI cho chọn nhiều file
  rồi đưa vào trash.
- **Lưu ý:** file upload qua chunk có hash rỗng → bỏ qua, hoặc tính hash lazy rồi
  cache vào metadata.

## 5. Một token nhiều prefix — Free, M

- **Vấn đề:** `prefix` chỉ là một chuỗi. Nhóm cần "thư mục của tôi + `shared/team-a`"
  thì phải mint 2 token và mở 2 iframe.
- **Đề xuất:** claim `prefixes: [{path, perms?, label}]`. UI hiện mỗi prefix như một
  "root" ảo ở sidebar. `Claims::scopePath()` nhận dạng `@<label>/…`.
- **Rủi ro:** đây là phần nhạy cảm bảo mật nhất (scoping). Cần một spec riêng cùng
  một vòng review: `isPathInScope`, audit, search, trash, quota phải nhận đúng root.
  Nên làm qua `/feature` để có đủ các bước planner, spec và reviewer.

## 6. Retention policy — Enterprise (bộ compliance), M

- **Đề xuất:** claim `retention_rules: [{path, action: trash|purge, after_days}]`.
  Áp dụng lazy: khi `list()`/`usage` chạm thư mục, gom các file quá hạn rồi xử lý
  theo lô nhỏ, có giới hạn mỗi request. Đi kèm endpoint "preview" để operator xem
  trước sẽ bị xóa những gì.
- **Ràng buộc:** legal hold luôn thắng retention (đã có `holdBlocking`). Mọi lần xóa
  do retention đều được ghi audit với action riêng.
- **Câu hỏi mở:** chạy lazy không đảm bảo đúng hạn nếu không ai mở thư mục đó → có
  thêm `bin/fluxfiles retention:run` cho cron của operator không? (Vẫn stateless, vì
  cron là của operator.)

## 7. Kiểm tra fixity định kỳ — Enterprise, S–M

- **Đề xuất:** `POST /api/fm/fixity/check` (admin) băm lại file theo lô rồi so với
  `file_hash`. File lệch thì ghi audit `fixity_mismatch` và bắn webhook (nếu có).
  Kết quả lần chạy lưu ở `_fluxfiles/fixity/last.json`.
- **Giá trị:** phục vụ lưu trữ số và pháp lý; bổ sung tự nhiên cho C2PA và legal hold.

## 8. MCP server `@fluxfiles/mcp` — Free (cân nhắc Pro), M

- **Đề xuất:** package Node bọc REST API thành các tool MCP: `list`, `search`, `read`
  (text), `upload`, `move`, `share` (nếu có module). Xác thực bằng JWT do operator mint
  với `role=viewer|editor`; tool chỉ làm được những gì token cho phép.
- **Hợp kiến trúc:** không thêm trạng thái server; dùng lại `packages/node` để mint.
- **Rủi ro:** prompt injection từ nội dung file → mặc định chỉ đọc; thao tác ghi cần
  cờ rõ ràng. Nên giới hạn kích thước `read`.

## 9. Thumbnail cho PDF/video — Free, M

- **Đề xuất:** khi upload (hoặc lazy qua `/api/fm/img`), PDF trang 1 → WebP bằng `gs`
  (đã dùng cho optimize), video frame ở giây thứ 1 → WebP bằng `ffmpeg` (tùy chọn,
  không có thì trả `501` như PDF). Cache trong `_variants/` như ảnh thường.
- **Bảo mật:** chạy `gs` với `-dSAFER` và argv dạng mảng như `PdfOptimizer`; `ffmpeg`
  có timeout và giới hạn input.

## 10. UI vận hành cho OCR / Virus / Backup — Paid (module sẵn), S mỗi cái

- **Hiện trạng:** ROADMAP ghi "engines complete … no operator UI yet".
- **Đề xuất:** làm theo đúng khuôn của AI Vision (modal, `proGate`, menu ngữ cảnh):
  - **OCR:** nút "Trích xuất văn bản", kết quả lưu vào caption/tags để search được.
  - **Virus:** badge "Đã quét/Sạch" trong panel chi tiết, cộng log `virus_blocked` trong
    activity.
  - **Backup:** màn hình "Đồng bộ sang disk khác" có dry-run.
- **Giá trị:** thu tiền được ngay trên phần code đã viết xong.

---

### Những thứ đã cân nhắc nhưng **không** đề xuất

- **WebDAV endpoint:** hấp dẫn (mount như ổ đĩa), nhưng khóa/thuộc tính của WebDAV
  cần trạng thái server và client desktop xác thực bằng JWT rất khó dùng. Nếu cần,
  BYO-embed (rclone serve / Nextcloud) hợp hơn.
- **Tìm kiếm ngữ nghĩa bằng embeddings:** cần lưu và truy vấn vector index → trái
  hướng "không DB" (trừ khi dùng `FLUXFILES_STORAGE_BACKEND=db` + pgvector; để sau).
- **Bình luận/chú thích trên file:** cộng tác thời gian thực cần trạng thái hoặc
  websocket; chỉ làm bản tối giản (sidecar comment) nếu khách yêu cầu.
