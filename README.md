# ITFlow Document Signing Plugin

An electronic document signing plugin for [ITFlow](https://itflow.org), the open-source IT documentation and business management platform for MSPs. This plugin lets agents create documents and send them to clients for electronic signature via a secure web link.

## Features

- **Document creation** with rich text editor (TinyMCE) or PDF upload
- **Guest signing page** accessible via a secure, unique URL (no login required)
- **Canvas-based signature capture** with mouse and touch support
- **Status lifecycle**: Draft → Sent → Viewed → Signed / Declined / Expired
- **PDF export** with embedded signatures via TCPDF
- **Email delivery** of signing links through ITFlow's existing mail system
- **Full audit trail** with timestamped history, IP addresses, and user agents
- **SHA-256 integrity hashing** for each signature (document + signature + email + timestamp)
- **Agent notifications** when a document is viewed, signed, or declined
- **CSRF protection** on all forms and state-changing operations
- **RBAC integration** using ITFlow's existing `module_sales` permissions

## Important: Plugin or Core Modification?

**This is a hybrid -- 95% plugin, 5% core-adjacent.** Understanding this distinction matters for maintenance:

- **Plugin-like (safe)**: Adds new standalone PHP pages, new database tables, new JS, and new shared functions. Does not alter any existing ITFlow table schemas or core PHP files.
- **POST handler**: Automatically discovered by ITFlow's `agent/post.php` via its `glob("post/*.php")` pattern. No core file editing needed.
- **Sidebar link**: Added via ITFlow's `custom_links` database table. No core file editing needed.
- **Files in tracked directories**: Plugin files are placed in `agent/`, `includes/`, `guest/`, and `js/`. These directories are tracked by git, but since the plugin uses unique filenames that don't exist in ITFlow core, a `git pull` update will not overwrite them.

**No core ITFlow files are modified by this plugin.**

## Compatibility Warnings

### ITFlow Version Requirements

This plugin was developed against ITFlow 0.6+. It depends on these ITFlow core functions and patterns:

| Dependency | Used For | Risk If Changed |
|-----------|----------|----------------|
| `enforceUserPermission()` | RBAC access control on all agent pages | All agent pages will break |
| `validateCSRFToken()` | CSRF protection on all POST actions | All form submissions will break |
| `addToMailQueue()` | Sending signing link emails | Email delivery will break |
| `includes/inc_all.php` | Standard agent page bootstrapping | All agent pages will break |
| `config.php` | Database connection, base URL, SMTP config | Everything will break |
| `$config_base_url` | Building guest signing URLs | Signing links will be wrong |
| `$session_user_id`, `$session_name` | Audit trail attribution | History entries will be incomplete |

### What Could Break After an ITFlow Update

1. **Function signature changes**: If ITFlow renames or changes the parameters of `enforceUserPermission()`, `validateCSRFToken()`, or `sendSingleEmail()`, the plugin will break. Run `./verify.sh` after every update.
2. **Include path restructuring**: If `includes/inc_all.php` or `config.php` move, all plugin pages will fail to load.
3. **Database schema changes**: The plugin JOINs against ITFlow core tables (`clients`, `contacts`, `companies`, `notifications`). If these table names or column names change, queries will fail.
4. **AdminLTE/Bootstrap version upgrades**: The plugin uses Bootstrap 4 classes and AdminLTE markup. A major UI framework upgrade would break the layout.
5. **TCPDF removal or relocation**: If `plugins/TCPDF/` is removed or moved, PDF export will break.
6. **TinyMCE removal or relocation**: If `plugins/tinymce/` is removed or moved, the rich text editor in document creation will not load.
7. **`custom_links` table changes**: If ITFlow changes how sidebar custom links work, the navigation entry may disappear.
8. **`glob()` auto-discovery removal**: If ITFlow stops using `glob("post/*.php")` in `agent/post.php`, the POST handler will not load. The installer detects this and falls back to manual registration.

### After Every ITFlow Update

Run the verification script to check that nothing is broken:

```bash
./verify.sh /path/to/itflow
```

This checks all files, dependencies, database tables, and integration points. If something fails, re-running `./install.sh` will typically fix it.

## Installation

### Automated Install (Recommended)

The installer validates your ITFlow installation, copies all files, runs the database migration, and verifies everything:

```bash
git clone https://github.com/life256/ITFlow_Doc_Sign.git
cd ITFlow_Doc_Sign
chmod +x install.sh
./install.sh
```

If no path is provided, the installer will automatically search `/var/www`, `/srv/www`, and other common web directories for an ITFlow installation and ask you to confirm the detected location. You can also pass a path directly: `./install.sh /your/itflow/path`

The installer will:
- Auto-detect your ITFlow installation (or accept a path argument)
- Verify the target directory is a real ITFlow installation
- Check for required PHP extensions and ITFlow core functions
- Back up any files it overwrites (to `.signable_backup_YYYYMMDD_HHMMSS/`)
- Copy all plugin files into the correct locations
- Create the database tables (if MySQL CLI is available)
- Add a "Signable Documents" sidebar link via the `custom_links` table
- Create the uploads directory with proper permissions
- Run a full verification check

**The installer is idempotent** -- safe to run multiple times.

### Prerequisites

- A working ITFlow installation (tested with ITFlow 0.6+)
- MariaDB/MySQL database access
- PHP with `finfo` extension (usually enabled by default)

### Manual Install

If you prefer to install manually or the automated installer doesn't work for your environment:

#### Step 1: Run the Database Schema

```bash
mysql -u itflow_user -p itflow_database < setup/db_schema.sql
```

Or copy the contents of `setup/db_schema.sql` into phpMyAdmin.

#### Step 2: Copy Files into ITFlow

```bash
ITFLOW=/path/to/your/itflow

cp includes/functions_signable.php            $ITFLOW/includes/
cp agent/signable_documents.php               $ITFLOW/agent/
cp agent/signable_document.php                $ITFLOW/agent/
cp agent/ajax_signable.php                    $ITFLOW/agent/

mkdir -p $ITFLOW/agent/modals/signable_document
cp agent/modals/signable_document/*.php       $ITFLOW/agent/modals/signable_document/

cp agent/post/signable_document.php           $ITFLOW/agent/post/
cp agent/post/signable_document_model.php     $ITFLOW/agent/post/

cp guest/guest_sign_document.php              $ITFLOW/guest/
cp js/signature_pad.js                        $ITFLOW/js/
```

#### Step 3: Create the Upload Directory

```bash
mkdir -p $ITFLOW/uploads/signable_documents
chown www-data:www-data $ITFLOW/uploads/signable_documents
chmod 770 $ITFLOW/uploads/signable_documents
```

Adjust `www-data` to match your web server user (e.g., `apache`, `nginx`).

#### Step 4: Verify POST Handler Auto-Discovery

ITFlow's `agent/post.php` uses `glob("post/*.php")` to auto-discover POST handlers. Since the plugin places `signable_document.php` in `agent/post/`, it is loaded automatically. **No editing of `post.php` is needed.**

To verify, check that `agent/post.php` contains a line like:
```php
foreach (glob("post/*.php") as $user_module) {
```

If your ITFlow version does NOT use glob (very old versions), add this line to `agent/post.php`:
```php
require_once("post/signable_document.php");
```

#### Step 5: Add Sidebar Navigation

The database schema includes an INSERT into ITFlow's `custom_links` table that adds the sidebar entry. If you ran the schema in Step 1, this is already done.

If the link doesn't appear, add it manually via SQL:
```sql
INSERT INTO custom_links (custom_link_name, custom_link_url, custom_link_icon, custom_link_target)
VALUES ('Signable Documents', '/agent/signable_documents.php', 'fas fa-file-signature', '_self');
```

#### Step 6: Test the Installation

1. Log in to ITFlow as an agent with sales module write access.
2. Look for "Signable Documents" in the sidebar.
3. Click "New Document" to create a test document.
4. Use "Send for Signature" to email yourself the signing link.
5. Open the link and verify the signing page loads correctly.
6. Sign the document and confirm the signature appears in the agent detail view.

## Uninstall

```bash
./uninstall.sh
```

The uninstaller will auto-detect your ITFlow installation and ask you to confirm. You can also pass a path directly: `./uninstall.sh /your/itflow/path`

This removes all plugin files and the sidebar link. Database tables are preserved by default (to protect signing data). To also drop tables:

```bash
./uninstall.sh --drop-tables
```

## How It Works

### Architecture

This plugin follows ITFlow's existing procedural, file-based architecture. There is no separate framework -- it uses the same patterns as ITFlow core: PHP files for pages, Bootstrap/AdminLTE modals for forms, a centralized POST handler for actions, and raw `mysqli` for database access.

### Document Lifecycle

1. **Agent creates a document** via the "New Document" modal on the Signable Documents list page. A 64-character cryptographic URL key is generated automatically.
2. **Agent sends the document** using the "Send for Signature" action. This emails the client a link to the guest signing page and updates the status to "Sent".
3. **Client opens the link** (`/guest/guest_sign_document.php?signable_document_id=X&url_key=Y`). The status updates to "Viewed" and the agent receives a notification.
4. **Client signs the document** by entering their name, email, drawing their signature on a canvas, and checking the consent box. The signature (as a PNG data URL), signer identity, IP address, user agent, and an SHA-256 integrity hash are all recorded.
5. **Client may alternatively decline** the document, which sets the status to "Declined" and notifies agents.
6. **Agent can export a PDF** at any time that includes the document content and any collected signatures.

### Key Components

| Component | File | Purpose |
|-----------|------|---------|
| List page | `agent/signable_documents.php` | Filterable, paginated list of all signable documents |
| Detail page | `agent/signable_document.php` | View document content, signatures, history, and actions |
| Add modal | `agent/modals/signable_document/signable_document_add.php` | Create new document (TinyMCE editor, client/contact select, PDF upload) |
| Edit modal | `agent/modals/signable_document/signable_document_edit.php` | Edit draft documents |
| Send modal | `agent/modals/signable_document/signable_document_send.php` | Email document link with customizable subject/body |
| POST handler | `agent/post/signable_document.php` | All server-side actions: create, edit, send, archive, PDF export |
| Input model | `agent/post/signable_document_model.php` | Input sanitization and validation |
| AJAX handler | `agent/ajax_signable.php` | JSON API for populating edit/send modals |
| Guest page | `guest/guest_sign_document.php` | Public signing page (no auth, validated by URL key) |
| Shared functions | `includes/functions_signable.php` | Status badges, history logging, hash generation, URL key generation |
| Signature pad | `js/signature_pad.js` | Lightweight canvas signature capture (mouse + touch, velocity-based line width) |
| DB schema | `setup/db_schema.sql` | Three tables + sidebar link |

### Database Tables

**`signable_documents`** -- The main document record. Stores title, description, HTML content, status, URL key, dates, and links to client/contact/quote.

**`signable_document_signatures`** -- Each signature collected. Stores signer name, email, IP, user agent, the signature image (base64 PNG data URL), and an SHA-256 integrity hash.

**`signable_document_history`** -- Audit log. Every status change (Created, Sent, Viewed, Signed, Declined, Archived, Expired) is recorded with timestamp, description, and IP address.

### Security Model

- **Guest access**: Documents are accessed via a 64-character hex URL key (generated with `random_bytes(32)`). No authentication is required -- the key itself serves as the access credential.
- **Agent access**: All agent pages require login and check `enforceUserPermission('module_sales', level)` where level 1 = read, 2 = write, 3 = delete/archive.
- **CSRF protection**: All POST forms include a `csrf_token` validated by ITFlow's `validateCSRFToken()`.
- **Input sanitization**: All inputs are sanitized via `intval()`, `strip_tags()`, `mysqli_real_escape_string()`, and `filter_var()`. HTML content uses HTMLPurifier when available, or an allowlist of safe tags as fallback.
- **File uploads**: PDF uploads are validated by both file extension AND MIME type (`finfo_file()`). Filenames are sanitized to alphanumeric characters only.
- **Signature data**: Limited to 500KB and must begin with `data:image/` prefix.
- **Audit trail**: IP addresses and user agents are recorded for every signature and status change.

## File Structure

```
ITFlow_Doc_Sign/
├── README.md                                   # This file
├── install.sh                                  # Automated installer
├── uninstall.sh                                # Automated uninstaller
├── verify.sh                                   # Post-upgrade verification
├── setup/
│   └── db_schema.sql                           # Database migration (3 tables + sidebar link)
├── includes/
│   └── functions_signable.php                  # Shared PHP functions
├── agent/
│   ├── signable_documents.php                  # Document list page
│   ├── signable_document.php                   # Document detail page
│   ├── ajax_signable.php                       # AJAX endpoint for modals
│   ├── post/
│   │   ├── signable_document.php               # POST handler (CRUD, send, archive, PDF)
│   │   └── signable_document_model.php         # Input sanitization
│   └── modals/signable_document/
│       ├── signable_document_add.php           # Create document modal
│       ├── signable_document_edit.php          # Edit document modal
│       └── signable_document_send.php          # Send for signature modal
├── guest/
│   └── guest_sign_document.php                 # Public guest signing page
├── js/
│   └── signature_pad.js                        # Canvas signature capture library
└── uploads/
    └── signable_documents/                     # PDF upload storage (created during install)
```

## Custom Action Hooks

The plugin fires `customAction()` at three lifecycle points, allowing you to extend behavior via ITFlow's custom action handler (`/custom/custom_action_handler.php`):

| Hook Name | Trigger | Parameter |
|-----------|---------|-----------|
| `signable_document_create` | After a new document is created | `$signable_document_id` |
| `signable_document_send` | After a document is emailed for signing | `$signable_document_id` |
| `signable_document_signed` | After a guest signs a document | `$signable_document_id` |

## Dependencies

This plugin relies on ITFlow core libraries that are already included in any standard ITFlow installation:

- **TCPDF** (`/plugins/TCPDF/`) -- PDF generation
- **TinyMCE** (`/plugins/tinymce/`) -- Rich text editing
- **HTMLPurifier** (`/plugins/HTMLPurifier/`) -- XSS prevention (optional but recommended)
- **PHPMailer** -- Email delivery (via ITFlow's `addToMailQueue()`)
- **AdminLTE / Bootstrap 4** -- UI framework
- **jQuery** -- DOM manipulation and AJAX

No additional third-party libraries need to be installed. The signature pad (`js/signature_pad.js`) is a self-contained, dependency-free implementation included with this plugin.

## License

This plugin is designed to work with ITFlow, which is licensed under the GPL-3.0 license.
