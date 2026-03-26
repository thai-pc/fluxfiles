#!/bin/bash
#
# FluxFiles R2/S3 Cloud Storage Test Suite
# Usage: bash tests/test-r2.sh
# Requires: server running at localhost:8080, R2 configured in .env
#

set -e

# Ensure we run from project root
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR/.."

BASE="http://localhost:8080/api/fm"
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "\n${CYAN}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║      FluxFiles R2/S3 Cloud Storage Tests         ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════════╝${NC}\n"

# Check if R2 is configured
R2_KEY=$(grep '^R2_ACCESS_KEY_ID=' .env 2>/dev/null | cut -d'=' -f2)
if [ -z "$R2_KEY" ]; then
    echo -e "${YELLOW}Skipping: R2 is not configured (R2_ACCESS_KEY_ID is empty in .env)${NC}\n"
    exit 0
fi

# Generate token (run from project root)
TOKEN=$(php -r "
require_once __DIR__ . '/embed.php';
\$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
\$dotenv->safeLoad();
echo fluxfiles_token('test-r2', ['read','write','delete'], ['local','r2'], '', 50, null, 86400);
")

PASS=0
FAIL=0

check() {
    local desc="$1"
    local expected_code="$2"
    local actual_code="$3"
    local body="$4"

    if [ "$actual_code" = "$expected_code" ]; then
        echo -e "  ${GREEN}✓${NC} $desc (HTTP $actual_code)"
        PASS=$((PASS + 1))
    else
        echo -e "  ${RED}✗${NC} $desc — expected $expected_code, got $actual_code"
        if [ -n "$body" ]; then
            echo "    Response: $(echo "$body" | head -c 200)"
        fi
        FAIL=$((FAIL + 1))
    fi
}

# ═══ 1. List files on R2 ═══
echo -e "${YELLOW}[List R2 Root]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/list?disk=r2&path=")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "List R2 root → 200" "200" "$CODE" "$BODY"

# ═══ 2. Upload first test file ═══
echo -e "\n${YELLOW}[Upload to R2]${NC}"

echo "r2 test file 1 — FluxFiles cloud storage test" > /tmp/ff-r2-test1.txt
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=r2" -F "path=_r2_test" -F "file=@/tmp/ff-r2-test1.txt" "$BASE/upload")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Upload file 1 to R2 → 200" "200" "$CODE" "$BODY"

# ═══ 3. Upload second and third test files ═══
echo "r2 test file 2 — second upload" > /tmp/ff-r2-test2.txt
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=r2" -F "path=_r2_test" -F "file=@/tmp/ff-r2-test2.txt" "$BASE/upload")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Upload file 2 to R2 → 200" "200" "$CODE" "$BODY"

echo "r2 test file 3 — third upload" > /tmp/ff-r2-test3.txt
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=r2" -F "path=_r2_test" -F "file=@/tmp/ff-r2-test3.txt" "$BASE/upload")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Upload file 3 to R2 → 200" "200" "$CODE" "$BODY"

# ═══ 4. List directory ═══
echo -e "\n${YELLOW}[List R2 Directory]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/list?disk=r2&path=_r2_test")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
FCOUNT=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(len([f for f in d if f.get('type')=='file']))" 2>/dev/null || echo "?")
check "List _r2_test → 200 ($FCOUNT files)" "200" "$CODE" "$BODY"

# ═══ 5. Copy file (within R2) ═══
echo -e "\n${YELLOW}[Copy within R2]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","from":"_r2_test/ff-r2-test1.txt","to":"_r2_test/copied.txt"}' \
  "$BASE/copy")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Copy file within R2 → 200" "200" "$CODE" "$BODY"

# ═══ 6. Move file (within R2) ═══
echo -e "\n${YELLOW}[Move within R2]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","from":"_r2_test/copied.txt","to":"_r2_test/moved.txt"}' \
  "$BASE/move")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Move file within R2 → 200" "200" "$CODE" "$BODY"

# ═══ 7. Save metadata on R2 file ═══
echo -e "\n${YELLOW}[Metadata CRUD on R2]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X PUT -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","key":"_r2_test/ff-r2-test1.txt","title":"R2 Test Title","alt_text":"R2 alt","caption":"R2 caption","tags":"r2, cloud, test"}' \
  "$BASE/metadata")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Save metadata on R2 file → 200" "200" "$CODE" "$BODY"

# ═══ 8. Get metadata ═══
RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$BASE/metadata?disk=r2&key=_r2_test/ff-r2-test1.txt")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
TITLE=$(echo "$BODY" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['title'])" 2>/dev/null || echo "")
check "Get metadata → 200 (title=$TITLE)" "200" "$CODE" "$BODY"

# ═══ 9. Search ═══
echo -e "\n${YELLOW}[Search on R2]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$BASE/search?disk=r2&q=R2+Test+Title&limit=5")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
SCOUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo "?")
check "Search by title on R2 → 200 ($SCOUNT results)" "200" "$CODE" "$BODY"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$BASE/search?disk=r2&q=cloud&limit=5")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Search by tag on R2 → 200" "200" "$CODE" "$BODY"

# ═══ 10. Presign URL ═══
echo -e "\n${YELLOW}[Presign URL]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","path":"_r2_test/ff-r2-test1.txt","method":"GET"}' \
  "$BASE/presign")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Presign GET URL → 200" "200" "$CODE" "$BODY"

# ═══ 11. Soft delete (trash) ═══
echo -e "\n${YELLOW}[Trash — Delete / Restore / Purge on R2]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","path":"_r2_test/moved.txt"}' "$BASE/delete")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Soft delete moved.txt → 200" "200" "$CODE" "$BODY"

# ═══ 12. List trash ═══
RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/trash?disk=r2")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
TCOUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo "?")
check "List R2 trash → 200 ($TCOUNT items)" "200" "$CODE" "$BODY"

# ═══ 13. Restore from trash ═══
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","path":"_r2_test/moved.txt"}' "$BASE/restore")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Restore moved.txt → 200" "200" "$CODE" "$BODY"

# ═══ 14. Delete again, then purge single ═══
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","path":"_r2_test/moved.txt"}' "$BASE/delete" > /dev/null 2>&1

RESP=$(curl -s -w "\n%{http_code}" -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","path":"_r2_test/moved.txt"}' "$BASE/purge")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Purge moved.txt → 200" "200" "$CODE" "$BODY"

# ═══ 15. Bulk delete remaining files ═══
echo -e "\n${YELLOW}[Bulk Operations on R2]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","paths":["_r2_test/ff-r2-test1.txt","_r2_test/ff-r2-test2.txt","_r2_test/ff-r2-test3.txt"]}' \
  "$BASE/delete-bulk")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Bulk delete 3 files → 200" "200" "$CODE" "$BODY"

# ═══ 16. Bulk purge ═══
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","paths":["_r2_test/ff-r2-test1.txt","_r2_test/ff-r2-test2.txt","_r2_test/ff-r2-test3.txt"]}' \
  "$BASE/purge-bulk")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Bulk purge 3 files → 200" "200" "$CODE" "$BODY"

# ═══ 17. Verify trash empty ═══
echo -e "\n${YELLOW}[Verify Clean State]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/trash?disk=r2")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
TCOUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo "?")
check "Trash empty → 200 ($TCOUNT items)" "200" "$CODE" "$BODY"

# ═══ 18. Cleanup ═══
echo -e "\n${YELLOW}[Cleanup]${NC}"

# Clean up metadata
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"r2","key":"_r2_test/ff-r2-test1.txt"}' "$BASE/metadata" > /dev/null 2>&1

# Clean up temp files
rm -f /tmp/ff-r2-test1.txt /tmp/ff-r2-test2.txt /tmp/ff-r2-test3.txt
echo -e "  ${GREEN}✓${NC} Cleaned up test files"

# ═══ Summary ═══
echo -e "\n${CYAN}══════════════════════════════════════════════════${NC}"
TOTAL=$((PASS + FAIL))
if [ $FAIL -eq 0 ]; then
    echo -e "${GREEN}All $TOTAL tests passed!${NC}"
else
    echo -e "${RED}$FAIL of $TOTAL tests failed${NC}"
fi
echo ""
exit $FAIL
