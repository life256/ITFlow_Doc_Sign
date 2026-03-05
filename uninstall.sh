#!/usr/bin/env bash
#
# ITFlow Document Signing Plugin - Uninstaller
#
# Usage:
#   ./uninstall.sh /path/to/itflow
#   ./uninstall.sh /path/to/itflow --drop-tables
#
# Removes all plugin files and sidebar navigation.
# Does NOT drop database tables unless --drop-tables is passed.
#

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

info()  { echo -e "${CYAN}[INFO]${NC}  $*"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
err()   { echo -e "${RED}[ERROR]${NC} $*"; }
fatal() { err "$*"; exit 1; }

DROP_TABLES=false
ITFLOW=""

for arg in "$@"; do
    case "$arg" in
        --drop-tables) DROP_TABLES=true ;;
        *) ITFLOW="$arg" ;;
    esac
done

echo ""
echo -e "${BOLD}ITFlow Document Signing Plugin - Uninstaller${NC}"
echo ""

if [ -z "$ITFLOW" ]; then
    # Try to auto-detect ITFlow
    info "Searching for ITFlow installation..."
    DETECTED=""
    while IFS= read -r candidate; do
        dir="$(dirname "$candidate")"
        if [ -f "$dir/agent/post.php" ] && [ -f "$dir/functions.php" ] && [ -d "$dir/guest" ]; then
            DETECTED="$dir"
            break
        fi
    done < <(find /var/www /var/html /srv/www /srv/http /usr/share/nginx /opt 2>/dev/null -maxdepth 4 -name "config.php" -path "*/config.php" -type f 2>/dev/null || true)

    if [ -n "$DETECTED" ]; then
        ok "Found ITFlow at: $DETECTED"
        echo ""
        read -rp "Is this the correct location? (Y/n): " confirm
        if [ "$confirm" = "n" ] || [ "$confirm" = "N" ]; then
            read -rp "Enter the full path to your ITFlow installation: " ITFLOW
        else
            ITFLOW="$DETECTED"
        fi
    else
        warn "Could not auto-detect ITFlow installation."
        read -rp "Enter the full path to your ITFlow installation: " ITFLOW
    fi
fi

ITFLOW="$(cd "$ITFLOW" 2>/dev/null && pwd)" || fatal "Directory does not exist: $ITFLOW"

if [ ! -f "$ITFLOW/config.php" ]; then
    fatal "This does not appear to be an ITFlow installation (config.php not found)."
fi

echo ""
echo -e "${BOLD}This will remove the Document Signing plugin from: $ITFLOW${NC}"
if [ "$DROP_TABLES" = true ]; then
    echo -e "${RED}WARNING: --drop-tables is set. Database tables and all signing data will be PERMANENTLY deleted.${NC}"
fi
echo ""
read -rp "Continue? (y/N): " confirm
[ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || exit 0

echo ""

# ── Extract DB credentials ───────────────────────────────────────
DB_HOST=""
DB_NAME=""
DB_USER=""
DB_PASS=""

if command -v php &>/dev/null; then
    eval "$(php -r "
        @include('$ITFLOW/config.php');
        \$h = \$dbhost ?? \$db_host ?? (defined('DATABASE_HOST') ? DATABASE_HOST : '');
        \$n = \$database ?? \$db_name ?? (defined('DATABASE_NAME') ? DATABASE_NAME : '');
        \$u = \$dbusername ?? \$db_username ?? (defined('DATABASE_USERNAME') ? DATABASE_USERNAME : '');
        \$p = \$dbpassword ?? \$db_password ?? (defined('DATABASE_PASSWORD') ? DATABASE_PASSWORD : '');
        echo 'DB_HOST=' . escapeshellarg(\$h) . \"\n\";
        echo 'DB_NAME=' . escapeshellarg(\$n) . \"\n\";
        echo 'DB_USER=' . escapeshellarg(\$u) . \"\n\";
        echo 'DB_PASS=' . escapeshellarg(\$p) . \"\n\";
    " 2>/dev/null)" || true
fi

HAS_DB=false
if [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] && command -v mysql &>/dev/null; then
    if mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -e "USE \`$DB_NAME\`" 2>/dev/null; then
        HAS_DB=true
    fi
fi

# ── Remove plugin files from agent/custom/ ────────────────────────
info "Removing plugin files..."

remove_file() {
    if [ -f "$ITFLOW/$1" ]; then
        rm "$ITFLOW/$1"
        ok "Removed $1"
    fi
}

# Files in agent/custom/
remove_file "agent/custom/signable_documents.php"
remove_file "agent/custom/signable_document.php"
remove_file "agent/custom/ajax_signable.php"
remove_file "agent/custom/post/signable_document.php"
remove_file "agent/custom/post/signable_document_model.php"
remove_file "agent/custom/modals/signable_document/signable_document_add.php"
remove_file "agent/custom/modals/signable_document/signable_document_edit.php"
remove_file "agent/custom/modals/signable_document/signable_document_send.php"
remove_file "agent/custom/includes/signable_side_nav_snippet.php"

# Shared includes and guest pages
remove_file "includes/functions_signable.php"
remove_file "guest/guest_sign_document.php"
remove_file "guest/guest_download_signed_pdf.php"
remove_file "js/signature_pad.js"

# Remove modal directory if empty
rmdir "$ITFLOW/agent/custom/modals/signable_document" 2>/dev/null && ok "Removed empty agent/custom/modals/signable_document/" || true

# Also clean up legacy file locations (from pre-custom-folder versions)
for legacy in \
    "agent/signable_documents.php" \
    "agent/signable_document.php" \
    "agent/ajax_signable.php" \
    "agent/post/signable_document.php" \
    "agent/post/signable_document_model.php" \
    "agent/modals/signable_document/signable_document_add.php" \
    "agent/modals/signable_document/signable_document_edit.php" \
    "agent/modals/signable_document/signable_document_send.php"
do
    if [ -f "$ITFLOW/$legacy" ]; then
        rm "$ITFLOW/$legacy"
        ok "Removed legacy file: $legacy"
    fi
done
rmdir "$ITFLOW/agent/modals/signable_document" 2>/dev/null || true

echo ""

# ── Remove sidebar navigation from custom_side_nav.php ────────────
info "Removing sidebar navigation..."

SIDE_NAV="$ITFLOW/agent/custom/includes/custom_side_nav.php"
MARKER_START="<!-- ITFlow Document Signing Plugin -->"
MARKER_END="<!-- /ITFlow Document Signing Plugin -->"

if [ -f "$SIDE_NAV" ] && grep -qF "$MARKER_START" "$SIDE_NAV"; then
    # Remove everything between (and including) the markers
    sed -i "/$MARKER_START/,/$MARKER_END/d" "$SIDE_NAV"
    ok "Sidebar navigation removed from custom_side_nav.php"
else
    info "No sidebar navigation found to remove."
fi

# Also remove legacy custom_links entry (from pre-custom-folder versions)
if [ "$HAS_DB" = true ]; then
    mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" -e \
        "DELETE FROM custom_links WHERE custom_link_uri = 'signable_documents.php'" 2>/dev/null || true
fi

echo ""

# ── Remove legacy post.php registration ───────────────────────────
POST_FILE="$ITFLOW/agent/post.php"
if grep -qF 'require_once("post/signable_document.php")' "$POST_FILE" 2>/dev/null; then
    info "Removing legacy POST handler registration from agent/post.php..."
    sed -i '/\/\/ ITFlow Document Signing Plugin/d' "$POST_FILE"
    sed -i '/require_once("post\/signable_document.php")/d' "$POST_FILE"
    sed -i '/^$/N;/^\n$/d' "$POST_FILE"
    ok "Legacy POST handler registration removed."
    echo ""
fi

# ── Handle uploads directory ─────────────────────────────────────
UPLOAD_DIR="$ITFLOW/uploads/signable_documents"
if [ -d "$UPLOAD_DIR" ]; then
    file_count=$(find "$UPLOAD_DIR" -type f 2>/dev/null | wc -l)
    if [ "$file_count" -gt 0 ]; then
        warn "Upload directory contains $file_count file(s): $UPLOAD_DIR"
        warn "NOT removing uploaded files. Delete manually if no longer needed:"
        echo "  rm -rf $UPLOAD_DIR"
    else
        rmdir "$UPLOAD_DIR" 2>/dev/null && ok "Removed empty uploads directory." || true
    fi
fi

echo ""

# ── Drop database tables (optional) ──────────────────────────────
if [ "$DROP_TABLES" = true ]; then
    info "Dropping database tables..."

    if [ "$HAS_DB" = true ]; then
        mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" -e "
            DROP TABLE IF EXISTS signable_document_signatures;
            DROP TABLE IF EXISTS signable_document_history;
            DROP TABLE IF EXISTS signable_documents;
        " 2>/dev/null && ok "Database tables dropped." || err "Failed to drop tables."
    else
        warn "Could not connect to database. Drop tables manually:"
        echo "  DROP TABLE IF EXISTS signable_document_signatures;"
        echo "  DROP TABLE IF EXISTS signable_document_history;"
        echo "  DROP TABLE IF EXISTS signable_documents;"
    fi
else
    info "Database tables preserved (use --drop-tables to remove them)."
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "${GREEN}${BOLD}Uninstall complete.${NC}"
echo ""
echo "Backups from installation (if any) are still at:"
echo "  $ITFLOW/.signable_backup_*"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
