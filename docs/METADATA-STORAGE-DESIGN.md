# Metadata Storage — Đi theo User, Không lưu trên Server

## Mục tiêu

- Metadata (title, alt_text, caption, tags) **không** lưu trên server (đã bỏ SQLite)
- Metadata **đi theo** file — nằm cùng bucket/storage với file của user
- Áp dụng cho: BYOB, S3, R2, Local

---

## Kiến trúc

### 1. S3 / R2 / BYOB — Metadata trong Object Metadata


| Thành phần       | Vị trí                               | Ghi chú                                               |
| ---------------- | ------------------------------------ | ----------------------------------------------------- |
| **Metadata**     | S3 Object Metadata (x-amz-meta-*)    | title, alt_text, caption, tags — max 2KB/object       |
| **Search index** | `_fluxfiles/index.json` trong bucket | Cập nhật khi save metadata, dùng cho full-text search |
| **Trash**        | —                                    | (Chưa có API trash/restore/purge trong core hiện tại)  |
| **Audit**        | `_fluxfiles/audit.jsonl`             | Append mỗi sự kiện                                    |
| **File hash**    | x-amz-meta-file-hash                 | Phát hiện duplicate                                   |


**Lưu metadata:** `CopyObject` (copy to self) với Metadata mới — S3 không có API update metadata trực tiếp.

**Đọc metadata:** `HeadObject` hoặc `GetObject` — response chứa Metadata.

### 2. Local disk — Sidecar file


| Thành phần       | Vị trí                   | Ghi chú                                                |
| ---------------- | ------------------------ | ------------------------------------------------------ |
| **Metadata**     | `{path}.meta.json`       | Ví dụ: `photos/2024.jpg` → `photos/2024.jpg.meta.json` |
| **Search index** | `_fluxfiles/index.json`  | Cache để search nhanh                                  |
| **Trash**        | —                        | (Chưa có API trash/restore/purge trong core hiện tại)  |
| **Audit**        | `_fluxfiles/audit.jsonl` | Append mỗi sự kiện                                     |


---

## Luồng hoạt động

### Save metadata (S3/R2)

1. CopyObject(bucket, key, CopySource: bucket/key, Metadata: {title, alt_text, caption, tags})
2. Cập nhật `_fluxfiles/index.json` — thêm/sửa entry cho key
3. (Tùy chọn) Append audit

### List files

1. ListObjects từ storage
2. Với S3: HeadObject từng file để lấy Metadata (hoặc dùng index)
3. Với Local: Đọc sidecar .meta.json nếu có
4. Lọc trash (S3: bỏ prefix _trash/, Local: bỏ _trash/)

### Search

1. Tải `_fluxfiles/index.json`
2. Filter trong memory theo query
3. Trả về kết quả (có thể thêm highlight)

### Trash

Core hiện có `DELETE /api/fm/delete` (xóa trực tiếp). Chưa có các endpoint trash/restore/purge.

---

## Trạng thái

- **Đã áp dụng:** Metadata, trash, audit, search đều lưu trong storage của user (S3 tags / sidecar / index.json / audit.jsonl)
- **Không còn SQLite** — mọi thứ đi theo user storage

