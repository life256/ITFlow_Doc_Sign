<?php

/**
 * ITFlow Document Signing Plugin (Documenso edition) - POST Handler
 *
 * Included by agent/custom/post.php (working dir is agent/custom/).
 * Actions:
 *   - create_documenso_msa   : gather client data -> create+distribute -> store
 *   - refresh_documenso_status: re-read envelope status (and file PDF if Completed)
 *   - archive_documenso_document
 */

require_once("../../includes/functions_documenso.php");

/**
 * Gather prefill values for a given profile from ITFlow data (direct DB).
 * Returns [values_array, error_string]. On success error is null. On failure
 * values is null and error is a user-facing message.
 *
 * Profiles:
 *   'none'         -> no prefill ([] , null)
 *   'client_basic' -> business_name + business_address
 *   'msa_full'     -> business_name + business_address + monthly_rate + effective_date
 */
function documensoGatherPrefill($mysqli, $profile, $client_id, $business_name) {
    if ($profile === 'none') {
        return [[], null];
    }

    // Both client_basic and msa_full need the primary location (address).
    $loc = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT location_address, location_city, location_state, location_zip
         FROM locations
         WHERE location_client_id = " . intval($client_id) . " AND location_primary = 1
         LIMIT 1"));
    if (!$loc || empty($loc['location_address'])) {
        return [null, "Client has no primary location/address set. Add one before sending this document."];
    }
    $business_address = trim($loc['location_address'] . ', ' . $loc['location_city'] . ', ' . $loc['location_state'] . ' ' . $loc['location_zip']);

    $values = [
        'business_name'    => $business_name,
        'business_address' => $business_address,
    ];

    if ($profile === 'client_basic') {
        return [$values, null];
    }

    if ($profile === 'msa_full') {
        // Most recent non-archived invoice -> rate + effective date.
        $inv = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT invoice_amount, invoice_due
             FROM invoices
             WHERE invoice_client_id = " . intval($client_id) . " AND invoice_archived_at IS NULL
             ORDER BY invoice_id DESC LIMIT 1"));
        if (!$inv) {
            return [null, "Client has no invoice. Create the placeholder invoice (amount + due date) before sending this document."];
        }
        $effective_date = $inv['invoice_due'];
        $ts = strtotime($inv['invoice_due']);
        if ($ts !== false) { $effective_date = date('F j, Y', $ts); }
        $values['monthly_rate']   = $inv['invoice_amount'];
        $values['effective_date'] = $effective_date;
        return [$values, null];
    }

    // Unknown profile
    return [null, "Unknown prefill profile: " . htmlspecialchars($profile)];
}

// ============================================================
// CREATE MSA - gather client data, create + distribute envelope
// ============================================================
if (isset($_POST['create_documenso_msa'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 2);

    $client_id = intval($_POST['client_id'] ?? 0);
    $template_key = trim($_POST['template_key'] ?? '');
    $approver_name_in  = trim($_POST['approver_name']  ?? '');
    $approver_email_in = trim($_POST['approver_email'] ?? '');

    if ($client_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "A client is required to create a document.";
        header("Location: documenso_documents.php");
        exit();
    }

    $cfg = documensoLoadConfig();
    if (!$cfg) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Documenso config not found. Copy documenso_config.example.php to documenso_config.php.";
        header("Location: documenso_documents.php");
        exit();
    }

    // --- Resolve which template to use ---
    if ($template_key === '') {
        $template_key = $cfg['default_template_key'];
    }
    $template = documensoGetTemplate($cfg, $template_key);
    if (!$template) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Unknown document template selected.";
        header("Location: documenso_documents.php");
        exit();
    }

    // --- Gather client (business name) ---
    $client = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT client_id, client_name FROM clients WHERE client_id = $client_id"));
    if (!$client) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Client not found.";
        header("Location: documenso_documents.php");
        exit();
    }
    $business_name = $client['client_name'];

    // --- Primary contact (client signer) — required for ALL templates ---
    $contact = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT contact_id, contact_name, contact_email
         FROM contacts
         WHERE contact_client_id = $client_id AND contact_primary = 1
         LIMIT 1"));
    if (!$contact || empty($contact['contact_email'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Client has no primary contact with an email. Set one before sending.";
        header("Location: documenso_documents.php");
        exit();
    }
    $contact_id = intval($contact['contact_id']);

    // --- Run the template's prefill profile to gather field values ---
    $profile = $template['prefill_profile'] ?? 'none';
    list($values, $prefill_error) = documensoGatherPrefill($mysqli, $profile, $client_id, $business_name);
    if ($prefill_error !== null) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $prefill_error;
        header("Location: documenso_documents.php");
        exit();
    }

    // --- Approver: explicit override, else default (you) ---
    $approver_name  = $approver_name_in  !== '' ? $approver_name_in  : $cfg['default_approver_name'];
    $approver_email = $approver_email_in !== '' ? $approver_email_in : $cfg['default_approver_email'];

    // --- Resolve template field ids by label (empty for no-prefill templates) ---
    $fieldIds = documensoResolveFieldIds($cfg, $template);
    if ($fieldIds === false) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Could not resolve Documenso template fields by label. Check template labels.";
        header("Location: documenso_documents.php");
        exit();
    }

    // --- Create envelope ---
    $signer   = ['name' => $contact['contact_name'], 'email' => $contact['contact_email']];
    $approver = ['name' => $approver_name, 'email' => $approver_email];

    list($code, $body) = documensoCreateEnvelope($cfg, $template, $fieldIds, $values, $signer, $approver);
    if ($code !== 200 || !is_array($body) || empty($body['id'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Documenso create failed (HTTP $code). Check logs.";
        header("Location: documenso_documents.php");
        exit();
    }
    $envelope_id = $body['id'];

    // --- Distribute (activate signing) ---
    list($dcode, $dbody) = documensoDistribute($cfg, $envelope_id);
    if ($dcode !== 200) {
        $_SESSION['alert_type'] = "warning";
        $_SESSION['alert_message'] = "Envelope created but distribution failed (HTTP $dcode). Signing links may be inactive.";
        // continue: still record what we created
    }

    // --- Numeric document id (for later download) ---
    list($scode, $sbody) = documensoGetEnvelope($cfg, $envelope_id);
    $numeric_id = is_array($sbody) ? documensoNumericIdFromEnvelope($sbody) : null;
    $status = (is_array($sbody) && !empty($sbody['status'])) ? ucfirst(strtolower($sbody['status'])) : 'Pending';

    // --- Store the document record ---
    $label = $template['label'] ?? $template_key;
    $title = mysqli_real_escape_string($mysqli, $label . " - " . $business_name);
    $type_esc        = mysqli_real_escape_string($mysqli, $template_key);
    $envelope_id_esc = mysqli_real_escape_string($mysqli, $envelope_id);
    $tmpl_env_esc    = mysqli_real_escape_string($mysqli, $template['template_envelope_id'] ?? '');
    $bn = mysqli_real_escape_string($mysqli, $business_name);
    $ba = mysqli_real_escape_string($mysqli, $values['business_address'] ?? '');
    $mr = mysqli_real_escape_string($mysqli, $values['monthly_rate'] ?? '');
    $ed = mysqli_real_escape_string($mysqli, $values['effective_date'] ?? '');
    $status_esc = mysqli_real_escape_string($mysqli, $status);
    $numeric_sql = $numeric_id !== null ? intval($numeric_id) : "NULL";

    mysqli_query($mysqli, "INSERT INTO documenso_documents SET
        documenso_document_title = '$title',
        documenso_document_type = '$type_esc',
        documenso_document_envelope_id = '$envelope_id_esc',
        documenso_document_numeric_id = $numeric_sql,
        documenso_document_template_envelope_id = '$tmpl_env_esc',
        documenso_document_status = '$status_esc',
        documenso_document_business_name = '$bn',
        documenso_document_business_address = '$ba',
        documenso_document_monthly_rate = '$mr',
        documenso_document_effective_date = '$ed',
        documenso_document_sent_at = NOW(),
        documenso_document_created_by = $session_user_id,
        documenso_document_client_id = $client_id,
        documenso_document_contact_id = $contact_id");
    $doc_id = mysqli_insert_id($mysqli);

    // --- Store recipients (signing URLs) ---
    foreach (($body['recipients'] ?? []) as $r) {
        $r_did   = isset($r['id']) ? intval($r['id']) : "NULL";
        $r_role  = mysqli_real_escape_string($mysqli, $r['role'] ?? '');
        $r_name  = mysqli_real_escape_string($mysqli, $r['name'] ?? '');
        $r_email = mysqli_real_escape_string($mysqli, $r['email'] ?? '');
        $r_order = isset($r['signingOrder']) ? intval($r['signingOrder']) : "NULL";
        $r_url   = mysqli_real_escape_string($mysqli, $r['signingUrl'] ?? '');
        mysqli_query($mysqli, "INSERT INTO documenso_document_recipients SET
            documenso_recipient_documenso_id = $r_did,
            documenso_recipient_role = '$r_role',
            documenso_recipient_name = '$r_name',
            documenso_recipient_email = '$r_email',
            documenso_recipient_signing_order = $r_order,
            documenso_recipient_signing_url = '$r_url',
            documenso_recipient_signing_status = 'NOT_SIGNED',
            documenso_recipient_document_id = $doc_id");
    }

    documensoAddHistory($mysqli, $doc_id, 'Created', "$label created and sent for $business_name by $session_name", $_SERVER['REMOTE_ADDR'] ?? null);

    if (function_exists('customAction')) {
        customAction('documenso_document_create', $doc_id);
    }

    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "$label created and sent for signing.";
    header("Location: documenso_document.php?documenso_document_id=$doc_id");
    exit();
}

// ============================================================
// REFRESH STATUS - re-read envelope; file signed PDF if Completed
// ============================================================
if (isset($_POST['refresh_documenso_status'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 1);

    $doc_id = intval($_POST['documenso_document_id'] ?? 0);
    $doc = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT * FROM documenso_documents WHERE documenso_document_id = $doc_id"));
    if (!$doc) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Document not found.";
        header("Location: documenso_documents.php");
        exit();
    }

    $cfg = documensoLoadConfig();
    if (!$cfg) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Documenso config not found.";
        header("Location: documenso_document.php?documenso_document_id=$doc_id");
        exit();
    }

    list($scode, $sbody) = documensoGetEnvelope($cfg, $doc['documenso_document_envelope_id']);
    if ($scode !== 200 || !is_array($sbody)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Could not read envelope status from Documenso (HTTP $scode).";
        header("Location: documenso_document.php?documenso_document_id=$doc_id");
        exit();
    }

    $new_status = !empty($sbody['status']) ? ucfirst(strtolower($sbody['status'])) : $doc['documenso_document_status'];
    $new_status_esc = mysqli_real_escape_string($mysqli, $new_status);

    // Update per-recipient signing status
    foreach (($sbody['recipients'] ?? []) as $r) {
        $rid = intval($r['id'] ?? 0);
        $rs  = mysqli_real_escape_string($mysqli, $r['signingStatus'] ?? '');
        if ($rid > 0) {
            mysqli_query($mysqli, "UPDATE documenso_document_recipients
                SET documenso_recipient_signing_status = '$rs'
                WHERE documenso_recipient_document_id = $doc_id
                  AND documenso_recipient_documenso_id = $rid");
        }
    }

    // Ensure we have the numeric id
    $numeric_id = $doc['documenso_document_numeric_id'];
    if (!$numeric_id) {
        $numeric_id = documensoNumericIdFromEnvelope($sbody);
    }

    // On completion, file the signed PDF into the client's Files (once)
    $completed_sql = "";
    if ($new_status === 'Completed' && empty($doc['documenso_document_filed_file_id']) && $numeric_id) {
        $filed_id = documensoFileSignedPdf($mysqli, $cfg, $doc, intval($numeric_id), $session_user_id ?? 0);
        if ($filed_id) {
            $completed_sql = ", documenso_document_filed_file_id = " . intval($filed_id) . ", documenso_document_completed_at = NOW()";
            documensoAddHistory($mysqli, $doc_id, 'Completed', "Signed PDF filed to client Files (file id $filed_id)", $_SERVER['REMOTE_ADDR'] ?? null);
        }
    }

    $numeric_sql = $numeric_id ? ", documenso_document_numeric_id = " . intval($numeric_id) : "";
    mysqli_query($mysqli, "UPDATE documenso_documents
        SET documenso_document_status = '$new_status_esc' $numeric_sql $completed_sql
        WHERE documenso_document_id = $doc_id");

    documensoAddHistory($mysqli, $doc_id, 'Status', "Status refreshed: $new_status_esc by $session_name", $_SERVER['REMOTE_ADDR'] ?? null);

    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Status refreshed: $new_status.";
    header("Location: documenso_document.php?documenso_document_id=$doc_id");
    exit();
}

// ============================================================
// ARCHIVE - soft delete
// ============================================================
if (isset($_POST['archive_documenso_document'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 3);

    $archive_id = intval($_POST['archive_documenso_document']);
    mysqli_query($mysqli, "UPDATE documenso_documents
        SET documenso_document_archived_at = NOW()
        WHERE documenso_document_id = $archive_id");

    documensoAddHistory($mysqli, $archive_id, 'Archived', "Document archived by $session_name", $_SERVER['REMOTE_ADDR'] ?? null);

    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Document archived.";
    header("Location: documenso_documents.php");
    exit();
}

// ============================================================
// documensoFileSignedPdf() - file the signed PDF into client Files
//
// STUB for now. The core plugin works without it (signed PDF stays
// downloadable from Documenso via the detail page). This will be
// implemented as a follow-up once ITFlow's file STORAGE layout is
// verified (disk path, folders table semantics, file_reference_name
// convention, www-data write perms).
//
// Verified ITFlow `files` schema (for the follow-up):
//   file_id, file_reference_name, file_name, file_description, file_ext,
//   file_size, file_mime_type, file_favorite, file_created_at,
//   file_updated_at, file_archived_at, file_accessed_at, file_created_by,
//   file_folder_id, file_client_id
//   (a `folders` table exists for the per-client "Signed Documents" folder)
//
// Planned: download via documensoDownloadSignedPdf($cfg, $numericId),
// write bytes to ITFlow's client file storage path, ensure/lookup a
// "Signed Documents" folder for the client in `folders`, INSERT a `files`
// row, return the new file_id.
//
// Returns: int file_id on success, or null if not filed.
// ============================================================
function documensoFileSignedPdf($mysqli, $cfg, $doc, $numericDocumentId, $created_by) {
    // TODO: implement file storage integration (see notes above).
    return null;
}
