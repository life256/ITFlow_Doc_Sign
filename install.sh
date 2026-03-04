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
#   4. Copies all plugin files into place
#   5. Runs the database migration (tables + sidebar link)
#   6. Creates the uploads directory with proper permissions
#   7. Verifies the installation
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

# ── Get ITFlow path ───────────────────────────────────────────────
ITFLOW="${1:-}"

if [ -z "$ITFLOW" ]; then
    echo ""
    echo -e "${BOLD}ITFlow Document Signing Plugin - Installer${NC}"
    echo ""
    read -rp "Enter the full path to your ITFlow installation: " ITFLOW
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
    "includes/functions.php"
    "guest"
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

# Verify post.php uses glob auto-discovery (expected in ITFlow 0.6+)
if grep -q 'glob.*post/.*\.php' "$ITFLOW/agent/post.php" 2>/dev/null; then
    ok "agent/post.php uses glob() auto-discovery (no patching needed)."
    POST_GLOB=true
else
    warn "agent/post.php does not appear to use glob() auto-discovery."
    warn "The POST handler may need to be registered manually (see README)."
    POST_GLOB=false
fi

# Check for enforceUserPermission (core function we depend on)
if grep -rql "function enforceUserPermission" "$ITFLOW/includes/" 2>/dev/null; then
    ok "enforceUserPermission() found."
else
    warn "Could not find enforceUserPermission() in includes/. Your ITFlow version may be incompatible."
    read -rp "Continue anyway? (y/N): " confirm
    [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || exit 1
fi

# Check for validateCSRFToken
if grep -rql "function validateCSRFToken" "$ITFLOW/includes/" 2>/dev/null; then
    ok "validateCSRFToken() found."
else
    warn "Could not find validateCSRFToken() in includes/. Your ITFlow version may be incompatible."
    read -rp "Continue anyway? (y/N): " confirm
    [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || exit 1
fi

# Check for sendSingleEmail
if grep -rql "function sendSingleEmail" "$ITFLOW/includes/" 2>/dev/null; then
    ok "sendSingleEmail() found."
else
    warn "Could not find sendSingleEmail(). Email delivery for signing links may not work."
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

    if php -m 2>/dev/null | grep -qi "fileinfo"; then
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
    # ITFlow stores DB config as PHP define() constants
    DB_HOST="$(grep -oP "(?<=['\"]DATABASE_HOST['\"]\\s*,\\s*['\"])[^'\"]*" "$ITFLOW/config.php" 2>/dev/null || true)"
    DB_NAME="$(grep -oP "(?<=['\"]DATABASE_NAME['\"]\\s*,\\s*['\"])[^'\"]*" "$ITFLOW/config.php" 2>/dev/null || true)"
    DB_USER="$(grep -oP "(?<=['\"]DATABASE_USERNAME['\"]\\s*,\\s*['\"])[^'\"]*" "$ITFLOW/config.php" 2>/dev/null || true)"
    DB_PASS="$(grep -oP "(?<=['\"]DATABASE_PASSWORD['\"]\\s*,\\s*['\"])[^'\"]*" "$ITFLOW/config.php" 2>/dev/null || true)"

    # Fallback: try $db_ variable style (older ITFlow)
    if [ -z "$DB_HOST" ]; then
        DB_HOST="$(grep -oP '(?<=\$db_host\s*=\s*["\x27])[^"\x27]*' "$ITFLOW/config.php" 2>/dev/null || true)"
        DB_NAME="$(grep -oP '(?<=\$db_name\s*=\s*["\x27])[^"\x27]*' "$ITFLOW/config.php" 2>/dev/null || true)"
        DB_USER="$(grep -oP '(?<=\$db_username\s*=\s*["\x27])[^"\x27]*' "$ITFLOW/config.php" 2>/dev/null || true)"
        DB_PASS="$(grep -oP '(?<=\$db_password\s*=\s*["\x27])[^"\x27]*' "$ITFLOW/config.php" 2>/dev/null || true)"
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

# Backup post.php in case we need to patch it
cp "$ITFLOW/agent/post.php" "$BACKUP_DIR/post.php"

# Backup existing files we might overwrite
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
    if [ -f "$ITFLOW/$f" ]; then
        backup_subdir="$(dirname "$f")"
        mkdir -p "$BACKUP_DIR/$backup_subdir"
        cp "$ITFLOW/$f" "$BACKUP_DIR/$f"
    fi
done

ok "Backup created."
echo ""

# ── Copy plugin files ─────────────────────────────────────────────
info "Installing plugin files..."

# Shared functions
cp "$SCRIPT_DIR/includes/functions_signable.php" "$ITFLOW/includes/"
ok "includes/functions_signable.php"

# Agent pages
cp "$SCRIPT_DIR/agent/signable_documents.php" "$ITFLOW/agent/"
cp "$SCRIPT_DIR/agent/signable_document.php" "$ITFLOW/agent/"
cp "$SCRIPT_DIR/agent/ajax_signable.php" "$ITFLOW/agent/"
ok "agent/ pages (list, detail, ajax)"

# Modals
mkdir -p "$ITFLOW/agent/modals/signable_document"
cp "$SCRIPT_DIR/agent/modals/signable_document/signable_document_add.php" "$ITFLOW/agent/modals/signable_document/"
cp "$SCRIPT_DIR/agent/modals/signable_document/signable_document_edit.php" "$ITFLOW/agent/modals/signable_document/"
cp "$SCRIPT_DIR/agent/modals/signable_document/signable_document_send.php" "$ITFLOW/agent/modals/signable_document/"
ok "agent/modals/signable_document/"

# POST handler (auto-discovered by post.php's glob() — no patching needed)
cp "$SCRIPT_DIR/agent/post/signable_document.php" "$ITFLOW/agent/post/"
cp "$SCRIPT_DIR/agent/post/signable_document_model.php" "$ITFLOW/agent/post/"
ok "agent/post/ handlers (auto-discovered by glob)"

# Guest signing page
cp "$SCRIPT_DIR/guest/guest_sign_document.php" "$ITFLOW/guest/"
ok "guest/guest_sign_document.php"

# Signature pad JS
cp "$SCRIPT_DIR/js/signature_pad.js" "$ITFLOW/js/"
ok "js/signature_pad.js"

echo ""

# ── Handle sidebar navigation ────────────────────────────────────
# ITFlow's main sidebar does NOT auto-include custom_side_nav.php.
# The correct approach is to insert a link via the custom_links database table,
# which the installer handles in the DB migration step below.
# We do NOT patch side_nav.php directly — that would be a core modification
# that gets overwritten on every ITFlow update.
info "Sidebar navigation will be added via the database (custom_links table)."

echo ""

# ── Fallback: patch post.php if glob is not available ─────────────
if [ "$POST_GLOB" = false ]; then
    info "Patching agent/post.php (no glob auto-discovery detected)..."

    POST_FILE="$ITFLOW/agent/post.php"
    REQUIRE_LINE='require_once("post/signable_document.php");'

    if grep -qF "$REQUIRE_LINE" "$POST_FILE"; then
        ok "POST handler already registered (skipped)."
    else
        LAST_REQUIRE_LINE=$(grep -n 'require_once.*post/' "$POST_FILE" | tail -1 | cut -d: -f1)
        if [ -n "$LAST_REQUIRE_LINE" ]; then
            sed -i "${LAST_REQUIRE_LINE}a\\
\\n// ITFlow Document Signing Plugin\\n${REQUIRE_LINE}" "$POST_FILE"
            ok "POST handler registered after line $LAST_REQUIRE_LINE."
        else
            echo "" >> "$POST_FILE"
            echo "// ITFlow Document Signing Plugin" >> "$POST_FILE"
            echo "$REQUIRE_LINE" >> "$POST_FILE"
            ok "POST handler appended to end of post.php."
        fi
    fi
    echo ""
fi

# ── Create uploads directory ──────────────────────────────────────
info "Creating uploads directory..."

UPLOAD_DIR="$ITFLOW/uploads/signable_documents"
mkdir -p "$UPLOAD_DIR"

# Match ownership of existing uploads dir
if [ -d "$ITFLOW/uploads" ]; then
    EXISTING_OWNER="$(stat -c '%U:%G' "$ITFLOW/uploads" 2>/dev/null || true)"
    if [ -n "$EXISTING_OWNER" ] && [ "$EXISTING_OWNER" != ":" ]; then
        if chown "$EXISTING_OWNER" "$UPLOAD_DIR" 2>/dev/null; then
            ok "Upload directory ownership set to $EXISTING_OWNER"
        else
            warn "Could not set ownership to $EXISTING_OWNER (may need sudo)."
            info "Run: sudo chown $EXISTING_OWNER $UPLOAD_DIR"
        fi
    fi
fi

chmod 770 "$UPLOAD_DIR" 2>/dev/null || warn "Could not set permissions on upload dir (may need sudo)."
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

    # Add sidebar link via custom_links (even if tables already existed)
    LINK_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" \
        -sNe "SELECT COUNT(*) FROM custom_links WHERE custom_link_url = '/agent/signable_documents.php'" 2>/dev/null || echo "0")

    if [ "$LINK_EXISTS" -gt 0 ] 2>/dev/null; then
        ok "Sidebar link already exists in custom_links."
    else
        if mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" -e "
            INSERT INTO custom_links (custom_link_name, custom_link_url, custom_link_icon, custom_link_target)
            VALUES ('Signable Documents', '/agent/signable_documents.php', 'fas fa-file-signature', '_self')" 2>/dev/null; then
            ok "Sidebar link added to custom_links table."
        else
            warn "Could not insert sidebar link. The custom_links table may not exist in your ITFlow version."
            warn "You may need to add 'Signable Documents' to your sidebar manually."
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
check_file "agent/signable_documents.php"
check_file "agent/signable_document.php"
check_file "agent/ajax_signable.php"
check_file "agent/post/signable_document.php"
check_file "agent/post/signable_document_model.php"
check_file "agent/modals/signable_document/signable_document_add.php"
check_file "agent/modals/signable_document/signable_document_edit.php"
check_file "agent/modals/signable_document/signable_document_send.php"
check_file "guest/guest_sign_document.php"
check_file "js/signature_pad.js"

# Check post handler registration
if [ "$POST_GLOB" = true ]; then
    ok "POST handler auto-discovered via glob() (no registration needed)"
    ((verify_ok++))
elif grep -qF 'require_once("post/signable_document.php")' "$ITFLOW/agent/post.php"; then
    ok "POST handler registered in agent/post.php"
    ((verify_ok++))
else
    err "POST handler NOT registered and glob() not detected"
    ((verify_fail++))
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
    echo "  2. Look for 'Signable Documents' in the sidebar"
    echo "  3. Create a test document and try the signing flow"
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
