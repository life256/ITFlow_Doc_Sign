<?php

/**
 * ITFlow Document Signing Plugin - Signable Documents List Page
 *
 * This page is included via the ITFlow custom extension mechanism.
 * It expects the standard ITFlow agent includes to be loaded (inc_all.php).
 *
 * Usage: Include this in your ITFlow installation at agent/signable_documents.php
 * and ensure inc_all.php is available.
 */

// Standard ITFlow agent includes
require_once("includes/inc_all.php");
require_once("../includes/functions_signable.php");

// Module access check - uses sales module permission
enforceUserPermission('module_sales', 1);

// Filters
$status_filter = '';
if (!empty($_GET['status'])) {
    $status_filter = mysqli_real_escape_string($mysqli, $_GET['status']);
}
$client_filter = 0;
if (!empty($_GET['client_id'])) {
    $client_filter = intval($_GET['client_id']);
}
$search_query = '';
if (!empty($_GET['q'])) {
    $search_query = mysqli_real_escape_string($mysqli, $_GET['q']);
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Build query
$where = "WHERE signable_document_archived_at IS NULL";
if ($status_filter) {
    $where .= " AND signable_document_status = '$status_filter'";
}
if ($client_filter) {
    $where .= " AND signable_document_client_id = $client_filter";
}
if ($search_query) {
    $where .= " AND (signable_document_title LIKE '%$search_query%' OR signable_document_description LIKE '%$search_query%')";
}

// Count total
$count_sql = "SELECT COUNT(*) AS total FROM signable_documents $where";
$count_result = mysqli_query($mysqli, $count_sql);
$total_rows = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_rows / $per_page);

// Fetch documents
$sql = "SELECT sd.*, c.client_name, ct.contact_name
    FROM signable_documents sd
    LEFT JOIN clients c ON sd.signable_document_client_id = c.client_id
    LEFT JOIN contacts ct ON sd.signable_document_contact_id = ct.contact_id
    $where
    ORDER BY sd.signable_document_created_at DESC
    LIMIT $per_page OFFSET $offset";
$result = mysqli_query($mysqli, $sql);

?>

<!-- Page Content -->
<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-file-signature mr-2"></i>Signable Documents</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addSignableDocumentModal">
                <i class="fas fa-plus mr-2"></i>New Document
            </button>
        </div>
    </div>

    <div class="card-body">
        <!-- Filters -->
        <form method="get" autocomplete="off" class="mb-3">
            <div class="row">
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" name="q" placeholder="Search..." value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
                        <div class="input-group-append">
                            <button class="btn btn-dark" type="submit"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <select class="form-control form-control-sm" name="status" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php
                        $statuses = ['Draft', 'Sent', 'Viewed', 'Signed', 'Declined', 'Expired'];
                        foreach ($statuses as $s) {
                            $selected = ($status_filter === $s) ? 'selected' : '';
                            echo "<option value=\"$s\" $selected>$s</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <?php if ($status_filter || $search_query || $client_filter): ?>
                        <a href="signable_documents.php" class="btn btn-sm btn-outline-secondary">Clear Filters</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Documents Table -->
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($session_dark_mode) { echo "thead-dark"; } ?>">
                    <tr>
                        <th>Title</th>
                        <th>Client</th>
                        <th>Contact</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Expires</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($doc = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td>
                            <a href="signable_document.php?signable_document_id=<?php echo intval($doc['signable_document_id']); ?>">
                                <?php echo htmlspecialchars($doc['signable_document_title']); ?>
                            </a>
                        </td>
                        <td>
                            <a href="client_overview.php?client_id=<?php echo intval($doc['signable_document_client_id']); ?>">
                                <?php echo htmlspecialchars($doc['client_name']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($doc['contact_name'] ?? 'Any'); ?></td>
                        <td><?php echo htmlspecialchars(getSignableTypeLabel($doc['signable_document_type'])); ?></td>
                        <td><?php echo getSignableStatusBadge($doc['signable_document_status']); ?></td>
                        <td><?php echo htmlspecialchars($doc['signable_document_date']); ?></td>
                        <td>
                            <?php
                            if (!empty($doc['signable_document_expire']) && $doc['signable_document_expire'] !== '0000-00-00') {
                                $expired = isSignableExpired($doc['signable_document_expire']);
                                echo '<span class="' . ($expired ? 'text-danger' : '') . '">';
                                echo htmlspecialchars($doc['signable_document_expire']);
                                echo '</span>';
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td class="text-center">
                            <div class="dropdown dropleft">
                                <button class="btn btn-sm btn-secondary dropdown-toggle" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="signable_document.php?signable_document_id=<?php echo intval($doc['signable_document_id']); ?>">
                                        <i class="fas fa-eye mr-2"></i>View
                                    </a>
                                    <?php if ($doc['signable_document_status'] === 'Draft'): ?>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#editSignableDocumentModal"
                                       onclick="loadEditSignableDocument(<?php echo intval($doc['signable_document_id']); ?>)">
                                        <i class="fas fa-edit mr-2"></i>Edit
                                    </a>
                                    <?php endif; ?>
                                    <?php if (in_array($doc['signable_document_status'], ['Draft', 'Sent', 'Viewed'])): ?>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#sendSignableDocumentModal"
                                       onclick="loadSendSignableDocument(<?php echo intval($doc['signable_document_id']); ?>)">
                                        <i class="fas fa-paper-plane mr-2"></i>Send
                                    </a>
                                    <?php endif; ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?archive_signable_document=<?php echo intval($doc['signable_document_id']); ?>">
                                        <i class="fas fa-archive mr-2"></i>Archive
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if ($total_rows === 0): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="fas fa-file-signature fa-3x mb-3 d-block"></i>
                            No signable documents found.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer text-right">
        <nav>
            <ul class="pagination pagination-sm justify-content-end mb-0">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&q=<?php echo urlencode($search_query); ?>&client_id=<?php echo $client_filter; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php
// Include modals
require_once("modals/signable_document/signable_document_add.php");
require_once("modals/signable_document/signable_document_edit.php");
require_once("modals/signable_document/signable_document_send.php");

require_once("../includes/footer.php");
