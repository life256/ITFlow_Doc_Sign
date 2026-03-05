<?php

/**
 * ITFlow Document Signing Plugin - AJAX Handler
 *
 * Provides JSON responses for AJAX requests from modals and other UI components.
 */

// Buffer output to prevent stray whitespace/warnings from breaking JSON
ob_start();

require_once("../../config.php");
require_once("../../functions.php");
require_once("../../includes/check_login.php");
require_once("../../includes/functions_signable.php");

// Discard any output from includes, then set JSON header
ob_end_clean();
header('Content-Type: application/json');

// Get a single signable document (for edit/send modals)
if (isset($_GET['get_signable_document'])) {
    $id = intval($_GET['signable_document_id']);
    $sql = "SELECT sd.*, ct.contact_email
        FROM signable_documents sd
        LEFT JOIN contacts ct ON sd.signable_document_contact_id = ct.contact_id
        WHERE sd.signable_document_id = $id";
    $result = mysqli_query($mysqli, $sql);
    $doc = mysqli_fetch_assoc($result);
    echo json_encode($doc ?: []);
    exit();
}

echo json_encode(['error' => 'Invalid request']);
