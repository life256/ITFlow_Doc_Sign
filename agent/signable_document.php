<?php

/**
 * ITFlow Document Signing Plugin - Single Signable Document Detail Page
 */

require_once("includes/inc_all.php");
require_once("../includes/functions_signable.php");

enforceUserPermission('module_sales', 1);

$signable_document_id = intval($_GET['signable_document_id']);

// Fetch document
$sql = "SELECT sd.*, c.client_name, c.client_id, ct.contact_name, ct.contact_email
    FROM signable_documents sd
    LEFT JOIN clients c ON sd.signable_document_client_id = c.client_id
    LEFT JOIN contacts ct ON sd.signable_document_contact_id = ct.contact_id
    WHERE sd.signable_document_id = $signable_document_id";
$result = mysqli_query($mysqli, $sql);
$doc = mysqli_fetch_assoc($result);

if (!$doc) {
    header("Location: signable_documents.php");
    exit();
}

// Fetch signatures
$sig_sql = "SELECT * FROM signable_document_signatures
    WHERE signature_signable_document_id = $signable_document_id
    ORDER BY signature_created_at ASC";
$signatures = mysqli_query($mysqli, $sig_sql);

// Fetch history
$hist_sql = "SELECT * FROM signable_document_history
    WHERE signable_history_signable_document_id = $signable_document_id
    ORDER BY signable_history_created_at DESC";
$history = mysqli_query($mysqli, $hist_sql);

// Guest signing URL
$guest_url = '';
if (!empty($doc['signable_document_url_key'])) {
    $guest_url = $config_base_url . "/guest/guest_sign_document.php?signable_document_id=$signable_document_id&url_key=" . urlencode($doc['signable_document_url_key']);
}

$page_title = htmlspecialchars($doc['signable_document_title']);

?>

<div class="row">
    <!-- Main Content -->
    <div class="col-md-8">
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2">
                    <i class="fas fa-file-signature mr-2"></i><?php echo $page_title; ?>
                </h3>
                <div class="card-tools">
                    <?php echo getSignableStatusBadge($doc['signable_document_status']); ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Document Info -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Client:</strong>
                        <a href="client_overview.php?client_id=<?php echo intval($doc['client_id']); ?>">
                            <?php echo htmlspecialchars($doc['client_name']); ?>
                        </a><br>
                        <strong>Contact:</strong> <?php echo htmlspecialchars($doc['contact_name'] ?? 'Any contact'); ?><br>
                        <strong>Type:</strong> <?php echo htmlspecialchars(getSignableTypeLabel($doc['signable_document_type'])); ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Date:</strong> <?php echo htmlspecialchars($doc['signable_document_date']); ?><br>
                        <strong>Expires:</strong>
                        <?php
                        if (!empty($doc['signable_document_expire']) && $doc['signable_document_expire'] !== '0000-00-00') {
                            $expired = isSignableExpired($doc['signable_document_expire']);
                            echo '<span class="' . ($expired ? 'text-danger font-weight-bold' : '') . '">';
                            echo htmlspecialchars($doc['signable_document_expire']);
                            if ($expired) echo ' (Expired)';
                            echo '</span>';
                        } else {
                            echo 'No expiry';
                        }
                        ?><br>
                        <strong>Created:</strong> <?php echo htmlspecialchars($doc['signable_document_created_at']); ?>
                    </div>
                </div>

                <?php if (!empty($doc['signable_document_description'])): ?>
                <div class="mb-3">
                    <strong>Description:</strong><br>
                    <?php echo htmlspecialchars($doc['signable_document_description']); ?>
                </div>
                <?php endif; ?>

                <!-- Document Content -->
                <?php if (!empty($doc['signable_document_content'])): ?>
                <hr>
                <div class="document-content border rounded p-3 bg-white">
                    <?php echo $doc['signable_document_content']; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($doc['signable_document_file_name'])): ?>
                <hr>
                <div class="mb-3">
                    <strong>Attached PDF:</strong>
                    <a href="../uploads/signable_documents/<?php echo intval($doc['signable_document_id']); ?>/<?php echo htmlspecialchars($doc['signable_document_file_name']); ?>" target="_blank">
                        <i class="fas fa-file-pdf mr-1"></i><?php echo htmlspecialchars($doc['signable_document_file_name']); ?>
                    </a>
                </div>
                <?php endif; ?>

                <?php if (!empty($doc['signable_document_note'])): ?>
                <hr>
                <div class="mb-3">
                    <strong>Notes:</strong><br>
                    <?php echo nl2br(htmlspecialchars($doc['signable_document_note'])); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Signatures Section -->
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2"><i class="fas fa-signature mr-2"></i>Signatures</h3>
            </div>
            <div class="card-body">
                <?php if (mysqli_num_rows($signatures) > 0): ?>
                    <?php while ($sig = mysqli_fetch_assoc($signatures)): ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="row">
                            <div class="col-md-6">
                                <strong><?php echo htmlspecialchars($sig['signature_signer_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($sig['signature_signer_email']); ?></small><br>
                                <small class="text-muted">
                                    Signed: <?php echo htmlspecialchars($sig['signature_created_at']); ?>
                                    <?php if ($sig['signature_signer_ip']): ?>
                                        | IP: <?php echo htmlspecialchars($sig['signature_signer_ip']); ?>
                                    <?php endif; ?>
                                </small><br>
                                <small class="text-muted">
                                    Hash: <code><?php echo htmlspecialchars(substr($sig['signature_hash'], 0, 16)); ?>...</code>
                                </small>
                            </div>
                            <div class="col-md-6 text-right">
                                <img src="<?php echo htmlspecialchars($sig['signature_data']); ?>" alt="Signature" class="img-fluid border" style="max-height: 100px;">
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No signatures yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-md-4">
        <!-- Actions Card -->
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2"><i class="fas fa-tasks mr-2"></i>Actions</h3>
            </div>
            <div class="card-body">
                <?php if ($doc['signable_document_status'] === 'Draft'): ?>
                <a href="#" class="btn btn-block btn-outline-primary mb-2"
                   data-toggle="modal" data-target="#editSignableDocumentModal"
                   onclick="loadEditSignableDocument(<?php echo $signable_document_id; ?>)">
                    <i class="fas fa-edit mr-2"></i>Edit Document
                </a>
                <?php endif; ?>

                <?php if (in_array($doc['signable_document_status'], ['Draft', 'Sent', 'Viewed'])): ?>
                <a href="#" class="btn btn-block btn-outline-success mb-2"
                   data-toggle="modal" data-target="#sendSignableDocumentModal"
                   onclick="loadSendSignableDocument(<?php echo $signable_document_id; ?>)">
                    <i class="fas fa-paper-plane mr-2"></i>Send for Signature
                </a>
                <?php endif; ?>

                <?php if (!empty($guest_url)): ?>
                <div class="input-group mb-2">
                    <input type="text" class="form-control form-control-sm" id="guestUrl" value="<?php echo htmlspecialchars($guest_url); ?>" readonly>
                    <div class="input-group-append">
                        <button class="btn btn-sm btn-outline-secondary" onclick="copyGuestUrl()">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <a href="post.php?export_signable_document_pdf=<?php echo $signable_document_id; ?>" class="btn btn-block btn-outline-dark mb-2" target="_blank">
                    <i class="fas fa-file-pdf mr-2"></i>Export PDF
                </a>

                <?php if ($doc['signable_document_status'] !== 'Signed'): ?>
                <hr>
                <form method="post" action="post.php" onsubmit="return confirm('Are you sure you want to archive this document?')">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="archive_signable_document" value="<?php echo $signable_document_id; ?>">
                    <button type="submit" class="btn btn-block btn-outline-danger">
                        <i class="fas fa-archive mr-2"></i>Archive
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- History Card -->
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2"><i class="fas fa-history mr-2"></i>History</h3>
            </div>
            <div class="card-body p-0">
                <?php if (mysqli_num_rows($history) > 0): ?>
                <div class="timeline timeline-inverse p-3">
                    <?php while ($h = mysqli_fetch_assoc($history)): ?>
                    <div class="mb-3">
                        <small class="text-muted"><?php echo htmlspecialchars($h['signable_history_created_at']); ?></small><br>
                        <strong><?php echo htmlspecialchars($h['signable_history_status']); ?></strong>
                        <span class="text-muted"> - <?php echo htmlspecialchars($h['signable_history_description']); ?></span>
                        <?php if ($h['signable_history_ip']): ?>
                            <br><small class="text-muted">IP: <?php echo htmlspecialchars($h['signable_history_ip']); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No history yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function copyGuestUrl() {
    var input = document.getElementById('guestUrl');
    input.select();
    document.execCommand('copy');
    toastr.success('Signing URL copied to clipboard');
}
</script>

<?php
require_once("modals/signable_document/signable_document_edit.php");
require_once("modals/signable_document/signable_document_send.php");

require_once("../includes/footer.php");
