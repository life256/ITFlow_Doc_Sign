<?php

/**
 * ITFlow Document Signing Plugin (Documenso edition) - Signed PDF passthrough
 *
 * Streams the sealed PDF from Documenso to the browser. We fetch server-side
 * with the API key (a browser link can't send the Authorization header), then
 * stream the bytes. Access is gated by the same ITFlow login + permission as
 * the rest of the plugin.
 *
 * NOTE: this page must NOT emit any HTML/layout — it streams a binary PDF.
 * So it does its own minimal bootstrap (login + permission) rather than the
 * full inc_all_custom (which would render the page chrome).
 */

require_once "../../config.php";
require_once "../../functions.php";
require_once "../../includes/check_login.php";
require_once "../../includes/functions_documenso.php";

enforceUserPermission('module_sales', 1);

$documenso_document_id = intval($_GET['documenso_document_id'] ?? 0);

$doc = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT documenso_document_numeric_id, documenso_document_status, documenso_document_title
     FROM documenso_documents
     WHERE documenso_document_id = $documenso_document_id"));

if (!$doc || $doc['documenso_document_status'] !== 'Completed' || empty($doc['documenso_document_numeric_id'])) {
    http_response_code(404);
    echo "Signed PDF not available.";
    exit();
}

$cfg = documensoLoadConfig();
if (!$cfg) {
    http_response_code(500);
    echo "Documenso config not found.";
    exit();
}

list($code, $bytes) = documensoDownloadSignedPdf($cfg, intval($doc['documenso_document_numeric_id']));
if ($code !== 200 || $bytes === false || $bytes === '') {
    http_response_code(502);
    echo "Could not retrieve signed PDF from Documenso (HTTP $code).";
    exit();
}

// Build a safe download filename from the title.
$fname = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $doc['documenso_document_title']);
$fname = trim($fname, '_');
if ($fname === '') { $fname = 'signed_document'; }
$fname .= '_signed.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($bytes));
header('X-Content-Type-Options: nosniff');
echo $bytes;
exit();
