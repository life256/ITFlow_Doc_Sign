<?php

/**
 * Send Documenso Document Modal
 *
 * The Documenso flow is: pick a template, pick a client, (optionally override
 * the approver), send. The template IS the document; prefill is pulled from
 * ITFlow data automatically by the matching prefill profile. No file upload.
 *
 * Expects (from the parent page's inc_all_custom bootstrap):
 *   $mysqli, $_SESSION['csrf_token'], and $documenso_templates_cfg / config.
 */

// Load templates for the dropdown if the parent didn't already.
if (!isset($documenso_cfg)) {
    $documenso_cfg = documensoLoadConfig();
}
$send_templates = $documenso_cfg ? ($documenso_cfg['templates'] ?? []) : [];
$send_default_key = $documenso_cfg ? ($documenso_cfg['default_template_key'] ?? '') : '';

$send_clients = mysqli_query($mysqli,
    "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
?>

<div class="modal fade" id="sendDocumensoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="post.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="modal-header bg-dark">
                    <h5 class="modal-title"><i class="fas fa-file-signature mr-2"></i>Send Document for Signature</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">

                    <div class="form-group">
                        <label>Document Type <span class="text-danger">*</span></label>
                        <select class="form-control" name="template_key" required>
                            <?php foreach ($send_templates as $key => $tpl): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($key === $send_default_key) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tpl['label'] ?? $key); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($send_templates)): ?>
                                <option value="">No templates configured</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Client <span class="text-danger">*</span></label>
                        <select class="form-control select2" name="client_id" required>
                            <option value="">Select a Client</option>
                            <?php while ($c = mysqli_fetch_assoc($send_clients)): ?>
                                <option value="<?php echo intval($c['client_id']); ?>">
                                    <?php echo htmlspecialchars($c['client_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="text-muted">
                            <i class="fas fa-info-circle mr-1"></i>The client's primary contact signs; document fields are
                            prefilled from the client's ITFlow data per the selected document type.
                        </small>
                    </div>

                    <hr>
                    <p class="text-muted mb-2"><small>Approver (optional &mdash; defaults to you)</small></p>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Approver Name</label>
                                <input type="text" class="form-control" name="approver_name" placeholder="(default approver)">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Approver Email</label>
                                <input type="email" class="form-control" name="approver_email" placeholder="(default approver)">
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-white">
                    <button type="submit" name="create_documenso_msa" class="btn btn-success">
                        <i class="fas fa-paper-plane mr-2"></i>Create &amp; Send
                    </button>
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
