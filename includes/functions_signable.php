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
    $base_dir = "../uploads/signable_documents/";
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
