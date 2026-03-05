#!/usr/bin/env bash
#
# ITFlow Document Signing Plugin - Installer
#
# Usage:
#   ./install.sh /path/to/itflow
#   ./install.sh                      (prompts for path)
#
# This script:
#   1. Validates the target is a real ITFlow installation
#   2. Checks PHP and MySQL prerequisites
#   3. Backs up any files it will overwrite
#   4. Copies all plugin files into agent/custom/ (ITFlow's custom module directory)
#   5. Injects sidebar navigation into custom_side_nav.php
#   6. Runs the database migration (tables only)
#   7. Creates the uploads directory with proper permissions
#   8. Verifies the installation
#
# Safe to run multiple times (idempotent).
#

set -euo pipefail

# ── Colors ────────────────────────────────────────────────────────
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

# ── Locate ourselves (the plugin source directory) ────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Auto-detect or accept ITFlow path ─────────────────────────────
ITFLOW="${1:-}"

echo ""
echo -e "${BOLD}ITFlow Document Signing Plugin - Installer${NC}"
echo ""

if [ -z "$ITFLOW" ]; then
    # Try to auto-detect ITFlow by searching common web directories
    info "Searching for ITFlow installation..."
    DETECTED=""
    while IFS= read -r candidate; do
        dir="$(dirname "$candidate")"
        # Verify it looks like ITFlow (has agent/post.php and functions.php)
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

# Resolve to absolute path
ITFLOW="$(cd "$ITFLOW" 2>/dev/null && pwd)" || fatal "Directory does not exist: ${1:-$ITFLOW}"

echo ""
info "ITFlow path: $ITFLOW"
echo ""

# ── Validate ITFlow installation ─────────────────────────────────
info "Validating ITFlow installation..."

REQUIRED_FILES=(
    "config.php"
    "agent/post.php"
    "functions.php"
    "guest"
    "includes"
)

missing=0
for f in "${REQUIRED_FILES[@]}"; do
    if [ ! -e "$ITFLOW/$f" ]; then
        err "Missing: $f"
        missing=1
    fi
done

if [ $missing -eq 1 ]; then
    echo ""
    fatal "This does not appear to be a valid ITFlow installation.
       Expected to find: config.php, agent/post.php, includes/functions.php, etc.
       Please provide the root directory of your ITFlow installation (not a subdirectory)."
fi

# Check for the custom module directory
if [ -d "$ITFLOW/agent/custom" ]; then
    ok "agent/custom/ directory found."
else
    warn "agent/custom/ directory not found. Your ITFlow version may not support custom modules."
    warn "The installer will create it, but the custom sidebar may not work."
fi

# Check that custom/post.php has glob auto-discovery
if [ -f "$ITFLOW/agent/custom/post.php" ] && grep -q 'glob.*post/.*\.php' "$ITFLOW/agent/custom/post.php" 2>/dev/null; then
    ok "agent/custom/post.php uses glob() auto-discovery."
else
    warn "agent/custom/post.php not found or does not use glob()."
    warn "POST handler may need to be registered manually (see README)."
fi

# Check for enforceUserPermission (core function we depend on)
if grep -rql "function enforceUserPermission" "$ITFLOW/includes/" "$ITFLOW/functions.php" 2>/dev/null; then
    ok "enforceUserPermission() found."
else
    warn "Could not find enforceUserPermission() in includes/. Your ITFlow version may be incompatible."
    read -rp "Continue anyway? (y/N): " confirm
    [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || exit 1
fi

# Check for validateCSRFToken
if grep -rql "function validateCSRFToken" "$ITFLOW/includes/" "$ITFLOW/functions.php" 2>/dev/null; then
    ok "validateCSRFToken() found."
else
    warn "Could not find validateCSRFToken() in includes/. Your ITFlow version may be incompatible."
    read -rp "Continue anyway? (y/N): " confirm
    [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || exit 1
fi

# Check for addToMailQueue (ITFlow's email delivery mechanism)
if grep -rql "function addToMailQueue" "$ITFLOW/includes/" "$ITFLOW/functions.php" 2>/dev/null; then
    ok "addToMailQueue() found."
else
    warn "Could not find addToMailQueue(). Email delivery for signing links may not work."
fi

# Check for TCPDF
if [ -d "$ITFLOW/plugins/TCPDF" ]; then
    ok "TCPDF library found."
else
    warn "TCPDF not found at plugins/TCPDF/. PDF export will not work until TCPDF is available."
fi

# Check for TinyMCE
if [ -d "$ITFLOW/plugins/tinymce" ]; then
    ok "TinyMCE library found."
else
    warn "TinyMCE not found at plugins/tinymce/. Rich text editor will not load in document creation."
fi

echo ""

# ── Check PHP ─────────────────────────────────────────────────────
info "Checking PHP..."

if command -v php &>/dev/null; then
    PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    ok "PHP $PHP_VERSION found."

    if php -r "exit(function_exists('finfo_open') ? 0 : 1);" 2>/dev/null; then
        ok "PHP fileinfo extension loaded."
    else
        warn "PHP fileinfo extension not detected. PDF upload MIME validation may fail."
    fi
else
    warn "PHP CLI not found in PATH. Cannot verify version (web server may still have it)."
fi

echo ""

# ── Database credentials ──────────────────────────────────────────
info "Checking database configuration..."

DB_HOST=""
DB_NAME=""
DB_USER=""
DB_PASS=""

if [ -f "$ITFLOW/config.php" ]; then
    # Use PHP itself to reliably extract the DB variables from config.php
    if command -v php &>/dev/null; then
        eval "$(php -r "
            @include('$ITFLOW/config.php');
            // ITFlow uses \$dbhost, \$dbusername, \$dbpassword, \$database
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
fi

RUN_DB_MIGRATION=false

if [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    ok "Found database config: $DB_USER@$DB_HOST/$DB_NAME"

    if command -v mysql &>/dev/null; then
        if mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -e "USE \`$DB_NAME\`" 2>/dev/null; then
            ok "Database connection successful."
            RUN_DB_MIGRATION=true
        else
            warn "Could not connect to database. You will need to run the SQL schema manually."
        fi
    else
        warn "mysql CLI not found. You will need to run the SQL schema manually."
    fi
else
    warn "Could not extract database credentials from config.php."
    warn "You will need to run setup/db_schema.sql against your database manually."
fi

echo ""

# ── Create backup ─────────────────────────────────────────────────
BACKUP_DIR="$ITFLOW/.signable_backup_$(date +%Y%m%d_%H%M%S)"

info "Creating backup at: $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"

# Backup custom_side_nav.php (we will modify it)
if [ -f "$ITFLOW/agent/custom/includes/custom_side_nav.php" ]; then
    mkdir -p "$BACKUP_DIR/agent/custom/includes"
    cp "$ITFLOW/agent/custom/includes/custom_side_nav.php" "$BACKUP_DIR/agent/custom/includes/"
fi

# Backup existing plugin files we might overwrite (both old and new locations)
for f in \
    "includes/functions_signable.php" \
    "agent/custom/signable_documents.php" \
    "agent/custom/signable_document.php" \
    "agent/custom/ajax_signable.php" \
    "agent/custom/post/signable_document.php" \
    "agent/custom/post/signable_document_model.php" \
    "agent/custom/modals/signable_document/signable_document_add.php" \
    "agent/custom/modals/signable_document/signable_document_edit.php" \
    "agent/custom/modals/signable_document/signable_document_send.php" \
    "agent/custom/includes/signable_side_nav_snippet.php" \
    "agent/signable_documents.php" \
    "agent/signable_document.php" \
    "agent/ajax_signable.php" \
    "agent/post/signable_document.php" \
    "agent/post/signable_document_model.php" \
    "agent/modals/signable_document/signable_document_add.php" \
    "agent/modals/signable_document/signable_document_edit.php" \
    "agent/modals/signable_document/signable_document_send.php" \
    "guest/guest_sign_document.php" \
    "guest/guest_download_signed_pdf.php" \
    "js/signature_pad.js"
do
    if [ -f "$ITFLOW/$f" ]; then
        backup_subdir="$(dirname "$f")"
        mkdir -p "$BACKUP_DIR/$backup_subdir"
        cp "$ITFLOW/$f" "$BACKUP_DIR/$f"
    fi
done

ok "Backup created."
echo ""

# ── Migrate from old file locations (pre-custom-folder versions) ──
MIGRATED=false

for old_file in \
    "agent/signable_documents.php" \
    "agent/signable_document.php" \
    "agent/ajax_signable.php" \
    "agent/post/signable_document.php" \
    "agent/post/signable_document_model.php" \
    "agent/modals/signable_document/signable_document_add.php" \
    "agent/modals/signable_document/signable_document_edit.php" \
    "agent/modals/signable_document/signable_document_send.php"
do
    if [ -f "$ITFLOW/$old_file" ]; then
        if [ "$MIGRATED" = false ]; then
            info "Migrating from previous installation (agent/ → agent/custom/)..."
            MIGRATED=true
        fi
        # Already backed up above, safe to remove
        rm "$ITFLOW/$old_file"
        ok "Removed old: $old_file"
    fi
done

# Clean up empty old modal directory
rmdir "$ITFLOW/agent/modals/signable_document" 2>/dev/null || true

# Remove old post.php patch if present (from pre-custom-folder installer)
if grep -qF 'require_once("post/signable_document.php")' "$ITFLOW/agent/post.php" 2>/dev/null; then
    sed -i '/\/\/ ITFlow Document Signing Plugin/d' "$ITFLOW/agent/post.php"
    sed -i '/require_once("post\/signable_document.php")/d' "$ITFLOW/agent/post.php"
    sed -i '/^$/N;/^\n$/d' "$ITFLOW/agent/post.php"
    ok "Removed legacy POST handler registration from agent/post.php"
fi

# Remove old custom_links sidebar entry (now using custom_side_nav.php injection)
if [ "$RUN_DB_MIGRATION" = true ]; then
    if mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" -e \
        "DELETE FROM custom_links WHERE custom_link_uri = 'signable_documents.php'" 2>/dev/null; then
        # Only report if a row was actually deleted
        ROWS_DELETED=$(mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" \
            -sNe "SELECT ROW_COUNT()" 2>/dev/null || echo "0")
        if [ "$ROWS_DELETED" -gt 0 ] 2>/dev/null; then
            ok "Removed old custom_links sidebar entry (now using custom sidebar)"
        fi
    fi
fi

if [ "$MIGRATED" = true ]; then
    ok "Migration complete. Old files removed, backups preserved."
    echo ""
fi

# ── Copy plugin files ─────────────────────────────────────────────
info "Installing plugin files into agent/custom/..."

# Shared functions (stays in includes/ for guest page access)
cp "$SCRIPT_DIR/includes/functions_signable.php" "$ITFLOW/includes/"
ok "includes/functions_signable.php"

# Agent pages (into agent/custom/)
cp "$SCRIPT_DIR/agent/custom/signable_documents.php" "$ITFLOW/agent/custom/"
cp "$SCRIPT_DIR/agent/custom/signable_document.php" "$ITFLOW/agent/custom/"
cp "$SCRIPT_DIR/agent/custom/ajax_signable.php" "$ITFLOW/agent/custom/"
ok "agent/custom/ pages (list, detail, ajax)"

# Modals
mkdir -p "$ITFLOW/agent/custom/modals/signable_document"
cp "$SCRIPT_DIR/agent/custom/modals/signable_document/signable_document_add.php" "$ITFLOW/agent/custom/modals/signable_document/"
cp "$SCRIPT_DIR/agent/custom/modals/signable_document/signable_document_edit.php" "$ITFLOW/agent/custom/modals/signable_document/"
cp "$SCRIPT_DIR/agent/custom/modals/signable_document/signable_document_send.php" "$ITFLOW/agent/custom/modals/signable_document/"
ok "agent/custom/modals/signable_document/"

# POST handler (auto-discovered by agent/custom/post.php's glob())
mkdir -p "$ITFLOW/agent/custom/post"
cp "$SCRIPT_DIR/agent/custom/post/signable_document.php" "$ITFLOW/agent/custom/post/"
cp "$SCRIPT_DIR/agent/custom/post/signable_document_model.php" "$ITFLOW/agent/custom/post/"
ok "agent/custom/post/ handlers (auto-discovered by glob)"

# Sidebar navigation snippet
mkdir -p "$ITFLOW/agent/custom/includes"
cp "$SCRIPT_DIR/agent/custom/includes/signable_side_nav_snippet.php" "$ITFLOW/agent/custom/includes/"
ok "agent/custom/includes/signable_side_nav_snippet.php"

# Guest pages (signing + PDF download)
cp "$SCRIPT_DIR/guest/guest_sign_document.php" "$ITFLOW/guest/"
cp "$SCRIPT_DIR/guest/guest_download_signed_pdf.php" "$ITFLOW/guest/"
ok "guest/ pages (signing + PDF download)"

# Signature pad JS
cp "$SCRIPT_DIR/js/signature_pad.js" "$ITFLOW/js/"
ok "js/signature_pad.js"

echo ""

# ── Inject sidebar navigation ─────────────────────────────────────
info "Setting up sidebar navigation..."

SIDE_NAV="$ITFLOW/agent/custom/includes/custom_side_nav.php"
MARKER="<!-- ITFlow Document Signing Plugin -->"

if [ -f "$SIDE_NAV" ]; then
    if grep -qF "$MARKER" "$SIDE_NAV"; then
        ok "Sidebar navigation already injected (skipped)."
    else
        # Inject our nav snippet before the closing </ul> tag
        SNIPPET=$(cat "$SCRIPT_DIR/agent/custom/includes/signable_side_nav_snippet.php")
        # Use awk to insert before the last </ul>
        awk -v snippet="$SNIPPET" '
            /<\/ul>/ && !done {
                print snippet
                done = 1
            }
            { print }
        ' "$SIDE_NAV" > "${SIDE_NAV}.tmp" && mv "${SIDE_NAV}.tmp" "$SIDE_NAV"
        ok "Sidebar navigation injected into custom_side_nav.php"
    fi
else
    info "custom_side_nav.php not found — creating it with plugin navigation."
    SNIPPET=$(cat "$SCRIPT_DIR/agent/custom/includes/signable_side_nav_snippet.php")
    cat > "$SIDE_NAV" <<NAVEOF
$SNIPPET
NAVEOF
    ok "Created custom_side_nav.php with plugin navigation."
fi

echo ""

# ── Create uploads directory ──────────────────────────────────────
info "Creating uploads directory..."

UPLOAD_DIR="$ITFLOW/uploads/signable_documents"
mkdir -p "$UPLOAD_DIR"

# Match ownership and permissions of existing uploads dir
if [ -d "$ITFLOW/uploads" ]; then
    EXISTING_OWNER="$(stat -c '%U:%G' "$ITFLOW/uploads" 2>/dev/null || true)"
    if [ -n "$EXISTING_OWNER" ] && [ "$EXISTING_OWNER" != ":" ]; then
        if chown -R "$EXISTING_OWNER" "$UPLOAD_DIR" 2>/dev/null; then
            ok "Upload directory ownership set to $EXISTING_OWNER"
        else
            warn "Could not set ownership to $EXISTING_OWNER (may need sudo)."
            info "Run: sudo chown -R $EXISTING_OWNER $UPLOAD_DIR"
        fi
    fi
fi

chmod 750 "$UPLOAD_DIR" 2>/dev/null || warn "Could not set permissions on upload dir (may need sudo)."

# Verify web server can write
if [ -w "$UPLOAD_DIR" ]; then
    ok "Upload directory is writable."
else
    warn "Upload directory may not be writable by the web server."
    info "Run: sudo chown -R www-data:www-data $UPLOAD_DIR && sudo chmod 750 $UPLOAD_DIR"
fi
ok "Upload directory: $UPLOAD_DIR"

echo ""

# ── Run database migration ────────────────────────────────────────
if [ "$RUN_DB_MIGRATION" = true ]; then
    info "Running database migration..."

    # Check if tables already exist
    TABLE_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" \
        -sNe "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='signable_documents'" 2>/dev/null || echo "0")

    if [ "$TABLE_EXISTS" -gt 0 ] 2>/dev/null; then
        ok "Database tables already exist (skipped table creation)."
    else
        if mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" < "$SCRIPT_DIR/setup/db_schema.sql" 2>/dev/null; then
            ok "Database tables created successfully."
        else
            err "Database migration failed."
            warn "Please run manually: mysql -u USER -p DATABASE < $SCRIPT_DIR/setup/db_schema.sql"
        fi
    fi
else
    warn "Skipping database migration (no database connection)."
    echo -e "  Run manually: ${BOLD}mysql -u USER -p DATABASE < $SCRIPT_DIR/setup/db_schema.sql${NC}"
fi

echo ""

# ── Verify installation ──────────────────────────────────────────
info "Verifying installation..."
echo ""

verify_ok=0
verify_fail=0

check_file() {
    if [ -f "$ITFLOW/$1" ]; then
        ok "$1"
        ((verify_ok++))
    else
        err "$1 MISSING"
        ((verify_fail++))
    fi
}

check_file "includes/functions_signable.php"
check_file "agent/custom/signable_documents.php"
check_file "agent/custom/signable_document.php"
check_file "agent/custom/ajax_signable.php"
check_file "agent/custom/post/signable_document.php"
check_file "agent/custom/post/signable_document_model.php"
check_file "agent/custom/modals/signable_document/signable_document_add.php"
check_file "agent/custom/modals/signable_document/signable_document_edit.php"
check_file "agent/custom/modals/signable_document/signable_document_send.php"
check_file "agent/custom/includes/signable_side_nav_snippet.php"
check_file "guest/guest_sign_document.php"
check_file "guest/guest_download_signed_pdf.php"
check_file "js/signature_pad.js"

# Check sidebar injection
if [ -f "$ITFLOW/agent/custom/includes/custom_side_nav.php" ] && grep -qF "$MARKER" "$ITFLOW/agent/custom/includes/custom_side_nav.php"; then
    ok "Sidebar navigation injected"
    ((verify_ok++))
else
    warn "Sidebar navigation not injected (may need manual setup)"
fi

# Check custom post handler
if [ -f "$ITFLOW/agent/custom/post.php" ] && grep -q 'glob.*post/.*\.php' "$ITFLOW/agent/custom/post.php" 2>/dev/null; then
    ok "POST handler auto-discovered via agent/custom/post.php glob()"
    ((verify_ok++))
else
    warn "agent/custom/post.php glob() not detected"
fi

# Check uploads dir
if [ -d "$ITFLOW/uploads/signable_documents" ]; then
    ok "Upload directory exists"
    ((verify_ok++))
else
    err "Upload directory missing"
    ((verify_fail++))
fi

# Check DB tables
if [ "$RUN_DB_MIGRATION" = true ]; then
    for table in signable_documents signable_document_signatures signable_document_history; do
        exists=$(mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" \
            -sNe "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='$table'" 2>/dev/null || echo "0")
        if [ "$exists" -gt 0 ] 2>/dev/null; then
            ok "Database table: $table"
            ((verify_ok++))
        else
            err "Database table missing: $table"
            ((verify_fail++))
        fi
    done
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

if [ $verify_fail -eq 0 ]; then
    echo -e "${GREEN}${BOLD}Installation complete!${NC}  ($verify_ok checks passed)"
    echo ""
    echo "Next steps:"
    echo "  1. Log in to ITFlow as an agent with sales module access"
    echo "  2. Navigate to the Custom section in ITFlow's sidebar"
    echo "  3. Look for 'Document Signing' in the custom sidebar"
    echo "  4. Create a test document and try the signing flow"
    echo ""
    echo -e "Backup saved to: ${CYAN}$BACKUP_DIR${NC}"
    echo -e "To verify later: ${CYAN}./verify.sh $ITFLOW${NC}"
    echo -e "To uninstall:    ${CYAN}./uninstall.sh $ITFLOW${NC}"
else
    echo -e "${YELLOW}${BOLD}Installation completed with $verify_fail issue(s).${NC}"
    echo "Review the errors above and fix manually."
    echo ""
    echo -e "Backup saved to: ${CYAN}$BACKUP_DIR${NC}"
fi

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
