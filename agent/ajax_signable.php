<?php

/**
 * ITFlow Document Signing Plugin - AJAX Handler
 *
 * Provides JSON responses for AJAX requests from modals and other UI components.
 * This should be included or called from the main agent/ajax.php handler.
 */

require_once("../config.php");
require_once("../functions.php");
require_once("../includes/check_login.php");
require_once("../includes/functions_signable.php");

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

// Get quotes for a client (for quote picker dropdown)
if (isset($_GET['get_client_quotes'])) {
    $client_id = intval($_GET['client_id']);
    $sql = "SELECT quote_id, quote_prefix, quote_number, quote_scope, quote_status, quote_amount, quote_currency_code, quote_date
        FROM quotes
        WHERE quote_client_id = $client_id AND quote_archived_at IS NULL
        ORDER BY quote_number DESC";
    $result = mysqli_query($mysqli, $sql);
    $quotes = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $quotes[] = $row;
    }
    echo json_encode(['quotes' => $quotes]);
    exit();
}

echo json_encode(['error' => 'Invalid request']);
