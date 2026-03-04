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
        $upload_dir = ensureSignableUploadDir($new_id);
        if ($upload_dir && move_uploaded_file($uploaded_file_tmp, $upload_dir . $uploaded_file_name)) {
            mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_file_name = '$uploaded_file_name' WHERE signable_document_id = $new_id");
        } else {
            $_SESSION['alert_type'] = "warning";
            $_SESSION['alert_message'] = "Document created but file upload failed. Check permissions on uploads/signable_documents/";
        }
    }

    // Generate quote PDF if quote type with a linked quote
    if ($type === 'quote' && $quote_id > 0 && $new_id && empty($uploaded_file_name)) {

        $q = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT * FROM quotes
            LEFT JOIN clients ON quote_client_id = client_id
            LEFT JOIN contacts ON clients.client_id = contacts.contact_client_id AND contact_primary = 1
            LEFT JOIN locations ON clients.client_id = locations.location_client_id AND location_primary = 1
            WHERE quote_id = $quote_id
            LIMIT 1"
        ));

        if ($q) {
            $comp = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1"));

            $q_prefix = nullable_htmlentities($q['quote_prefix']);
            $q_number = intval($q['quote_number']);
            $q_scope = nullable_htmlentities($q['quote_scope']);
            $q_status = nullable_htmlentities($q['quote_status']);
            $q_date = nullable_htmlentities($q['quote_date']);
            $q_expire = nullable_htmlentities($q['quote_expire']);
            $q_amount = floatval($q['quote_amount']);
            $q_discount = floatval($q['quote_discount_amount']);
            $q_currency = nullable_htmlentities($q['quote_currency_code']);
            $q_note = nullable_htmlentities($q['quote_note']);

            $comp_name = nullable_htmlentities($comp['company_name']);
            $comp_address = nullable_htmlentities($comp['company_address']);
            $comp_city = nullable_htmlentities($comp['company_city']);
            $comp_state = nullable_htmlentities($comp['company_state']);
            $comp_zip = nullable_htmlentities($comp['company_zip']);
            $comp_country = nullable_htmlentities($comp['company_country']);
            $comp_phone_cc = nullable_htmlentities($comp['company_phone_country_code']);
            $comp_phone = nullable_htmlentities(formatPhoneNumber($comp['company_phone'], $comp_phone_cc));
            $comp_website = nullable_htmlentities($comp['company_website']);
            $comp_logo = nullable_htmlentities($comp['company_logo']);

            $cl_name = nullable_htmlentities($q['client_name']);
            $cl_address = nullable_htmlentities($q['location_address']);
            $cl_city = nullable_htmlentities($q['location_city']);
            $cl_state = nullable_htmlentities($q['location_state']);
            $cl_zip = nullable_htmlentities($q['location_zip']);
            $cl_country = nullable_htmlentities($q['location_country']);
            $cl_email = nullable_htmlentities($q['contact_email']);
            $cl_phone_cc = nullable_htmlentities($q['contact_phone_country_code']);
            $cl_phone = nullable_htmlentities(formatPhoneNumber($q['contact_phone'], $cl_phone_cc));

            // Badge
            $q_badge = '';
            if (strtolower($q_status) === 'accepted') {
                $q_badge = '<span style="color:green; font-weight:bold;">ACCEPTED</span><br>';
            } elseif (strtolower($q_status) === 'declined') {
                $q_badge = '<span style="color:red; font-weight:bold;">DECLINED</span><br>';
            }

            require_once("../plugins/TCPDF/tcpdf.php");

            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetMargins(10, 10, 10);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 10);

            // Logo + Quote title
            $html = '<table width="100%" cellspacing="0" cellpadding="3"><tr><td width="40%">';
            if (!empty($comp_logo) && file_exists("../uploads/settings/$comp_logo")) {
                $html .= '<img src="/uploads/settings/' . $comp_logo . '" width="120">';
            }
            $html .= '</td><td width="60%" align="right">
                <span style="font-size:18pt; font-weight:bold;">QUOTE</span><br>
                <span style="font-size:14pt;">' . $q_prefix . $q_number . '</span><br>' . $q_badge . '
            </td></tr></table><br>';

            // Company + Client info
            $html .= '<table width="100%" cellspacing="0" cellpadding="2">
            <tr>
                <td width="50%" style="font-size:14pt; font-weight:bold;">' . $comp_name . '</td>
                <td width="50%" align="right" style="font-size:14pt; font-weight:bold;">' . $cl_name . '</td>
            </tr>
            <tr>
                <td style="font-size:10pt; line-height:1.4;">' . nl2br("$comp_address\n$comp_city $comp_state $comp_zip\n$comp_country\n$comp_phone\n$comp_website") . '</td>
                <td style="font-size:10pt; line-height:1.4;" align="right">' . nl2br("$cl_address\n$cl_city $cl_state $cl_zip\n$cl_country\n$cl_email\n$cl_phone") . '</td>
            </tr></table><br>';

            // Dates
            $html .= '<table border="0" cellpadding="2" cellspacing="0" width="100%">
            <tr><td width="60%"></td><td width="20%" style="font-size:10pt;"><strong>Date:</strong></td><td width="20%" style="font-size:10pt;" align="right">' . $q_date . '</td></tr>
            <tr><td></td><td style="font-size:10pt;"><strong>Expires:</strong></td><td style="font-size:10pt;" align="right">' . $q_expire . '</td></tr>
            </table><br><br>';

            // Items
            $html .= '<table border="0" cellpadding="5" cellspacing="0" width="100%">
            <tr style="background-color:#f0f0f0;">
                <th align="left" width="40%"><strong>Item</strong></th>
                <th align="center" width="10%"><strong>Qty</strong></th>
                <th align="right" width="15%"><strong>Price</strong></th>
                <th align="right" width="15%"><strong>Tax</strong></th>
                <th align="right" width="20%"><strong>Amount</strong></th>
            </tr>';

            $sub_total = 0;
            $total_tax = 0;
            $items = mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_quote_id = $quote_id ORDER BY item_order ASC");
            while ($item = mysqli_fetch_assoc($items)) {
                $i_name = $item['item_name'];
                $i_desc = $item['item_description'];
                $i_qty = $item['item_quantity'];
                $i_price = $item['item_price'];
                $i_tax = $item['item_tax'];
                $i_total = $item['item_total'];
                $sub_total += $i_price * $i_qty;
                $total_tax += $i_tax;

                $html .= '<tr>
                    <td><strong>' . $i_name . '</strong><br><span style="font-style:italic; font-size:9pt;">' . nl2br($i_desc) . '</span></td>
                    <td align="center">' . number_format($i_qty, 2) . '</td>
                    <td align="right">' . numfmt_format_currency($currency_format, $i_price, $q_currency) . '</td>
                    <td align="right">' . numfmt_format_currency($currency_format, $i_tax, $q_currency) . '</td>
                    <td align="right">' . numfmt_format_currency($currency_format, $i_total, $q_currency) . '</td>
                </tr>';
            }
            $html .= '</table><br><hr><br><br>';

            // Totals
            $html .= '<table width="100%" cellspacing="0" cellpadding="4">
            <tr><td width="60%"><i style="font-size:9pt;">' . nl2br($q_note) . '</i></td>
            <td width="40%"><table width="100%" cellpadding="3" cellspacing="0">
                <tr><td>Subtotal:</td><td align="right">' . numfmt_format_currency($currency_format, $sub_total, $q_currency) . '</td></tr>';
            if ($q_discount > 0) {
                $html .= '<tr><td>Discount:</td><td align="right">-' . numfmt_format_currency($currency_format, $q_discount, $q_currency) . '</td></tr>';
            }
            if ($total_tax > 0) {
                $html .= '<tr><td>Tax:</td><td align="right">' . numfmt_format_currency($currency_format, $total_tax, $q_currency) . '</td></tr>';
            }
            $html .= '<tr><td><h3><strong>Total:</strong></h3></td><td align="right"><h3><strong>' . numfmt_format_currency($currency_format, $q_amount, $q_currency) . '</strong></h3></td></tr>
            </table></td></tr></table><br><br>';

            // Footer
            $q_footer = nullable_htmlentities($comp['config_quote_footer'] ?? '');
            if ($q_footer) {
                $html .= '<div style="text-align:center; font-size:9pt; color:gray;">' . nl2br($q_footer) . '</div>';
            }

            $pdf->writeHTML($html, true, false, true, false, '');

            $pdf_filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', "Quote_{$q_prefix}{$q_number}") . '.pdf';

            // Save PDF to uploads
            $upload_dir = ensureSignableUploadDir($new_id);
            if ($upload_dir) {
                $pdf->Output($upload_dir . $pdf_filename, 'F');
                $pdf_filename_escaped = mysqli_real_escape_string($mysqli, $pdf_filename);
                mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_file_name = '$pdf_filename_escaped' WHERE signable_document_id = $new_id");
            } else {
                $_SESSION['alert_type'] = "warning";
                $_SESSION['alert_message'] = "Document created but PDF could not be saved to disk. Check permissions on uploads/signable_documents/";
            }
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
        $upload_dir = ensureSignableUploadDir($signable_document_id);
        if ($upload_dir && move_uploaded_file($uploaded_file_tmp, $upload_dir . $uploaded_file_name)) {
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
