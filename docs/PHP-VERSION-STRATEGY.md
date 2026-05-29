# Chọn PHP version floor cho FluxFiles — phân tích chiến lược

Ngày: 2026-05-29
Tác giả: audit AI (Claude Opus 4.7)
Tài liệu liên quan: [PHP74-PORT-ANALYSIS.md](./PHP74-PORT-ANALYSIS.md)

---

## TL;DR

**Đề xuất: PHP 8.0+ làm baseline.**

Lý do ngắn gọn:
- Bắt được **~85-90% user base** thực tế năm 2026 (không khác biệt đáng kể với 7.4 là ~92-95%).
- Effort port từ 8.1+ xuống 8.0+ là **gần như zero** (1 file ImageOptimizer + pin AWS SDK).
- Effort port xuống 7.4+ là **~5 giờ** kèm rủi ro lùi 3 thứ EOL (JWT v5, Laravel 8, chính PHP 7.4).
- 5-10% user thêm được khi xuống 7.4 phần lớn là host shared cũ không cập nhật → ít commercial value, lại đẩy mình vào maintain dep EOL.

---

## 1. Bối cảnh thị trường PHP — tháng 5/2026

### 1.1. Lifecycle các phiên bản PHP

| Version | Released | Active support | Security support | Status hôm nay (2026-05) |
|---|---|---|---|---|
| 7.4 | 2019-11 | hết 2021-11 | hết 2022-11 | **EOL 3.5 năm rồi** |
| 8.0 | 2020-11 | hết 2022-11 | hết 2023-11 | **EOL 2.5 năm** |
| 8.1 | 2021-11 | hết 2023-11 | hết 2025-12 | **EOL 5 tháng** |
| 8.2 | 2022-12 | hết 2024-12 | hết 2026-12 | **security-only — sắp EOL 7 tháng tới** |
| 8.3 | 2023-11 | hết 2025-11 | hết 2027-11 | security-only — còn 1.5 năm |
| 8.4 | 2024-11 | đang active | hết 2028-12 | đang active |

⚠️ **Quan trọng:** ngay cả PHP 8.1 — phiên bản FluxFiles đang yêu cầu hiện tại — cũng **đã EOL**. Maintainer FluxFiles thực ra **đang ship trên một floor đã EOL** mà không biết.

### 1.2. Phân bố thực tế trên user base (ước lượng 2026)

Dựa trên WordPress.org stats public + xu hướng Packagist:

| Version | WP install share (~5/2026) | Composer install share | Ghi chú |
|---|---|---|---|
| ≤ 7.3 | < 1% | < 0.5% | gần như 0 |
| 7.4 | 5-8% | 3-5% | shared host cũ chưa update |
| 8.0 | 5-7% | 4-6% | đang giảm |
| 8.1 | 10-15% | 12-18% | EOL nhưng vẫn nhiều |
| 8.2 | 25-30% | 30-35% | phổ biến nhất |
| 8.3 | 25-30% | 25-30% | đang lên |
| 8.4 | 15-20% | 12-18% | mới |

**Hệ quả:**
- Floor 7.4: bắt **~92-95%** install base
- Floor 8.0: bắt **~87-90%**
- Floor 8.1: bắt **~80-85%** (state hiện tại của FluxFiles)
- Floor 8.2: bắt **~65-75%**

---

## 2. So sánh 3 lựa chọn

### Option A — PHP 7.4+

**Pros:**
- Bắt được nhóm host shared cũ nhất, đặc biệt là WP install bị "lock" trên 7.4.
- Coverage ~92-95% market.
- Khoảng 5-7% user nhiều hơn so với 8.0+.

**Cons:**
- **Effort port ~5h** (chi tiết ở [PHP74-PORT-ANALYSIS.md](./PHP74-PORT-ANALYSIS.md)).
- **Lùi 3 thứ EOL** vào dep tree:
  - `firebase/php-jwt v5` (EOL 2022)
  - `Laravel 8` (EOL 1/2023)
  - **Chính PHP 7.4** (EOL 11/2022)
- Phải maintain `intervention/image v2` rewrite (1 file), không quá nặng nhưng là tech debt.
- AWS SDK phải pin version cũ (~3.290), bỏ lỡ fix mới.
- Truyền thông điệp "FluxFiles OK với phần mềm EOL" — có thể hấp dẫn sai user (legacy shop, không trả tiền upgrade).
- Phải maintain 2 dep tree song song nếu muốn tiếp tục tận dụng feature mới ở user 8.x.

**User được thêm:** chủ yếu là shared host bị lock 7.4 + WP install cũ. Phân khúc này:
- Ít khi trả tiền dịch vụ premium.
- Có rủi ro cao về data breach (vì chạy PHP EOL).
- Tích cực nhất là user cá nhân, blog nhỏ.

### Option B — PHP 8.0+ ✅ **Khuyến nghị**

**Pros:**
- **Effort port từ 8.1+ xuống 8.0+ là ~30 phút**:
  - Code 0 thay đổi (mọi feature dùng đều ≤ 8.0).
  - Pin `aws/aws-sdk-php < 3.295` (1 dòng composer.json).
  - `intervention/image: ^2.7` thay vì ^3.0 (vì v3 yêu cầu 8.1, v2 OK trên 8.0) — đây là phần chung với Option A nhưng nhẹ hơn vì không phải lo Flysystem/JWT.
  - Đổi `^8.1` → `^8.0` trong 3 composer.json + WP plugin header.
- **Không kéo dep EOL nào vào** trừ Laravel 9 (EOL 2/2024, dùng cho 8.0).
- Coverage ~87-90% market.
- Vẫn dùng được Flysystem 3 (active), firebase/php-jwt 6 (active).
- Sang năm dễ bump lên 8.1/8.2 khi 8.0 user giảm tiếp.

**Cons:**
- Bỏ qua ~5-7% user trên 7.4.
- Vẫn phải downgrade intervention 3 → 2 (chung với Option A).
- Laravel cần mở constraint xuống `^9.0` (EOL 2/2024) — vẫn còn dep EOL nhưng chỉ 1 thứ.

### Option C — Giữ nguyên PHP 8.1+

**Pros:**
- Code hiện tại không đụng gì.
- Tất cả dep ở phiên bản mới nhất.
- Không vướng dep EOL nào (sau khi 8.1 EOL, Laravel 10 vẫn active).

**Cons:**
- **Bỏ qua ~10-15% user** trên 7.4 + 8.0.
- Truyền thông điệp "không hỗ trợ host cũ" → mất phân khúc shared host trung-thấp cấp.
- WP plugin bị reject trên nhiều install WP cũ.

### Option D — PHP 8.2+ (an toàn pháp lý)

**Pros:**
- Tất cả PHP đều còn được support upstream.
- Không có rủi ro lùi vào software EOL.

**Cons:**
- Chỉ bắt ~65-75% install base.
- Mất luôn user trên 8.0/8.1 vẫn còn nhiều.
- Quá khắt khe cho 1 file manager — không phải app financial mission-critical.

---

## 3. So sánh bảng tóm tắt

| Tiêu chí | 7.4+ | **8.0+ ✅** | 8.1+ (hiện tại) | 8.2+ |
|---|---|---|---|---|
| Coverage install | 92-95% | 87-90% | 80-85% | 65-75% |
| Effort port | ~5h | ~30 phút | 0 | 0 (nhưng bắt phải bump) |
| Code change | có (polyfill + tests) | không | không | không |
| Dep EOL kéo theo | JWT v5, Laravel 8, PHP 7.4 | Laravel 9 | không | không |
| AWS SDK pin | bắt buộc | nhẹ | không | không |
| Intervention v3 OK? | không | không | có | có |
| Flysystem v3 OK? | không | có | có | có |
| Maintain dep song song | có | không | không | không |
| Truyền thông điệp | "support legacy" | "modern, broad" | "modern only" | "newest only" |

---

## 4. Khuyến nghị chi tiết

### 4.1. Chọn PHP 8.0+ vì:

1. **Coverage marginal trade-off rất tốt**: tăng 5-7% coverage so với 8.1+ (state hiện tại) với gần như không có effort. Tăng thêm 5% nữa lên 7.4 đòi ~5h + maintain dep EOL.

2. **Tránh được 2 trong 3 thứ EOL** mà Option A kéo vào (JWT v5 + Laravel 8).

3. **Tương thích thẳng với hosting hiện đại 2026**:
   - Cloudways, Kinsta, WPEngine, RunCloud, SiteGround: tất cả đều support 8.0+.
   - Mua VPS DigitalOcean/Linode 2025-2026: PHP 8.0+ là default.
   - Shared host budget (Hostinger, A2 Hosting, NameCheap): đều có 8.0+ available, chỉ một số user lười switch.

4. **Sang năm dễ bump tiếp**: khi 8.0 user thêm giảm xuống <5%, có thể bump sang 8.1 floor mà không tổn hại.

5. **Truyền thông điệp đúng**: "FluxFiles support tất cả PHP 8.x" — clean, professional, không bị nhầm là legacy.

### 4.2. Khi nào chọn 7.4+?

CHỈ chọn nếu:
- Có khách hàng/deal cụ thể yêu cầu PHP 7.4 — viết vào hợp đồng.
- Sẵn sàng maintain dep EOL trong 1-2 năm tới.
- Document rõ trong README: "Recommended PHP 8.x, 7.4 supported for legacy environments — security updates not guaranteed".

### 4.3. Khi nào giữ 8.1+?

Nếu:
- Target user là dev modern dùng Laravel 10+/WordPress 6.x mới install.
- Không cần WP plugin reach rộng.
- Chấp nhận user base 80-85% là OK.

### 4.4. Hybrid strategy — nếu bắt buộc support 7.4 cho WP plugin

Nếu lý do support 7.4 chủ yếu là vì WordPress install base, có thể chia:

- **Core + Laravel package + standalone server: PHP 8.0+**
- **WP plugin: PHP 7.4+** (composer.json riêng pin dep cũ hơn, build script khác)

Cost: maintain 2 dep tree, build pipeline phức tạp hơn. Pros: WP plugin reach max, code dev modern.

Khuyến nghị: **không làm hybrid trừ khi có deal cụ thể**, vì phức tạp gấp đôi.

---

## 5. Kế hoạch nếu chốt 8.0+

So với plan ở `PHP74-PORT-ANALYSIS.md`, giảm rất nhiều:

### Phase A (rút gọn)
- Không cần polyfill str_*.
- Không cần đổi named args.
- **Skip toàn bộ Phase A.**

### Phase B (giống)
- Vẫn rewrite `ImageOptimizer` cho Intervention v2 vì v3 cần 8.1.
- Hoặc giữ Intervention v3 và floor 8.1+ — tradeoff: mất user 8.0.

### Phase C (rút gọn)
- composer.json: `php: ">=8.0"`
- `intervention/image: ^2.7`
- `aws/aws-sdk-php: ">=3.250,<3.295"` (pin tránh bump 8.1)
- `firebase/php-jwt`: giữ ^6.0 (OK với 8.0)
- `league/flysystem`: giữ ^3.0 (yêu cầu 8.0.2, mà 8.0 floor coi như 8.0.2+ → OK)
- Laravel: `^9.0|^10|^11|^12`
- WP plugin header: `Requires PHP: 8.0`

### Phase D (giống)
- Test trong Docker `php:8.0-cli`.

**Tổng effort: ~2h** (so với 5h cho 7.4).

---

## 6. Có 1 vấn đề cần xác nhận với bạn

Câu hỏi của bạn là "**có nhiều user cũ và mới**". Tôi cần biết thêm:

1. **"User cũ"** ở đây là gì?
   - (a) User đã dùng FluxFiles từ trước → họ đã quen 8.1+, không sao.
   - (b) User mới install lần đầu nhưng đang ở host cũ → đây là phân khúc 7.4 thực sự.

2. **Target deployment chính:**
   - (a) WordPress plugin marketplace → reach rộng cần 7.4
   - (b) Composer package cho dev Laravel → 8.0+ là đủ
   - (c) Standalone trên VPS/Docker → 8.0+ là dư

3. **Có deal/khách hàng cụ thể** yêu cầu 7.4 không?

Nếu trả lời (a)/(a)/có → port 7.4.
Nếu trả lời (a hoặc b)/(b hoặc c)/không → **đề xuất 8.0**.

---

## 7. Kết luận

**Chốt: PHP 8.0+** trừ khi có lý do business cụ thể support 7.4.

- Effort: 2h
- Coverage: 87-90% market
- Không kéo EOL critical vào dep tree
- Future-proof: 12-18 tháng sau bump tiếp 8.1 dễ dàng

Nếu chốt rồi, tôi sẽ:
1. Update `PHP74-PORT-ANALYSIS.md` thành `PHP80-PORT-PLAN.md` (rút gọn theo §5 ở trên).
2. Tiến hành theo plan đó với checkpoint ở mỗi phase.
