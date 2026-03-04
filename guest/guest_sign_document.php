<?php

/**
 * ITFlow Document Signing Plugin - Guest Signing Page
 *
 * Accessible without authentication via a secret URL key.
 * URL: /guest/guest_sign_document.php?signable_document_id=X&url_key=Y
 */

require_once("../config.php");
require_once("../functions.php");
require_once("../includes/functions_signable.php");

$signable_document_id = intval($_GET['signable_document_id'] ?? 0);
$url_key = $_GET['url_key'] ?? '';

// Validate access
if (!$signable_document_id || empty($url_key)) {
    http_response_code(404);
    echo "Document not found.";
    exit();
}

$url_key_escaped = mysqli_real_escape_string($mysqli, $url_key);
$sql = "SELECT sd.*, c.client_name, ct.contact_name, ct.contact_email,
            comp.company_name, comp.company_logo
    FROM signable_documents sd
    LEFT JOIN clients c ON sd.signable_document_client_id = c.client_id
    LEFT JOIN contacts ct ON sd.signable_document_contact_id = ct.contact_id
    LEFT JOIN companies comp ON comp.company_id = 1
    WHERE sd.signable_document_id = $signable_document_id
      AND sd.signable_document_url_key = '$url_key_escaped'
      AND sd.signable_document_archived_at IS NULL";

$result = mysqli_query($mysqli, $sql);
$doc = mysqli_fetch_assoc($result);

if (!$doc) {
    http_response_code(404);
    echo "Document not found or link is invalid.";
    exit();
}

// Check if expired
if (isSignableExpired($doc['signable_document_expire'])) {
    // Update status if not already expired
    if ($doc['signable_document_status'] !== 'Expired') {
        mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_status = 'Expired' WHERE signable_document_id = $signable_document_id");
        addSignableHistory($mysqli, $signable_document_id, 'Expired', 'Document expired');
    }
}

// Re-fetch to get updated status
$doc = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT sd.*, c.client_name, ct.contact_name, ct.contact_email,
    comp.company_name, comp.company_logo
    FROM signable_documents sd
    LEFT JOIN clients c ON sd.signable_document_client_id = c.client_id
    LEFT JOIN contacts ct ON sd.signable_document_contact_id = ct.contact_id
    LEFT JOIN companies comp ON comp.company_id = 1
    WHERE sd.signable_document_id = $signable_document_id"));

// Update to Viewed if Sent
if ($doc['signable_document_status'] === 'Sent') {
    mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_status = 'Viewed' WHERE signable_document_id = $signable_document_id");
    addSignableHistory($mysqli, $signable_document_id, 'Viewed', 'Document viewed by recipient', $_SERVER['REMOTE_ADDR'] ?? null);
    $doc['signable_document_status'] = 'Viewed';

    // Create notification for agents
    mysqli_query($mysqli, "INSERT INTO notifications SET
        notification_type = 'Signable Document',
        notification = 'Document \"" . mysqli_real_escape_string($mysqli, $doc['signable_document_title']) . "\" has been viewed',
        notification_client_id = " . intval($doc['signable_document_client_id']));
}

// Check for existing signatures
$existing_sigs = mysqli_query($mysqli, "SELECT * FROM signable_document_signatures WHERE signature_signable_document_id = $signable_document_id ORDER BY signature_created_at ASC");
$is_signed = ($doc['signable_document_status'] === 'Signed');

// Handle signature submission
$sign_error = '';
$sign_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_signature'])) {
    $signer_name = trim($_POST['signer_name'] ?? '');
    $signer_email = trim($_POST['signer_email'] ?? '');
    $signature_data = $_POST['signature_data'] ?? '';

    // Validate
    if (empty($signer_name) || empty($signer_email) || empty($signature_data)) {
        $sign_error = 'Please fill in all fields and provide your signature.';
    } elseif (!filter_var($signer_email, FILTER_VALIDATE_EMAIL)) {
        $sign_error = 'Please enter a valid email address.';
    } elseif ($doc['signable_document_status'] === 'Signed') {
        $sign_error = 'This document has already been signed.';
    } elseif ($doc['signable_document_status'] === 'Expired') {
        $sign_error = 'This document has expired.';
    } elseif ($doc['signable_document_status'] === 'Declined') {
        $sign_error = 'This document has been declined.';
    } else {
        // Generate integrity hash
        $timestamp = date('Y-m-d H:i:s');
        $hash = generateSignatureHash(
            $doc['signable_document_content'] ?? '',
            $signature_data,
            $signer_email,
            $timestamp
        );

        $signer_name_escaped = mysqli_real_escape_string($mysqli, $signer_name);
        $signer_email_escaped = mysqli_real_escape_string($mysqli, $signer_email);
        $signature_data_escaped = mysqli_real_escape_string($mysqli, $signature_data);
        $ip = mysqli_real_escape_string($mysqli, $_SERVER['REMOTE_ADDR'] ?? '');
        $user_agent = mysqli_real_escape_string($mysqli, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));

        mysqli_query($mysqli, "INSERT INTO signable_document_signatures SET
            signature_signer_name = '$signer_name_escaped',
            signature_signer_email = '$signer_email_escaped',
            signature_signer_ip = '$ip',
            signature_signer_user_agent = '$user_agent',
            signature_data = '$signature_data_escaped',
            signature_hash = '$hash',
            signature_signable_document_id = $signable_document_id"
        );

        // Update document status to Signed
        mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_status = 'Signed' WHERE signable_document_id = $signable_document_id");

        addSignableHistory($mysqli, $signable_document_id, 'Signed', "Document signed by $signer_name ($signer_email)", $ip);

        // Create notification for agents
        mysqli_query($mysqli, "INSERT INTO notifications SET
            notification_type = 'Signable Document',
            notification = 'Document \"" . mysqli_real_escape_string($mysqli, $doc['signable_document_title']) . "\" has been signed by $signer_name_escaped',
            notification_client_id = " . intval($doc['signable_document_client_id']));

        if (function_exists('customAction')) {
            customAction('signable_document_signed', $signable_document_id);
        }

        $sign_success = true;
        $is_signed = true;
        $doc['signable_document_status'] = 'Signed';
    }
}

// Handle decline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decline_document'])) {
    if (!in_array($doc['signable_document_status'], ['Signed', 'Expired', 'Declined'])) {
        mysqli_query($mysqli, "UPDATE signable_documents SET signable_document_status = 'Declined' WHERE signable_document_id = $signable_document_id");
        addSignableHistory($mysqli, $signable_document_id, 'Declined', 'Document declined by recipient', $_SERVER['REMOTE_ADDR'] ?? null);

        mysqli_query($mysqli, "INSERT INTO notifications SET
            notification_type = 'Signable Document',
            notification = 'Document \"" . mysqli_real_escape_string($mysqli, $doc['signable_document_title']) . "\" has been declined',
            notification_client_id = " . intval($doc['signable_document_client_id']));

        $doc['signable_document_status'] = 'Declined';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($doc['signable_document_title']); ?> - Sign Document</title>
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../plugins/adminlte/css/adminlte.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .document-container { max-width: 900px; margin: 30px auto; }
        .signature-pad-wrapper { position: relative; }
        #signature-pad { border: 2px dashed #ccc; border-radius: 4px; background: #fff; cursor: crosshair; width: 100%; height: 200px; }
        #signature-pad.signing { border-color: #007bff; }
        .signature-pad-placeholder {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            color: #aaa; font-size: 1.1rem; pointer-events: none;
        }
    </style>
</head>
<body>
<div class="document-container">
    <!-- Company Header -->
    <div class="card">
        <div class="card-body text-center">
            <?php if (!empty($doc['company_logo'])): ?>
                <img src="../uploads/settings/<?php echo htmlspecialchars($doc['company_logo']); ?>" alt="Logo" class="mb-2" style="max-height: 60px;">
                <br>
            <?php endif; ?>
            <h4><?php echo htmlspecialchars($doc['company_name'] ?? ''); ?></h4>
        </div>
    </div>

    <!-- Document Content -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-file-signature mr-2"></i><?php echo htmlspecialchars($doc['signable_document_title']); ?>
            </h3>
            <div class="card-tools">
                <?php echo getSignableStatusBadge($doc['signable_document_status']); ?>
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($doc['signable_document_description'])): ?>
                <p class="text-muted"><?php echo htmlspecialchars($doc['signable_document_description']); ?></p>
                <hr>
            <?php endif; ?>

            <?php if (!empty($doc['signable_document_content'])): ?>
                <div class="document-content">
                    <?php echo $doc['signable_document_content']; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($doc['signable_document_file_name'])): ?>
                <div class="text-center mt-3">
                    <a href="../uploads/signable_documents/<?php echo intval($doc['signable_document_id']); ?>/<?php echo htmlspecialchars($doc['signable_document_file_name']); ?>" target="_blank" class="btn btn-outline-primary">
                        <i class="fas fa-file-pdf mr-2"></i>View PDF Document
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Existing Signatures -->
    <?php if (mysqli_num_rows($existing_sigs) > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-signature mr-2"></i>Signatures</h3>
        </div>
        <div class="card-body">
            <?php while ($sig = mysqli_fetch_assoc($existing_sigs)): ?>
            <div class="border rounded p-3 mb-2">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <strong><?php echo htmlspecialchars($sig['signature_signer_name']); ?></strong><br>
                        <small class="text-muted"><?php echo htmlspecialchars($sig['signature_created_at']); ?></small>
                    </div>
                    <div class="col-md-6 text-right">
                        <img src="<?php echo htmlspecialchars($sig['signature_data']); ?>" alt="Signature" class="img-fluid border" style="max-height: 80px;">
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Success Message -->
    <?php if ($sign_success): ?>
    <div class="card border-success">
        <div class="card-body text-center">
            <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
            <h4 class="text-success">Document Signed Successfully</h4>
            <p>Thank you for signing this document. A record of your signature has been saved.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Signing Form -->
    <?php if (!$is_signed && !in_array($doc['signable_document_status'], ['Expired', 'Declined'])): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-pen mr-2"></i>Sign This Document</h3>
        </div>
        <div class="card-body">
            <?php if ($sign_error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($sign_error); ?></div>
            <?php endif; ?>

            <form method="post" id="signatureForm">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="signer_name" id="signerName" required
                                   value="<?php echo htmlspecialchars($doc['contact_name'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="signer_email" id="signerEmail" required
                                   value="<?php echo htmlspecialchars($doc['contact_email'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Signature <span class="text-danger">*</span></label>
                    <div class="signature-pad-wrapper">
                        <canvas id="signature-pad"></canvas>
                        <div class="signature-pad-placeholder" id="sigPadPlaceholder">Draw your signature here</div>
                    </div>
                    <input type="hidden" name="signature_data" id="signatureData">
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="clearSignature">
                        <i class="fas fa-eraser mr-1"></i>Clear Signature
                    </button>
                </div>

                <div class="form-group">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="agreeCheckbox" required>
                        <label class="custom-control-label" for="agreeCheckbox">
                            I agree that my electronic signature is the legal equivalent of my manual signature.
                        </label>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <button type="submit" name="submit_signature" class="btn btn-success btn-block btn-lg">
                            <i class="fas fa-check mr-2"></i>Sign Document
                        </button>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" name="decline_document" class="btn btn-outline-danger btn-block" onclick="return confirm('Are you sure you want to decline this document?')">
                            <i class="fas fa-times mr-2"></i>Decline
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php elseif ($doc['signable_document_status'] === 'Expired'): ?>
    <div class="card border-dark">
        <div class="card-body text-center py-4">
            <i class="fas fa-clock text-dark fa-3x mb-3"></i>
            <h4>This Document Has Expired</h4>
            <p class="text-muted">This document is no longer available for signing. Please contact the sender for a new document.</p>
        </div>
    </div>
    <?php elseif ($doc['signable_document_status'] === 'Declined'): ?>
    <div class="card border-danger">
        <div class="card-body text-center py-4">
            <i class="fas fa-times-circle text-danger fa-3x mb-3"></i>
            <h4>This Document Has Been Declined</h4>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="text-center text-muted my-4">
        <small>Powered by ITFlow Document Signing</small>
    </div>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../js/signature_pad.js"></script>
<script>
$(document).ready(function() {
    var canvas = document.getElementById('signature-pad');
    if (canvas) {
        var signaturePad = new SignaturePad(canvas);
        var placeholder = document.getElementById('sigPadPlaceholder');

        // Resize canvas
        function resizeCanvas() {
            var ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
            signaturePad.clear();
        }
        window.addEventListener("resize", resizeCanvas);
        resizeCanvas();

        // Hide placeholder when signing
        signaturePad.addEventListener("beginStroke", function() {
            canvas.classList.add('signing');
            placeholder.style.display = 'none';
        });

        // Clear button
        document.getElementById('clearSignature').addEventListener('click', function() {
            signaturePad.clear();
            placeholder.style.display = 'block';
            canvas.classList.remove('signing');
        });

        // Form submit
        document.getElementById('signatureForm').addEventListener('submit', function(e) {
            if (e.submitter && e.submitter.name === 'decline_document') {
                return true;
            }
            if (signaturePad.isEmpty()) {
                e.preventDefault();
                alert('Please provide your signature.');
                return false;
            }
            document.getElementById('signatureData').value = signaturePad.toDataURL();
        });
    }
});
</script>
</body>
</html>
