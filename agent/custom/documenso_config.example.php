<?php

/**
 * ITFlow Document Signing Plugin (Documenso edition) - Configuration
 *
 * COPY this file to `documenso_config.php` in the same directory and fill in
 * your real values. `documenso_config.php` is git-ignored so your API key
 * never lands in the repo.
 *
 * On the server, lock it down so only the web server user can read it:
 *     chmod 640 documenso_config.php
 *     chown www-data:www-data documenso_config.php   # adjust user as needed
 */

// Base URL of your Documenso API (no trailing slash)
$documenso_base_url = 'https://sign.example.com/api/v2';

// Documenso API token (the value you pass in the Authorization header)
$documenso_api_key = 'api_xxxxxxxxxxxxxxxxx';

// The template envelope this plugin sends. Get it from:
//   GET /api/v2/template/{id}  ->  "envelopeId"
$documenso_template_envelope_id = 'envelope_xxxxxxxxxxxxxxxx';

// Map of MSA prefill field LABELS as set in the Documenso template.
// The plugin resolves these labels to numeric field ids at runtime by
// reading the template, so field-id changes on template edits won't break it.
$documenso_field_labels = [
    'business_name'    => 'Business Name',
    'business_address' => 'Business Address',
    'monthly_rate'     => 'Monthly Rate',
    'effective_date'   => 'Effective Date',
];

// Documenso template recipient PLACEHOLDER ids (from the template structure).
// These are the slots real people get mapped onto at send time.
$documenso_recipients = [
    'provider_id'      => 2,   // You (the provider) - signs first
    'client_signer_id' => 29,  // Client authorized representative - signs second
    'approver_id'      => 30,  // Final approver - signs last (defaults to provider)
];

// Default approver (used when no specific approver is supplied at send time).
// Per the workflow: you fill+sign, client signs, then back to you to approve.
$documenso_default_approver_name  = 'Provider Name';
$documenso_default_approver_email = 'provider@example.com';

// The ITFlow template document type these MSAs are filed under, and the
// Files-section folder name where signed PDFs get stored per client.
$documenso_signed_files_folder = 'Signed Documents';
