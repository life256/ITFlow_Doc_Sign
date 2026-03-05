<?php

/**
 * ITFlow Document Signing Plugin - Shared Functions
 */

/**
 * Generate a cryptographically secure URL key for guest access
 */
function generateSignableUrlKey() {
    return bin2hex(random_bytes(32));
}

/**
 * Get status badge HTML for a signable document status
 */
function getSignableStatusBadge($status) {
    $badges = [
        'Draft'    => 'badge-secondary',
        'Sent'     => 'badge-warning',
        'Viewed'   => 'badge-primary',
        'Signed'   => 'badge-success',
        'Declined' => 'badge-danger',
        'Expired'  => 'badge-dark',
    ];
    $class = $badges[$status] ?? 'badge-secondary';
    $escaped = htmlspecialchars($status);
    return "<span class=\"badge $class\">$escaped</span>";
}

/**
 * Get document type display label
 */
function getSignableTypeLabel($type) {
    $types = [
        'custom'   => 'Custom Document',
        'quote'    => 'Quote',
        'msa'      => 'MSA',
        'contract' => 'Contract',
    ];
    return $types[$type] ?? ucfirst($type);
}

/**
 * Record a signable document history entry
 */
function addSignableHistory($mysqli, $signable_document_id, $status, $description, $ip = null) {
    $signable_document_id = intval($signable_document_id);
    $status = mysqli_real_escape_string($mysqli, $status);
    $description = mysqli_real_escape_string($mysqli, $description);
    $ip = $ip ? "'" . mysqli_real_escape_string($mysqli, $ip) . "'" : "NULL";

    mysqli_query($mysqli, "INSERT INTO signable_document_history
        SET signable_history_status = '$status',
            signable_history_description = '$description',
            signable_history_ip = $ip,
            signable_history_signable_document_id = $signable_document_id"
    );
}

/**
 * Generate SHA-256 hash for document integrity verification
 */
function generateSignatureHash($document_content, $signature_data, $signer_email, $timestamp) {
    return hash('sha256', $document_content . $signature_data . $signer_email . $timestamp);
}

/**
 * Ensure the upload directory exists for a signable document.
 * Returns the writable directory path, or false on failure.
 * Uses 0750 permissions (owner rwx, group r-x, no world access).
 */
function ensureSignableUploadDir($document_id) {
    // Use DOCUMENT_ROOT for reliable path resolution regardless of calling script location
    $base_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/signable_documents/";
    if (!is_dir($base_dir)) {
        if (!@mkdir($base_dir, 0750, true)) {
            return false;
        }
    }
    $doc_dir = $base_dir . intval($document_id) . "/";
    if (!is_dir($doc_dir)) {
        if (!@mkdir($doc_dir, 0750, true)) {
            return false;
        }
    }
    return is_writable($doc_dir) ? $doc_dir : false;
}

/**
 * Check if a signable document has expired
 */
function isSignableExpired($expire_date) {
    if (empty($expire_date) || $expire_date === '0000-00-00') {
        return false;
    }
    return strtotime($expire_date) < strtotime('today');
}

/**
 * Generate a signed document PDF and return it as a binary string.
 *
 * Includes the document content, inline signature blocks, and a
 * Certificate of Completion page with full audit details.
 *
 * @param mysqli  $mysqli       Database connection
 * @param int     $document_id  The signable_document_id
 * @param string  $company_name Company name for PDF metadata
 * @return string|false  PDF binary string, or false on failure
 */
function generateSignableDocumentPDF($mysqli, $document_id, $company_name = 'ITFlow') {

    $document_id = intval($document_id);
    $doc = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT sd.*, c.client_name, ct.contact_name, ct.contact_email
         FROM signable_documents sd
         LEFT JOIN clients c ON sd.signable_document_client_id = c.client_id
         LEFT JOIN contacts ct ON sd.signable_document_contact_id = ct.contact_id
         WHERE sd.signable_document_id = $document_id"));

    if (!$doc) {
        return false;
    }

    // Locate TCPDF — works from both agent/ and guest/ directories
    $tcpdf_paths = [
        $_SERVER['DOCUMENT_ROOT'] . '/plugins/TCPDF/tcpdf.php',
        __DIR__ . '/../plugins/TCPDF/tcpdf.php',
    ];
    $tcpdf_loaded = false;
    foreach ($tcpdf_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $tcpdf_loaded = true;
            break;
        }
    }
    if (!$tcpdf_loaded) {
        return false;
    }

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('ITFlow');
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle($doc['signable_document_title']);
    $pdf->SetMargins(15, 15, 15);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    // --- Document header ---
    $html = '<h2>' . htmlspecialchars($doc['signable_document_title']) . '</h2>';
    $html .= '<table width="100%" cellpadding="3">';
    $html .= '<tr><td width="50%"><strong>Client:</strong> ' . htmlspecialchars($doc['client_name']) . '</td>';
    $html .= '<td width="50%"><strong>Date:</strong> ' . htmlspecialchars($doc['signable_document_date']) . '</td></tr>';
    if ($doc['contact_name']) {
        $html .= '<tr><td><strong>Contact:</strong> ' . htmlspecialchars($doc['contact_name']) . '</td>';
        $html .= '<td><strong>Status:</strong> ' . htmlspecialchars($doc['signable_document_status']) . '</td></tr>';
    }
    $html .= '</table><hr>';

    // --- Document content ---
    if (!empty($doc['signable_document_content'])) {
        $html .= $doc['signable_document_content'];
    }

    // --- Inline signature blocks ---
    $sigs = mysqli_query($mysqli, "SELECT * FROM signable_document_signatures WHERE signature_signable_document_id = $document_id ORDER BY signature_created_at ASC");
    $sig_rows = [];
    while ($sig = mysqli_fetch_assoc($sigs)) {
        $sig_rows[] = $sig;
    }

    if (count($sig_rows) > 0) {
        $html .= '<br><br>';
        foreach ($sig_rows as $sig) {
            $html .= '<table width="100%" cellpadding="8" style="border: 1px solid #333333;">';
            $html .= '<tr>';
            $html .= '<td width="55%" style="border-right: 1px solid #cccccc;">';
            if (strpos($sig['signature_data'], 'data:image') === 0) {
                $html .= '<img src="' . $sig['signature_data'] . '" width="180">';
            }
            $html .= '<br><span style="font-size: 8pt; color: #666666;">Electronically signed</span>';
            $html .= '</td>';
            $html .= '<td width="45%">';
            $html .= '<strong>' . htmlspecialchars($sig['signature_signer_name']) . '</strong><br>';
            $html .= '<span style="font-size: 9pt;">' . htmlspecialchars($sig['signature_signer_email']) . '</span><br>';
            $html .= '<span style="font-size: 9pt; color: #555555;">' . htmlspecialchars($sig['signature_created_at']) . '</span>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table><br>';
        }
    }

    $pdf->writeHTML($html, true, false, true, false, '');

    // --- Certificate of Completion page ---
    if (count($sig_rows) > 0) {
        $pdf->AddPage();

        $cert = '';
        $cert .= '<table width="100%" cellpadding="0">';
        $cert .= '<tr><td style="border-bottom: 3px solid #333333;">';
        $cert .= '<h1 style="color: #333333; font-size: 20pt; margin-bottom: 2px;">Certificate of Completion</h1>';
        $cert .= '<span style="font-size: 9pt; color: #666666;">Electronic Signature Verification</span>';
        $cert .= '</td></tr>';
        $cert .= '</table><br>';

        // Document details
        $cert .= '<table width="100%" cellpadding="6" style="background-color: #f5f5f5; border: 1px solid #dddddd;">';
        $cert .= '<tr><td colspan="2" style="border-bottom: 1px solid #dddddd;"><strong style="font-size: 11pt;">Document Details</strong></td></tr>';
        $cert .= '<tr><td width="35%" style="border-bottom: 1px solid #eeeeee;"><strong>Document Title:</strong></td>';
        $cert .= '<td width="65%" style="border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($doc['signable_document_title']) . '</td></tr>';
        $cert .= '<tr><td style="border-bottom: 1px solid #eeeeee;"><strong>Document ID:</strong></td>';
        $cert .= '<td style="border-bottom: 1px solid #eeeeee;">' . intval($doc['signable_document_id']) . '</td></tr>';
        $cert .= '<tr><td style="border-bottom: 1px solid #eeeeee;"><strong>Client:</strong></td>';
        $cert .= '<td style="border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($doc['client_name']) . '</td></tr>';
        if ($doc['contact_name']) {
            $cert .= '<tr><td style="border-bottom: 1px solid #eeeeee;"><strong>Contact:</strong></td>';
            $cert .= '<td style="border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($doc['contact_name']) . '</td></tr>';
        }
        $cert .= '<tr><td style="border-bottom: 1px solid #eeeeee;"><strong>Document Date:</strong></td>';
        $cert .= '<td style="border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($doc['signable_document_date']) . '</td></tr>';
        $cert .= '<tr><td><strong>Status:</strong></td>';
        $cert .= '<td><span style="color: #28a745; font-weight: bold;">' . htmlspecialchars($doc['signable_document_status']) . '</span></td></tr>';
        $cert .= '</table><br>';

        // Per-signer details
        $signer_num = 0;
        foreach ($sig_rows as $sig) {
            $signer_num++;
            $cert .= '<table width="100%" cellpadding="6" style="border: 1px solid #dddddd; margin-bottom: 8px;">';
            $cert .= '<tr><td colspan="2" style="background-color: #f5f5f5; border-bottom: 1px solid #dddddd;"><strong style="font-size: 11pt;">Signer ' . $signer_num . '</strong></td></tr>';

            $cert .= '<tr><td width="35%" style="border-bottom: 1px solid #eeeeee;"><strong>Name:</strong></td>';
            $cert .= '<td width="65%" style="border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($sig['signature_signer_name']) . '</td></tr>';

            $cert .= '<tr><td style="border-bottom: 1px solid #eeeeee;"><strong>Email:</strong></td>';
            $cert .= '<td style="border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($sig['signature_signer_email']) . '</td></tr>';

            $cert .= '<tr><td style="border-bottom: 1px solid #eeeeee;"><strong>Signed At:</strong></td>';
            $cert .= '<td style="border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($sig['signature_created_at']) . '</td></tr>';

            $cert .= '<tr><td style="border-bottom: 1px solid #eeeeee;"><strong>IP Address:</strong></td>';
            $cert .= '<td style="border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($sig['signature_signer_ip']) . '</td></tr>';

            $cert .= '<tr><td style="border-bottom: 1px solid #eeeeee;"><strong>User Agent:</strong></td>';
            $cert .= '<td style="border-bottom: 1px solid #eeeeee; font-size: 7pt;">' . htmlspecialchars($sig['signature_signer_user_agent']) . '</td></tr>';

            $cert .= '<tr><td style="border-bottom: 1px solid #eeeeee;"><strong>Signature:</strong></td>';
            $cert .= '<td style="border-bottom: 1px solid #eeeeee;">';
            if (strpos($sig['signature_data'], 'data:image') === 0) {
                $cert .= '<img src="' . $sig['signature_data'] . '" width="150">';
            }
            $cert .= '</td></tr>';

            $cert .= '<tr><td><strong>Integrity Hash:</strong></td>';
            $cert .= '<td><span style="font-family: courier; font-size: 7pt;">' . htmlspecialchars($sig['signature_hash']) . '</span></td></tr>';
            $cert .= '</table><br>';
        }

        // Disclaimer
        $cert .= '<br><table width="100%" cellpadding="4">';
        $cert .= '<tr><td style="border-top: 1px solid #cccccc; font-size: 8pt; color: #888888;">';
        $cert .= 'This document was electronically signed via ITFlow. The integrity hash is a SHA-256 digest of the ';
        $cert .= 'document content, signature data, signer email, and signing timestamp. Any modification to the original ';
        $cert .= 'document after signing will invalidate this hash. This certificate serves as a record of the electronic ';
        $cert .= 'signing event and the identity of the signer(s) as verified at the time of signing.';
        $cert .= '</td></tr></table>';

        $pdf->writeHTML($cert, true, false, true, false, '');
    }

    return $pdf->Output('', 'S'); // 'S' = return as string
}
