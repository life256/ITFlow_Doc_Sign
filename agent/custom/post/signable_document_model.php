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

// String fields - sanitized
$title = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['title'] ?? '')));
$description = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['description'] ?? '')));
$date = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['date'] ?? date('Y-m-d'))));
$expire = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['expire'] ?? '')));
$note = mysqli_real_escape_string($mysqli, strip_tags(trim($_POST['note'] ?? '')));

// Validate type before escaping
$type_raw = strip_tags(trim($_POST['type'] ?? 'custom'));
$valid_types = ['custom', 'quote', 'msa', 'contract'];
if (!in_array($type_raw, $valid_types)) {
    $type_raw = 'custom';
}
$type = mysqli_real_escape_string($mysqli, $type_raw);

// Content - allow HTML but sanitize via HTMLPurifier if available
$content = '';
if (!empty($_POST['content'])) {
    if (class_exists('HTMLPurifier')) {
        $purifier_config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($purifier_config);
        $content = mysqli_real_escape_string($mysqli, $purifier->purify($_POST['content']));
    } else {
        // Strip dangerous tags but allow basic formatting
        $content = mysqli_real_escape_string($mysqli, strip_tags($_POST['content'], '<p><br><b><i><u><strong><em><ul><ol><li><h1><h2><h3><h4><h5><h6><table><tr><td><th><thead><tbody><hr><span><div><a><img>'));
    }
}

// Email fields - NOT SQL-escaped since they are used for email sending, not SQL
$email_to = filter_var(trim($_POST['email_to'] ?? ''), FILTER_SANITIZE_EMAIL);
$email_subject = strip_tags(trim($_POST['email_subject'] ?? ''));
$email_body = trim($_POST['email_body'] ?? '');

// Handle file upload
$uploaded_file_name = '';
$uploaded_file_tmp = '';
if (!empty($_FILES['document_file']['name']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
    $file_tmp = $_FILES['document_file']['tmp_name'];
    $file_name = basename($_FILES['document_file']['name']);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    // Validate both extension and MIME type
    if ($file_ext === 'pdf') {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);

        if ($mime_type === 'application/pdf') {
            // Use a sanitized filename
            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file_name);
            $uploaded_file_name = mysqli_real_escape_string($mysqli, $safe_name);
            $uploaded_file_tmp = $file_tmp;
        }
    }
}
