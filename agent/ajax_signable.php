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

// Get quote content rendered as HTML (for populating document content)
if (isset($_GET['get_quote_content'])) {
    $quote_id = intval($_GET['quote_id']);

    $quote = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT q.*, c.client_name, comp.company_name
        FROM quotes q
        LEFT JOIN clients c ON q.quote_client_id = c.client_id
        LEFT JOIN companies comp ON comp.company_id = 1
        WHERE q.quote_id = $quote_id AND q.quote_archived_at IS NULL"));

    if (!$quote) {
        echo json_encode(['error' => 'Quote not found']);
        exit();
    }

    // Fetch line items
    $items_result = mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_quote_id = $quote_id ORDER BY item_order ASC");

    $currency_code = $quote['quote_currency_code'] ?: 'USD';

    // Build HTML table matching quote layout
    $html = '<h3>' . htmlspecialchars($quote['company_name']) . '</h3>';
    $html .= '<p><strong>Quote ' . htmlspecialchars($quote['quote_prefix'] . $quote['quote_number']) . '</strong>';
    $html .= ' &mdash; ' . htmlspecialchars($quote['client_name']) . '</p>';
    $html .= '<p>Date: ' . htmlspecialchars($quote['quote_date']);
    if (!empty($quote['quote_expire']) && $quote['quote_expire'] !== '0000-00-00') {
        $html .= ' &nbsp;|&nbsp; Expires: ' . htmlspecialchars($quote['quote_expire']);
    }
    $html .= '</p>';

    if (!empty($quote['quote_scope'])) {
        $html .= '<p><strong>Scope:</strong> ' . htmlspecialchars($quote['quote_scope']) . '</p>';
    }

    $html .= '<hr>';
    $html .= '<table style="width:100%; border-collapse:collapse;" border="1" cellpadding="6">';
    $html .= '<thead><tr style="background:#f4f4f4;">';
    $html .= '<th style="text-align:left;">Item</th>';
    $html .= '<th style="text-align:left;">Description</th>';
    $html .= '<th style="text-align:right;">Qty</th>';
    $html .= '<th style="text-align:right;">Price</th>';
    $html .= '<th style="text-align:right;">Tax</th>';
    $html .= '<th style="text-align:right;">Amount</th>';
    $html .= '</tr></thead><tbody>';

    $sub_total = 0;
    $total_tax = 0;

    while ($item = mysqli_fetch_assoc($items_result)) {
        $item_total = floatval($item['item_total']);
        $item_tax = floatval($item['item_tax']);
        $item_price = floatval($item['item_price']);
        $item_qty = floatval($item['item_quantity']);
        $sub_total += ($item_price * $item_qty);
        $total_tax += $item_tax;

        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($item['item_name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($item['item_description']) . '</td>';
        $html .= '<td style="text-align:right;">' . $item_qty . '</td>';
        $html .= '<td style="text-align:right;">' . numfmt_format_currency($currency_format, $item_price, $currency_code) . '</td>';
        $html .= '<td style="text-align:right;">' . numfmt_format_currency($currency_format, $item_tax, $currency_code) . '</td>';
        $html .= '<td style="text-align:right;">' . numfmt_format_currency($currency_format, $item_total, $currency_code) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    // Totals
    $discount = floatval($quote['quote_discount_amount']);
    $quote_amount = floatval($quote['quote_amount']);

    $html .= '<table style="width:50%; margin-left:auto; margin-top:10px;" cellpadding="4">';
    $html .= '<tr><td style="text-align:right;"><strong>Subtotal:</strong></td><td style="text-align:right;">' . numfmt_format_currency($currency_format, $sub_total, $currency_code) . '</td></tr>';
    if ($discount > 0) {
        $html .= '<tr><td style="text-align:right;"><strong>Discount:</strong></td><td style="text-align:right;">-' . numfmt_format_currency($currency_format, $discount, $currency_code) . '</td></tr>';
    }
    if ($total_tax > 0) {
        $html .= '<tr><td style="text-align:right;"><strong>Tax:</strong></td><td style="text-align:right;">' . numfmt_format_currency($currency_format, $total_tax, $currency_code) . '</td></tr>';
    }
    $html .= '<tr><td style="text-align:right;"><strong>Total:</strong></td><td style="text-align:right;"><strong>' . numfmt_format_currency($currency_format, $quote_amount, $currency_code) . '</strong></td></tr>';
    $html .= '</table>';

    // Notes
    if (!empty($quote['quote_note'])) {
        $html .= '<hr><p><strong>Notes:</strong></p><p>' . nl2br(htmlspecialchars($quote['quote_note'])) . '</p>';
    }

    $title = 'Quote ' . $quote['quote_prefix'] . $quote['quote_number'] . ' - ' . $quote['quote_scope'];

    echo json_encode(['html' => $html, 'title' => $title]);
    exit();
}

echo json_encode(['error' => 'Invalid request']);
