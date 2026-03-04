<?php

/**
 * ITFlow Document Signing Plugin - Input Sanitization Model
 *
 * This file sanitizes all POST inputs for signable document operations.
 * Included by signable_document.php POST handler before processing.
 */

// Integer fields
$signable_document_id = intval($_POST['signable_document_id'] ?? $_GET['signable_document_id'] ?? 0);
$client_id = intval($_POST['client_id'] ?? 0);
$contact_id = intval($_POST['contact_id'] ?? 0);
$quote_id = intval($_POST['quote_id'] ?? 0);

// String fields - sanitized
$title = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['title'] ?? '')));
$description = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['description'] ?? '')));
$type = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['type'] ?? 'custom')));
$date = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['date'] ?? date('Y-m-d'))));
$expire = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['expire'] ?? '')));
$note = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['note'] ?? '')));

// Content - allow HTML but sanitize via HTMLPurifier if available
$content = '';
if (!empty($_POST['content'])) {
    if (class_exists('HTMLPurifier')) {
        $purifier_config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($purifier_config);
        $content = $purifier->purify($_POST['content']);
    } else {
        $content = mysqli_real_escape_string($mysqli, $_POST['content']);
    }
    $content = mysqli_real_escape_string($mysqli, $content);
}

// Email fields (for sending)
$email_to = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['email_to'] ?? '')));
$email_subject = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['email_subject'] ?? '')));
$email_body = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['email_body'] ?? '')));

// Validate type
$valid_types = ['custom', 'quote', 'msa', 'contract'];
if (!in_array($type, $valid_types)) {
    $type = 'custom';
}

// Handle file upload
$uploaded_file_name = '';
if (!empty($_FILES['document_file']['name']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
    $file_tmp = $_FILES['document_file']['tmp_name'];
    $file_name = basename($_FILES['document_file']['name']);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    if ($file_ext === 'pdf') {
        $uploaded_file_name = mysqli_real_escape_string($mysqli, $file_name);
    }
}
