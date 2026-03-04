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
| Sidebar nav | `agent/custom/includes/custom_side_nav.php` | Adds "Signable Documents" link to the agent sidebar |
| DB schema | `setup/db_schema.sql` | Three tables: `signable_documents`, `signable_document_signatures`, `signable_document_history` |

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

## Installation

### Prerequisites

- A working ITFlow installation (tested with ITFlow 0.6+)
- MariaDB/MySQL database access
- PHP with `finfo` extension (usually enabled by default)

### Step 1: Run the Database Schema

Execute the SQL file against your ITFlow database to create the three required tables:

```bash
mysql -u itflow_user -p itflow_database < setup/db_schema.sql
```

Or copy the contents of `setup/db_schema.sql` into phpMyAdmin or another database tool.

### Step 2: Copy Files into ITFlow

Copy all plugin files into the matching locations in your ITFlow installation:

```bash
ITFLOW=/path/to/your/itflow

# Shared functions
cp includes/functions_signable.php $ITFLOW/includes/

# Agent pages
cp agent/signable_documents.php $ITFLOW/agent/
cp agent/signable_document.php  $ITFLOW/agent/
cp agent/ajax_signable.php      $ITFLOW/agent/

# Modals
mkdir -p $ITFLOW/agent/modals/signable_document
cp agent/modals/signable_document/*.php $ITFLOW/agent/modals/signable_document/

# POST handler
cp agent/post/signable_document.php       $ITFLOW/agent/post/
cp agent/post/signable_document_model.php $ITFLOW/agent/post/

# Guest signing page
cp guest/guest_sign_document.php $ITFLOW/guest/

# Signature pad JavaScript
cp js/signature_pad.js $ITFLOW/js/

# Sidebar navigation (creates the custom includes dir if needed)
mkdir -p $ITFLOW/agent/custom/includes
cp agent/custom/includes/custom_side_nav.php $ITFLOW/agent/custom/includes/
```

### Step 3: Create the Upload Directory

```bash
mkdir -p $ITFLOW/uploads/signable_documents
chown www-data:www-data $ITFLOW/uploads/signable_documents
chmod 770 $ITFLOW/uploads/signable_documents
```

Adjust `www-data` to match your web server user (e.g., `apache`, `nginx`, `www-data`).

### Step 4: Register the POST Handler

Edit `agent/post.php` in your ITFlow installation and add this line alongside the other `require_once` statements:

```php
require_once("post/signable_document.php");
```

This tells ITFlow's central POST dispatcher to load the document signing handler.

### Step 5: Verify Sidebar Navigation

ITFlow automatically includes `agent/custom/includes/custom_side_nav.php` if it exists. After copying the file in Step 2, the "Signable Documents" link should appear in the agent sidebar. If your ITFlow installation already has a `custom_side_nav.php`, append the contents of this plugin's file to yours.

### Step 6: Test the Installation

1. Log in to ITFlow as an agent with sales module write access.
2. Look for "Signable Documents" in the sidebar.
3. Click "New Document" to create a test document.
4. Use "Send for Signature" to email yourself the signing link.
5. Open the link and verify the signing page loads correctly.
6. Sign the document and confirm the signature appears in the agent detail view.

## File Structure

```
ITFlow_Doc_Sign/
├── README.md                                   # This file
├── setup/
│   └── db_schema.sql                           # Database migration (3 tables)
├── includes/
│   └── functions_signable.php                  # Shared PHP functions
├── agent/
│   ├── signable_documents.php                  # Document list page
│   ├── signable_document.php                   # Document detail page
│   ├── ajax_signable.php                       # AJAX endpoint for modals
│   ├── post/
│   │   ├── signable_document.php               # POST handler (CRUD, send, archive, PDF)
│   │   └── signable_document_model.php         # Input sanitization
│   ├── modals/signable_document/
│   │   ├── signable_document_add.php           # Create document modal
│   │   ├── signable_document_edit.php          # Edit document modal
│   │   └── signable_document_send.php          # Send for signature modal
│   └── custom/includes/
│       └── custom_side_nav.php                 # Sidebar navigation entry
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
- **PHPMailer** -- Email delivery (via ITFlow's `sendSingleEmail()`)
- **AdminLTE / Bootstrap 4** -- UI framework
- **jQuery** -- DOM manipulation and AJAX

No additional third-party libraries need to be installed. The signature pad (`js/signature_pad.js`) is a self-contained, dependency-free implementation included with this plugin.

## License

This plugin is designed to work with ITFlow, which is licensed under the GPL-3.0 license.
