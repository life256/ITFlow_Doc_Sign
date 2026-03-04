#!/usr/bin/env bash
#
# ITFlow Document Signing Plugin - Post-Upgrade Verification
#
# Run this after updating ITFlow to check that the plugin is still intact.
#
# Usage:
#   ./verify.sh /path/to/itflow
#

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

ok()   { echo -e "  ${GREEN}✓${NC} $*"; ((pass++)); }
fail() { echo -e "  ${RED}✗${NC} $*"; ((fail_count++)); }
warn_() { echo -e "  ${YELLOW}!${NC} $*"; ((warnings++)); }

ITFLOW="${1:-}"
if [ -z "$ITFLOW" ]; then
    echo "Usage: ./verify.sh /path/to/itflow"
    exit 1
fi

ITFLOW="$(cd "$ITFLOW" 2>/dev/null && pwd)" || { echo "Directory not found: $1"; exit 1; }

echo ""
echo -e "${BOLD}ITFlow Document Signing Plugin - Verification${NC}"
echo -e "ITFlow path: ${CYAN}$ITFLOW${NC}"
echo ""

pass=0
fail_count=0
warnings=0

# ── File checks ──────────────────────────────────────────────────
echo -e "${BOLD}Files:${NC}"

for f in \
    "includes/functions_signable.php" \
    "agent/signable_documents.php" \
    "agent/signable_document.php" \
    "agent/ajax_signable.php" \
    "agent/post/signable_document.php" \
    "agent/post/signable_document_model.php" \
    "agent/modals/signable_document/signable_document_add.php" \
    "agent/modals/signable_document/signable_document_edit.php" \
    "agent/modals/signable_document/signable_document_send.php" \
    "guest/guest_sign_document.php" \
    "js/signature_pad.js"
do
    [ -f "$ITFLOW/$f" ] && ok "$f" || fail "$f MISSING"
done

echo ""

# ── Integration checks ───────────────────────────────────────────
echo -e "${BOLD}Integration:${NC}"

# POST handler auto-discovery
if grep -q 'glob.*post/.*\.php' "$ITFLOW/agent/post.php" 2>/dev/null; then
    ok "POST handler auto-discovered via glob()"
elif grep -qF 'require_once("post/signable_document.php")' "$ITFLOW/agent/post.php" 2>/dev/null; then
    ok "POST handler manually registered in agent/post.php"
else
    fail "POST handler NOT loadable (no glob and no manual registration)"
    echo -e "    ${CYAN}Fix: Re-run install.sh${NC}"
fi

# Uploads directory
if [ -d "$ITFLOW/uploads/signable_documents" ]; then
    ok "Upload directory exists"
    if [ -w "$ITFLOW/uploads/signable_documents" ]; then
        ok "Upload directory is writable"
    else
        fail "Upload directory is NOT writable"
    fi
else
    fail "Upload directory missing: uploads/signable_documents/"
fi

echo ""

# ── ITFlow dependency checks ─────────────────────────────────────
echo -e "${BOLD}ITFlow Dependencies (functions this plugin calls):${NC}"

check_function() {
    local func="$1"
    local desc="$2"
    if grep -rql "function $func" "$ITFLOW/includes/" 2>/dev/null; then
        ok "$func() - $desc"
    else
        fail "$func() NOT FOUND - $desc"
    fi
}

check_function "enforceUserPermission" "RBAC access control"
check_function "validateCSRFToken" "CSRF protection"

# sendSingleEmail might be in a different location
if grep -rql "function sendSingleEmail" "$ITFLOW/includes/" "$ITFLOW/plugins/" 2>/dev/null; then
    ok "sendSingleEmail() - Email delivery"
else
    warn_ "sendSingleEmail() not found (email sending may not work)"
fi

# Check inc_all.php exists
if [ -f "$ITFLOW/includes/inc_all.php" ] || [ -f "$ITFLOW/agent/includes/inc_all.php" ]; then
    ok "inc_all.php found"
else
    fail "inc_all.php NOT FOUND (all agent pages depend on this)"
fi

# Check config.php
if [ -f "$ITFLOW/config.php" ]; then
    ok "config.php found"
else
    fail "config.php NOT FOUND"
fi

# TCPDF for PDF export
if [ -d "$ITFLOW/plugins/TCPDF" ]; then
    ok "TCPDF library present (PDF export)"
else
    warn_ "TCPDF not found (PDF export will not work)"
fi

# TinyMCE for rich text editing
if [ -d "$ITFLOW/plugins/tinymce" ]; then
    ok "TinyMCE library present (rich text editing)"
else
    warn_ "TinyMCE not found (rich text editor will not load)"
fi

echo ""

# ── Database table checks ────────────────────────────────────────
echo -e "${BOLD}Database:${NC}"

DB_HOST="$(grep -oP "(?<=['\"]DATABASE_HOST['\"]\\s*,\\s*['\"])[^'\"]*" "$ITFLOW/config.php" 2>/dev/null || true)"
DB_NAME="$(grep -oP "(?<=['\"]DATABASE_NAME['\"]\\s*,\\s*['\"])[^'\"]*" "$ITFLOW/config.php" 2>/dev/null || true)"
DB_USER="$(grep -oP "(?<=['\"]DATABASE_USERNAME['\"]\\s*,\\s*['\"])[^'\"]*" "$ITFLOW/config.php" 2>/dev/null || true)"
DB_PASS="$(grep -oP "(?<=['\"]DATABASE_PASSWORD['\"]\\s*,\\s*['\"])[^'\"]*" "$ITFLOW/config.php" 2>/dev/null || true)"

if [ -z "$DB_HOST" ]; then
    DB_HOST="$(grep -oP '(?<=\$db_host\s*=\s*["\x27])[^"\x27]*' "$ITFLOW/config.php" 2>/dev/null || true)"
    DB_NAME="$(grep -oP '(?<=\$db_name\s*=\s*["\x27])[^"\x27]*' "$ITFLOW/config.php" 2>/dev/null || true)"
    DB_USER="$(grep -oP '(?<=\$db_username\s*=\s*["\x27])[^"\x27]*' "$ITFLOW/config.php" 2>/dev/null || true)"
    DB_PASS="$(grep -oP '(?<=\$db_password\s*=\s*["\x27])[^"\x27]*' "$ITFLOW/config.php" 2>/dev/null || true)"
fi

if [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] && command -v mysql &>/dev/null; then
    if mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -e "USE \`$DB_NAME\`" 2>/dev/null; then
        for table in signable_documents signable_document_signatures signable_document_history; do
            exists=$(mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" \
                -sNe "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='$table'" 2>/dev/null || echo "0")
            if [ "$exists" -gt 0 ] 2>/dev/null; then
                ok "Table: $table"
            else
                fail "Table missing: $table"
            fi
        done

        # Check that referenced tables still exist (ITFlow core tables we JOIN against)
        for core_table in clients contacts companies notifications; do
            exists=$(mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" \
                -sNe "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='$core_table'" 2>/dev/null || echo "0")
            if [ "$exists" -gt 0 ] 2>/dev/null; then
                ok "Core table present: $core_table"
            else
                fail "Core table missing: $core_table (plugin JOINs against this)"
            fi
        done
    else
        warn_ "Could not connect to database (skipping table checks)"
    fi
else
    warn_ "Could not extract database credentials (skipping table checks)"
fi

echo ""

# ── Summary ──────────────────────────────────────────────────────
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if [ $fail_count -eq 0 ] && [ $warnings -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All checks passed ($pass/$pass).${NC} Plugin is fully operational."
elif [ $fail_count -eq 0 ]; then
    echo -e "${YELLOW}${BOLD}$pass passed, $warnings warning(s).${NC} Plugin should work but with reduced functionality."
else
    echo -e "${RED}${BOLD}$fail_count FAILED${NC}, $pass passed, $warnings warning(s)."
    echo "Review the failures above. The plugin may not work correctly."
    echo "Re-running install.sh should fix most issues."
fi
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
