# ITFlow Document Signing Plugin - Installation Guide

## Overview

This plugin adds electronic document signing capabilities to ITFlow. It allows MSP agents to create documents that clients can sign electronically via a secure guest link.

## Features

- Create signable documents (custom, quotes, MSAs, contracts)
- Rich HTML content editor (TinyMCE) or PDF upload
- Secure guest signing page with URL key authentication
- Canvas-based signature capture with touch support
- SHA-256 integrity hashing for each signature
- Full audit trail (history log with IP addresses)
- PDF export with embedded signatures (TCPDF)
- Email delivery of signing links
- Status lifecycle: Draft → Sent → Viewed → Signed/Declined/Expired
- Agent notifications on view/sign events

## Installation

### 1. Database Setup

Run the SQL schema against your ITFlow database:

```bash
mysql -u your_user -p your_itflow_db < setup/db_schema.sql
```

### 2. Copy Plugin Files

Copy the following into your ITFlow installation:

```
# Agent pages
cp agent/signable_documents.php  /path/to/itflow/agent/
cp agent/signable_document.php   /path/to/itflow/agent/

# Modals
cp -r agent/modals/signable_document/ /path/to/itflow/agent/modals/

# POST handler
cp agent/post/signable_document.php       /path/to/itflow/agent/post/
cp agent/post/signable_document_model.php /path/to/itflow/agent/post/

# AJAX handler - merge into existing ajax.php or copy standalone
cp agent/ajax_signable.php /path/to/itflow/agent/

# Guest signing page
cp guest/guest_sign_document.php /path/to/itflow/guest/

# Shared functions
cp includes/functions_signable.php /path/to/itflow/includes/

# Signature pad JS
cp js/signature_pad.js /path/to/itflow/js/

# Sidebar navigation
cp agent/custom/includes/custom_side_nav.php /path/to/itflow/agent/custom/includes/
```

### 3. Create Upload Directory

```bash
mkdir -p /path/to/itflow/uploads/signable_documents
chown www-data:www-data /path/to/itflow/uploads/signable_documents
chmod 770 /path/to/itflow/uploads/signable_documents
```

### 4. Integration Points

The plugin hooks into ITFlow via:

- **Custom sidebar navigation** (`agent/custom/includes/custom_side_nav.php`)
- **POST handler** - Include `signable_document.php` from `agent/post.php` by adding:
  ```php
  require_once("post/signable_document.php");
  ```
- **AJAX handler** - Either merge `ajax_signable.php` contents into `agent/ajax.php` or update modal JS to point to `ajax_signable.php`
- **Custom actions** - The plugin calls `customAction()` at lifecycle points: `signable_document_create`, `signable_document_send`, `signable_document_signed`

## File Structure

```
├── setup/
│   └── db_schema.sql                    # Database tables
├── includes/
│   └── functions_signable.php           # Shared PHP functions
├── agent/
│   ├── signable_documents.php           # Document list page
│   ├── signable_document.php            # Document detail page
│   ├── ajax_signable.php                # AJAX endpoint
│   ├── post/
│   │   ├── signable_document.php        # POST handler (CRUD + PDF)
│   │   └── signable_document_model.php  # Input sanitization
│   ├── modals/signable_document/
│   │   ├── signable_document_add.php    # Create modal
│   │   ├── signable_document_edit.php   # Edit modal
│   │   └── signable_document_send.php   # Send modal
│   └── custom/includes/
│       └── custom_side_nav.php          # Sidebar nav entry
├── guest/
│   └── guest_sign_document.php          # Guest signing page
├── js/
│   └── signature_pad.js                 # Canvas signature capture
└── uploads/
    └── signable_documents/              # PDF uploads directory
```

## Security Notes

- Guest access uses 64-character cryptographic URL keys
- Signature integrity verified via SHA-256 hash (document content + signature + email + timestamp)
- IP addresses and user agents recorded for audit
- HTML content sanitized via HTMLPurifier (when available)
- Input sanitization via `mysqli_real_escape_string` and `intval`
- Only Draft documents can be edited
- RBAC permissions enforced via ITFlow's `enforceUserPermission()`
