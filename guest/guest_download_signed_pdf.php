<?php

/**
 * ITFlow Document Signing Plugin - Guest PDF Download
 *
 * Allows signers to download a copy of their signed document (with
 * Certificate of Completion) using the same url_key authentication
 * as the signing page.
 *
 * URL: /guest/guest_download_signed_pdf.php?signable_document_id=X&url_key=Y
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions_signable.php';

$signable_document_id = intval($_GET['signable_document_id'] ?? 0);
$url_key = $_GET['url_key'] ?? '';

// Validate access
if (!$signable_document_id || empty($url_key)) {
    http_response_code(404);
    echo "Document not found.";
    exit();
}

$url_key_escaped = mysqli_real_escape_string($mysqli, $url_key);
$doc = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT signable_document_title, signable_document_status
     FROM signable_documents
     WHERE signable_document_id = $signable_document_id
       AND signable_document_url_key = '$url_key_escaped'
       AND signable_document_archived_at IS NULL"));

if (!$doc) {
    http_response_code(404);
    echo "Document not found or link is invalid.";
    exit();
}

// Only allow download of signed documents
if ($doc['signable_document_status'] !== 'Signed') {
    http_response_code(403);
    echo "This document has not been signed yet.";
    exit();
}

$pdf_data = generateSignableDocumentPDF($mysqli, $signable_document_id, $config_company_name ?? 'ITFlow');

if ($pdf_data === false) {
    http_response_code(500);
    echo "Failed to generate PDF.";
    exit();
}

$filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $doc['signable_document_title']);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '_Signed.pdf"');
header('Content-Length: ' . strlen($pdf_data));
echo $pdf_data;
exit();
