<?php

/**
 * ITFlow Document Signing Plugin (Documenso edition) - Document Detail Page
 */

require_once "includes/inc_all_custom.php";
require_once "../../includes/functions_documenso.php";

enforceUserPermission('module_sales', 1);

$documenso_document_id = intval($_GET['documenso_document_id'] ?? 0);

$doc = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT dd.*, c.client_name, ct.contact_name, ct.contact_email
     FROM documenso_documents dd
     LEFT JOIN clients c ON dd.documenso_document_client_id = c.client_id
     LEFT JOIN contacts ct ON dd.documenso_document_contact_id = ct.contact_id
     WHERE dd.documenso_document_id = $documenso_document_id"));

if (!$doc) {
    header("Location: documenso_documents.php");
    exit();
}

$recipients = mysqli_query($mysqli,
    "SELECT * FROM documenso_document_recipients
     WHERE documenso_recipient_document_id = $documenso_document_id
     ORDER BY documenso_recipient_signing_order ASC");

$history = mysqli_query($mysqli,
    "SELECT * FROM documenso_document_history
     WHERE documenso_history_document_id = $documenso_document_id
     ORDER BY documenso_history_created_at DESC");

$documenso_cfg = documensoLoadConfig();
$status = $doc['documenso_document_status'];
$is_completed = ($status === 'Completed');
$numeric_id = $doc['documenso_document_numeric_id'];

?>

<div class="row">
    <div class="col-md-8">
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2">
                    <i class="fas fa-file-signature mr-2"></i><?php echo htmlspecialchars($doc['documenso_document_title']); ?>
                </h3>
                <div class="card-tools">
                    <?php echo documensoStatusBadge($status); ?>
                </div>
            </div>
            <div class="card-body">

                <dl class="row mb-0">
                    <dt class="col-sm-3">Client</dt>
                    <dd class="col-sm-9">
                        <a href="../client_overview.php?client_id=<?php echo intval($doc['documenso_document_client_id']); ?>">
                            <?php echo htmlspecialchars($doc['client_name'] ?? '-'); ?>
                        </a>
                    </dd>
                    <dt class="col-sm-3">Signer</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($doc['contact_name'] ?? '-'); ?>
                        <?php if (!empty($doc['contact_email'])): ?>
                            <span class="text-muted">&lt;<?php echo htmlspecialchars($doc['contact_email']); ?>&gt;</span>
                        <?php endif; ?>
                    </dd>
                    <?php if (!empty($doc['documenso_document_business_address'])): ?>
                    <dt class="col-sm-3">Address</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($doc['documenso_document_business_address']); ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($doc['documenso_document_monthly_rate'])): ?>
                    <dt class="col-sm-3">Monthly Rate</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($doc['documenso_document_monthly_rate']); ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($doc['documenso_document_effective_date'])): ?>
                    <dt class="col-sm-3">Effective Date</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($doc['documenso_document_effective_date']); ?></dd>
                    <?php endif; ?>
                    <dt class="col-sm-3">Sent</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($doc['documenso_document_sent_at'] ?? '-'); ?></dd>
                    <?php if (!empty($doc['documenso_document_completed_at'])): ?>
                    <dt class="col-sm-3">Completed</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($doc['documenso_document_completed_at']); ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Recipients / signing links -->
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2"><i class="fas fa-users mr-2"></i>Recipients</h3>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <thead class="text-dark <?php if ($session_dark_mode) { echo 'thead-dark'; } ?>">
                        <tr><th>#</th><th>Role</th><th>Name</th><th>Status</th><th>Signing Link</th></tr>
                    </thead>
                    <tbody>
                        <?php while ($r = mysqli_fetch_assoc($recipients)): ?>
                        <tr>
                            <td><?php echo intval($r['documenso_recipient_signing_order']); ?></td>
                            <td><?php echo htmlspecialchars($r['documenso_recipient_role']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($r['documenso_recipient_name']); ?><br>
                                <small class="text-muted"><?php echo htmlspecialchars($r['documenso_recipient_email']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($r['documenso_recipient_signing_status'] ?: 'NOT_SIGNED'); ?></td>
                            <td>
                                <?php if (!empty($r['documenso_recipient_signing_url']) && !$is_completed): ?>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control form-control-sm" readonly
                                               value="<?php echo htmlspecialchars($r['documenso_recipient_signing_url']); ?>"
                                               id="signurl<?php echo intval($r['documenso_recipient_id']); ?>">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-secondary" type="button"
                                                onclick="navigator.clipboard.writeText(document.getElementById('signurl<?php echo intval($r['documenso_recipient_id']); ?>').value)">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sidebar: actions + history -->
    <div class="col-md-4">
        <div class="card card-dark">
            <div class="card-header py-2"><h3 class="card-title mt-2"><i class="fas fa-bolt mr-2"></i>Actions</h3></div>
            <div class="card-body">
                <?php if (!in_array($status, ['Completed', 'Cancelled'])): ?>
                <form method="post" action="post.php" class="mb-2">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="documenso_document_id" value="<?php echo $documenso_document_id; ?>">
                    <button type="submit" name="refresh_documenso_status" class="btn btn-block btn-primary">
                        <i class="fas fa-sync mr-2"></i>Refresh Status
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($is_completed && $numeric_id): ?>
                <a href="documenso_download.php?documenso_document_id=<?php echo $documenso_document_id; ?>"
                   class="btn btn-block btn-success mb-2" target="_blank" rel="noopener">
                    <i class="fas fa-download mr-2"></i>Download Signed PDF
                </a>
                <small class="text-muted d-block mb-2">Streams the sealed PDF from Documenso.</small>
                <?php endif; ?>

                <form method="post" action="post.php" onsubmit="return confirm('Archive this document?')">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="archive_documenso_document" value="<?php echo $documenso_document_id; ?>">
                    <button type="submit" class="btn btn-block btn-outline-danger">
                        <i class="fas fa-archive mr-2"></i>Archive
                    </button>
                </form>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header py-2"><h3 class="card-title mt-2"><i class="fas fa-history mr-2"></i>History</h3></div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <?php while ($h = mysqli_fetch_assoc($history)): ?>
                    <li class="mb-2">
                        <strong><?php echo htmlspecialchars($h['documenso_history_status']); ?></strong>
                        <span class="text-muted float-right"><small><?php echo htmlspecialchars($h['documenso_history_created_at']); ?></small></span>
                        <br><small><?php echo htmlspecialchars($h['documenso_history_description']); ?></small>
                    </li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>

        <a href="documenso_documents.php" class="btn btn-block btn-light"><i class="fas fa-arrow-left mr-2"></i>Back to List</a>
    </div>
</div>

<?php
require_once "../../includes/footer.php";
