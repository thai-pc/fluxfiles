#!/bin/bash
#
# FluxFiles API Test Suite
# Usage: bash tests/test-api.sh
# Requires: server running at localhost:8080
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

# Generate token (run from project root)
TOKEN=$(php -r "
require_once __DIR__ . '/embed.php';
\$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
\$dotenv->safeLoad();
echo fluxfiles_token('test-api', ['read','write','delete'], ['local','s3','r2'], '', 50, null, 86400);
")

READ_TOKEN=$(php -r "
require_once __DIR__ . '/embed.php';
\$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
\$dotenv->safeLoad();
echo fluxfiles_token('reader', ['read'], ['local'], '', 10, null, 3600);
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

echo -e "\n${CYAN}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║      FluxFiles API Test Suite                    ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════════╝${NC}\n"

# ═══ Auth ═══
echo -e "${YELLOW}[Auth]${NC}"

RESP=$(curl -s -w "\n%{http_code}" "$BASE/list?disk=local&path=")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "No token → 401" "401" "$CODE" "$BODY"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer invalid-token" "$BASE/list?disk=local&path=")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Invalid token → 401" "401" "$CODE" "$BODY"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/list?disk=local&path=")
CODE=$(echo "$RESP" | tail -1)
check "Valid token → 200" "200" "$CODE"

# ═══ CORS ═══
echo -e "\n${YELLOW}[CORS]${NC}"

RESP=$(curl -s -o /dev/null -w "%{http_code}" -X OPTIONS \
  -H "Origin: http://localhost:8080" \
  -H "Access-Control-Request-Method: POST" \
  "$BASE/list")
check "CORS preflight → 204" "204" "$RESP"

# ═══ i18n (Public) ═══
echo -e "\n${YELLOW}[i18n — Public Routes]${NC}"

RESP=$(curl -s -w "\n%{http_code}" "$BASE/lang")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
COUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo "?")
check "GET /lang → 200 ($COUNT locales)" "200" "$CODE"

for LOCALE in vi ja ar th hi ru it tr nl; do
    RESP=$(curl -s -w "\n%{http_code}" "$BASE/lang/$LOCALE")
    CODE=$(echo "$RESP" | tail -1)
    check "GET /lang/$LOCALE → 200" "200" "$CODE"
done

RESP=$(curl -s -w "\n%{http_code}" "$BASE/lang/xx")
CODE=$(echo "$RESP" | tail -1)
check "GET /lang/xx (invalid) → 404" "404" "$CODE"

# ═══ Mkdir ═══
echo -e "\n${YELLOW}[Mkdir]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"disk":"local","path":"_api_test_dir"}' "$BASE/mkdir")
CODE=$(echo "$RESP" | tail -1)
check "Create directory → 200" "200" "$CODE"

# ═══ Upload ═══
echo -e "\n${YELLOW}[Upload]${NC}"

echo "test file content for FluxFiles API testing" > /tmp/ff-api-test.txt
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" -F "path=_api_test_dir" -F "file=@/tmp/ff-api-test.txt" "$BASE/upload")
CODE=$(echo "$RESP" | tail -1)
check "Upload file → 200" "200" "$CODE"

# Duplicate
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" -F "path=_api_test_dir" -F "file=@/tmp/ff-api-test.txt" "$BASE/upload")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
check "Duplicate detection → 200" "200" "$CODE"

# Force upload
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" -F "path=_api_test_dir" -F "file=@/tmp/ff-api-test.txt" -F "force_upload=1" "$BASE/upload")
CODE=$(echo "$RESP" | tail -1)
check "Force upload duplicate → 200" "200" "$CODE"

# Upload with read-only token
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $READ_TOKEN" \
  -F "disk=local" -F "path=_api_test_dir" -F "file=@/tmp/ff-api-test.txt" "$BASE/upload")
CODE=$(echo "$RESP" | tail -1)
check "Upload read-only token → 403" "403" "$CODE"

# ═══ List ═══
echo -e "\n${YELLOW}[List]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/list?disk=local&path=_api_test_dir")
CODE=$(echo "$RESP" | tail -1)
check "List directory → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/list?disk=local&path=")
CODE=$(echo "$RESP" | tail -1)
check "List root → 200" "200" "$CODE"

# ═══ File Meta ═══
echo -e "\n${YELLOW}[File Meta]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$BASE/meta?disk=local&path=_api_test_dir/ff-api-test.txt")
CODE=$(echo "$RESP" | tail -1)
check "Get file meta → 200" "200" "$CODE"

# ═══ Metadata CRUD ═══
echo -e "\n${YELLOW}[Metadata CRUD]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X PUT -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","key":"_api_test_dir/ff-api-test.txt","title":"Test Title","alt_text":"Alt text","caption":"Caption","tags":"test, api, fluxfiles"}' \
  "$BASE/metadata")
CODE=$(echo "$RESP" | tail -1)
check "Save metadata → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$BASE/metadata?disk=local&key=_api_test_dir/ff-api-test.txt")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
TITLE=$(echo "$BODY" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['title'])" 2>/dev/null || echo "")
check "Get metadata → 200 (title=$TITLE)" "200" "$CODE"

# ═══ Search ═══
echo -e "\n${YELLOW}[Search FTS5]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$BASE/search?disk=local&q=Test+Title&limit=5")
CODE=$(echo "$RESP" | tail -1)
check "Search by title → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$BASE/search?disk=local&q=fluxfiles&limit=5")
CODE=$(echo "$RESP" | tail -1)
check "Search by tag → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$BASE/search?disk=local&q=nonexistent_query_xyz&limit=5")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
RCOUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo "?")
check "Search no results → 200 (count=$RCOUNT)" "200" "$CODE"

# ═══ Copy & Move ═══
echo -e "\n${YELLOW}[Copy & Move]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","from":"_api_test_dir/ff-api-test.txt","to":"_api_test_dir/copied.txt"}' \
  "$BASE/copy")
CODE=$(echo "$RESP" | tail -1)
check "Copy file → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","from":"_api_test_dir/copied.txt","to":"_api_test_dir/moved.txt"}' \
  "$BASE/move")
CODE=$(echo "$RESP" | tail -1)
check "Move file → 200" "200" "$CODE"

# ═══ Cross-copy (local to local = copy) ═══
echo -e "\n${YELLOW}[Cross-copy]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"src_disk":"local","src_path":"_api_test_dir/ff-api-test.txt","dst_disk":"local","dst_path":"_api_test_dir/cross-copied.txt"}' \
  "$BASE/cross-copy")
CODE=$(echo "$RESP" | tail -1)
check "Cross-copy (local→local) → 200" "200" "$CODE"

# ═══ Trash ═══
echo -e "\n${YELLOW}[Trash — Delete / Restore / Purge]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"_api_test_dir/moved.txt"}' "$BASE/delete")
CODE=$(echo "$RESP" | tail -1)
check "Soft delete → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/trash?disk=local")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
TCOUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo "?")
check "List trash → 200 ($TCOUNT items)" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"_api_test_dir/moved.txt"}' "$BASE/restore")
CODE=$(echo "$RESP" | tail -1)
check "Restore file → 200" "200" "$CODE"

# Delete again then purge
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"_api_test_dir/moved.txt"}' "$BASE/delete" > /dev/null 2>&1

RESP=$(curl -s -w "\n%{http_code}" -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"_api_test_dir/moved.txt"}' "$BASE/purge")
CODE=$(echo "$RESP" | tail -1)
check "Purge file → 200" "200" "$CODE"

# Purge-bulk: delete cross-copied file, then purge-bulk
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"_api_test_dir/cross-copied.txt"}' "$BASE/delete" > /dev/null 2>&1
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","paths":["_api_test_dir/cross-copied.txt"]}' "$BASE/purge-bulk")
CODE=$(echo "$RESP" | tail -1)
check "Purge-bulk → 200" "200" "$CODE"

# Chunk upload requires S3/R2 — skip when only local disk configured

# ═══ Quota ═══
echo -e "\n${YELLOW}[Quota]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/quota?disk=local")
CODE=$(echo "$RESP" | tail -1)
check "Get quota → 200" "200" "$CODE"

# ═══ Audit ═══
echo -e "\n${YELLOW}[Audit Log]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/audit?limit=10&offset=0")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | sed '$d')
ACOUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo "?")
check "Audit log → 200 ($ACOUNT entries)" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/audit?limit=5&user_id=test-api")
CODE=$(echo "$RESP" | tail -1)
check "Audit filter by user → 200" "200" "$CODE"

# ═══ Validation ═══
echo -e "\n${YELLOW}[Validation & Error Handling]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{}' "$BASE/move")
CODE=$(echo "$RESP" | tail -1)
check "Missing required fields → 400" "400" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d 'not-json' "$BASE/move")
CODE=$(echo "$RESP" | tail -1)
check "Invalid JSON body → 400" "400" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/nonexistent-route")
CODE=$(echo "$RESP" | tail -1)
check "Unknown route → 404" "404" "$CODE"

# Permission denied — write with read-only
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $READ_TOKEN" \
  -H "Content-Type: application/json" -d '{"disk":"local","path":"test"}' "$BASE/mkdir")
CODE=$(echo "$RESP" | tail -1)
check "Write with read-only → 403" "403" "$CODE"

# Delete with read-only
RESP=$(curl -s -w "\n%{http_code}" -X DELETE -H "Authorization: Bearer $READ_TOKEN" \
  -H "Content-Type: application/json" -d '{"disk":"local","path":"test.txt"}' "$BASE/delete")
CODE=$(echo "$RESP" | tail -1)
check "Delete with read-only → 403" "403" "$CODE"

# Disk access denied
RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $READ_TOKEN" \
  "$BASE/list?disk=s3&path=")
CODE=$(echo "$RESP" | tail -1)
check "Access denied disk → 403" "403" "$CODE"

# Missing search query
RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/search?disk=local")
CODE=$(echo "$RESP" | tail -1)
check "Search missing query → 400" "400" "$CODE"

# ═══ Cleanup ═══
echo -e "\n${YELLOW}[Cleanup]${NC}"

# Delete test files
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"_api_test_dir/ff-api-test.txt"}' "$BASE/delete" > /dev/null 2>&1
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"_api_test_dir/ff-api-test.txt"}' "$BASE/purge" > /dev/null 2>&1

# Delete metadata
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","key":"_api_test_dir/ff-api-test.txt"}' "$BASE/metadata" > /dev/null 2>&1

rm -f /tmp/ff-api-test.txt
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
