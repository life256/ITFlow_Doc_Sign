<?php

/**
 * ITFlow Document Signing Plugin - POST Handler
 *
 * Handles all CRUD and action operations for signable documents.
 * This file is included by the main agent/post.php handler.
 */

require_once("../includes/functions_signable.php");

// ============================================================
// CREATE - Add new signable document
// ============================================================
if (isset($_POST['add_signable_document'])) {

    validateCSRFToken($_POST['csrf_token']);
    require_once("post/signable_document_model.php");

    enforceUserPermission('module_sales', 2);

    // Validate required fields
    if (empty($title) || empty($client_id)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Title and Client are required.";
        header("Location: signable_documents.php");
        exit();
    }

    // Generate URL key for guest signing
    $url_key = generateSignableUrlKey();

    mysqli_query($mysqli, "INSERT INTO signable_documents SET
        signable_document_title = '$title',
        signable_document_description = '$description',
        signable_document_type = '$type',
        signable_document_content = '$content',
        signable_document_status = 'Draft',
        signable_document_url_key = '$url_key',
        signable_document_date = '$date',
        signable_document_expire = " . ($expire ? "'$expire'" : "NULL") . ",
        signable_document_note = '$note',
        signable_document_created_by = $session_user_id,
        signable_document_client_id = $client_id,
        signable_document_contact_id = $contact_id,
        signable_document_quote_id = $quote_id"
    );

    $new_id = mysqli_insert_id($mysqli);

    // Handle file upload
    if (!empty($uploaded_file_name) && !empty($uploaded_file_tmp) && $new_id) {
        $upload_dir = "../uploads/signable_documents/$new_id/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0770, true);
        }
        if (move_uploaded_file($uploaded_file_tmp, $upload_dir . $uploaded_file_name)) {
            mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_file_name = '$uploaded_file_name' WHERE signable_document_id = $new_id");
        }
    }

    // Record history
    addSignableHistory($mysqli, $new_id, 'Created', "Document created by $session_name", $_SERVER['REMOTE_ADDR'] ?? null);

    // Custom action hook
    if (function_exists('customAction')) {
        customAction('signable_document_create', $new_id);
    }

    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Signable document created.";

    header("Location: signable_document.php?signable_document_id=$new_id");
    exit();
}

// ============================================================
// UPDATE - Edit signable document
// ============================================================
if (isset($_POST['edit_signable_document'])) {

    validateCSRFToken($_POST['csrf_token']);
    require_once("post/signable_document_model.php");

    enforceUserPermission('module_sales', 2);

    // Only allow editing Draft documents
    $check = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT signable_document_status FROM signable_documents WHERE signable_document_id = $signable_document_id"));
    if (!$check || $check['signable_document_status'] !== 'Draft') {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Only draft documents can be edited.";
        header("Location: signable_documents.php");
        exit();
    }

    mysqli_query($mysqli, "UPDATE signable_documents SET
        signable_document_title = '$title',
        signable_document_description = '$description',
        signable_document_type = '$type',
        signable_document_content = '$content',
        signable_document_date = '$date',
        signable_document_expire = " . ($expire ? "'$expire'" : "NULL") . ",
        signable_document_note = '$note'
        WHERE signable_document_id = $signable_document_id"
    );

    // Handle file upload
    if (!empty($uploaded_file_name) && !empty($uploaded_file_tmp)) {
        $upload_dir = "../uploads/signable_documents/$signable_document_id/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0770, true);
        }
        if (move_uploaded_file($uploaded_file_tmp, $upload_dir . $uploaded_file_name)) {
            mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_file_name = '$uploaded_file_name' WHERE signable_document_id = $signable_document_id");
        }
    }

    addSignableHistory($mysqli, $signable_document_id, 'Edited', "Document edited by $session_name", $_SERVER['REMOTE_ADDR'] ?? null);

    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Document updated.";

    header("Location: signable_document.php?signable_document_id=$signable_document_id");
    exit();
}

// ============================================================
// SEND - Email document for signing
// ============================================================
if (isset($_POST['send_signable_document'])) {

    validateCSRFToken($_POST['csrf_token']);
    require_once("post/signable_document_model.php");

    enforceUserPermission('module_sales', 2);

    $doc = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM signable_documents WHERE signable_document_id = $signable_document_id"));
    if (!$doc) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Document not found.";
        header("Location: signable_documents.php");
        exit();
    }

    // Build signing URL
    $signing_url = $config_base_url . "/guest/guest_sign_document.php?signable_document_id=$signable_document_id&url_key=" . urlencode($doc['signable_document_url_key']);

    // Replace placeholder in email body (email fields are NOT sql-escaped)
    $body = str_replace('[SIGNING_LINK]', $signing_url, $email_body);
    $body = nl2br(htmlspecialchars($body));

    // Send email using ITFlow's mail queue
    addToMailQueue([[
        'from' => $config_mail_from_email,
        'from_name' => $config_mail_from_name,
        'recipient' => $email_to,
        'recipient_name' => '',
        'subject' => $email_subject,
        'body' => $body,
    ]]);

    // Update status to Sent if still Draft
    if ($doc['signable_document_status'] === 'Draft') {
        mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_status = 'Sent' WHERE signable_document_id = $signable_document_id");
    }

    $email_to_escaped = mysqli_real_escape_string($mysqli, $email_to);
    addSignableHistory($mysqli, $signable_document_id, 'Sent', "Document sent to $email_to_escaped by $session_name", $_SERVER['REMOTE_ADDR'] ?? null);

    if (function_exists('customAction')) {
        customAction('signable_document_send', $signable_document_id);
    }

    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Document sent for signing.";

    header("Location: signable_document.php?signable_document_id=$signable_document_id");
    exit();
}

// ============================================================
// ARCHIVE - Soft delete
// ============================================================
if (isset($_POST['archive_signable_document'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 3);

    $archive_id = intval($_POST['archive_signable_document']);

    mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_archived_at = NOW() WHERE signable_document_id = $archive_id");

    addSignableHistory($mysqli, $archive_id, 'Archived', "Document archived by $session_name", $_SERVER['REMOTE_ADDR'] ?? null);

    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Document archived.";

    header("Location: signable_documents.php");
    exit();
}

// ============================================================
// EXPORT PDF - Generate PDF with TCPDF
// ============================================================
if (isset($_GET['export_signable_document_pdf'])) {

    enforceUserPermission('module_sales', 1);

    $pdf_id = intval($_GET['export_signable_document_pdf']);
    $doc = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT sd.*, c.client_name, ct.contact_name, ct.contact_email
        FROM signable_documents sd
        LEFT JOIN clients c ON sd.signable_document_client_id = c.client_id
        LEFT JOIN contacts ct ON sd.signable_document_contact_id = ct.contact_id
        WHERE sd.signable_document_id = $pdf_id"));

    if (!$doc) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Document not found.";
        header("Location: signable_documents.php");
        exit();
    }

    require_once("../plugins/TCPDF/tcpdf.php");

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('ITFlow');
    $pdf->SetAuthor($config_company_name);
    $pdf->SetTitle($doc['signable_document_title']);
    $pdf->SetMargins(15, 15, 15);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    // Header
    $html = '<h2>' . htmlspecialchars($doc['signable_document_title']) . '</h2>';
    $html .= '<table width="100%" cellpadding="3">';
    $html .= '<tr><td width="50%"><strong>Client:</strong> ' . htmlspecialchars($doc['client_name']) . '</td>';
    $html .= '<td width="50%"><strong>Date:</strong> ' . htmlspecialchars($doc['signable_document_date']) . '</td></tr>';
    if ($doc['contact_name']) {
        $html .= '<tr><td><strong>Contact:</strong> ' . htmlspecialchars($doc['contact_name']) . '</td>';
        $html .= '<td><strong>Status:</strong> ' . htmlspecialchars($doc['signable_document_status']) . '</td></tr>';
    }
    $html .= '</table><hr>';

    // Document content
    if (!empty($doc['signable_document_content'])) {
        $html .= $doc['signable_document_content'];
    }

    // Signatures
    $sigs = mysqli_query($mysqli, "SELECT * FROM signable_document_signatures WHERE signature_signable_document_id = $pdf_id ORDER BY signature_created_at ASC");
    if (mysqli_num_rows($sigs) > 0) {
        $html .= '<hr><h3>Signatures</h3>';
        while ($sig = mysqli_fetch_assoc($sigs)) {
            $html .= '<table width="100%" cellpadding="3">';
            $html .= '<tr><td width="60%">';
            $html .= '<strong>' . htmlspecialchars($sig['signature_signer_name']) . '</strong><br>';
            $html .= htmlspecialchars($sig['signature_signer_email']) . '<br>';
            $html .= '<small>Signed: ' . htmlspecialchars($sig['signature_created_at']) . '</small><br>';
            $html .= '<small>Hash: ' . htmlspecialchars($sig['signature_hash']) . '</small>';
            $html .= '</td><td width="40%">';
            // Embed signature image
            if (strpos($sig['signature_data'], 'data:image') === 0) {
                $html .= '<img src="' . $sig['signature_data'] . '" width="200">';
            }
            $html .= '</td></tr></table>';
        }
    }

    $pdf->writeHTML($html, true, false, true, false, '');

    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $doc['signable_document_title']);
    $pdf->Output("$filename.pdf", 'I');
    exit();
}
