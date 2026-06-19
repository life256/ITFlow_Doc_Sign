<?php

/**
 * ITFlow Document Signing Plugin (Documenso edition) - Documents List Page
 *
 * Lives in agent/custom/ and uses ITFlow's custom module bootstrap.
 */

require_once "includes/inc_all_custom.php";
require_once "../../includes/functions_documenso.php";

enforceUserPermission('module_sales', 1);

// Load config for template labels (type column) + the send dropdown.
$documenso_cfg = documensoLoadConfig();
$documenso_templates_cfg = $documenso_cfg ? ($documenso_cfg['templates'] ?? []) : [];

// Filters
$status_filter = !empty($_GET['status']) ? mysqli_real_escape_string($mysqli, $_GET['status']) : '';
$client_filter = !empty($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$search_query  = !empty($_GET['q']) ? mysqli_real_escape_string($mysqli, $_GET['q']) : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Archive filter: ITFlow's inc_all_custom sets $archived/$archive_query.
// We mirror that pattern on our own archived_at column.
$where = $archived
    ? "WHERE documenso_document_archived_at IS NOT NULL"
    : "WHERE documenso_document_archived_at IS NULL";
if ($status_filter) {
    $where .= " AND documenso_document_status = '$status_filter'";
}
if ($client_filter) {
    $where .= " AND documenso_document_client_id = $client_filter";
}
if ($search_query) {
    $where .= " AND (documenso_document_title LIKE '%$search_query%' OR documenso_document_business_name LIKE '%$search_query%')";
}

$count_result = mysqli_query($mysqli, "SELECT COUNT(*) AS total FROM documenso_documents $where");
$total_rows = intval(mysqli_fetch_assoc($count_result)['total']);
$total_pages = $total_rows > 0 ? ceil($total_rows / $per_page) : 1;

$sql = "SELECT dd.*, c.client_name, ct.contact_name
    FROM documenso_documents dd
    LEFT JOIN clients c ON dd.documenso_document_client_id = c.client_id
    LEFT JOIN contacts ct ON dd.documenso_document_contact_id = ct.contact_id
    $where
    ORDER BY dd.documenso_document_created_at DESC
    LIMIT $per_page OFFSET $offset";
$result = mysqli_query($mysqli, $sql);

// Helper: template key -> friendly label
function documensoTypeLabel($type_key, $templates_cfg) {
    if (isset($templates_cfg[$type_key]['label'])) {
        return $templates_cfg[$type_key]['label'];
    }
    return ucfirst($type_key);
}

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-file-signature mr-2"></i>Signed Documents</h3>
        <div class="card-tools">
            <a href="?archived=<?php echo $archived ? 0 : 1; ?>"
               class="btn btn-<?php echo $archived ? 'primary' : 'outline-secondary'; ?> mr-2">
                <i class="fa fa-fw fa-archive mr-2"></i>Archived
            </a>
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#sendDocumensoModal">
                <i class="fas fa-plus mr-2"></i>New Document
            </button>
        </div>
    </div>

    <div class="card-body">
        <form method="get" autocomplete="off" class="mb-3">
            <input type="hidden" name="archived" value="<?php echo $archived; ?>">
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
                        foreach (['Draft', 'Pending', 'Completed', 'Cancelled', 'Error'] as $s) {
                            $selected = ($status_filter === $s) ? 'selected' : '';
                            echo "<option value=\"$s\" $selected>$s</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <?php if ($status_filter || $search_query || $client_filter): ?>
                        <a href="documenso_documents.php" class="btn btn-sm btn-outline-secondary">Clear Filters</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($session_dark_mode) { echo "thead-dark"; } ?>">
                    <tr>
                        <th>Title</th>
                        <th>Client</th>
                        <th>Signer</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Sent</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($doc = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td>
                            <a href="documenso_document.php?documenso_document_id=<?php echo intval($doc['documenso_document_id']); ?>">
                                <?php echo htmlspecialchars($doc['documenso_document_title']); ?>
                            </a>
                        </td>
                        <td>
                            <a href="../client_overview.php?client_id=<?php echo intval($doc['documenso_document_client_id']); ?>">
                                <?php echo htmlspecialchars($doc['client_name'] ?? '-'); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($doc['contact_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars(documensoTypeLabel($doc['documenso_document_type'], $documenso_templates_cfg)); ?></td>
                        <td><?php echo documensoStatusBadge($doc['documenso_document_status']); ?></td>
                        <td><?php echo htmlspecialchars($doc['documenso_document_sent_at'] ? date('Y-m-d', strtotime($doc['documenso_document_sent_at'])) : '-'); ?></td>
                        <td class="text-center">
                            <div class="dropdown dropleft">
                                <button class="btn btn-sm btn-secondary dropdown-toggle" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="documenso_document.php?documenso_document_id=<?php echo intval($doc['documenso_document_id']); ?>">
                                        <i class="fas fa-eye mr-2"></i>View
                                    </a>
                                    <?php if (!in_array($doc['documenso_document_status'], ['Completed', 'Cancelled'])): ?>
                                    <form method="post" action="post.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="documenso_document_id" value="<?php echo intval($doc['documenso_document_id']); ?>">
                                        <button type="submit" name="refresh_documenso_status" class="dropdown-item">
                                            <i class="fas fa-sync mr-2"></i>Refresh Status
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <div class="dropdown-divider"></div>
                                    <form method="post" action="post.php" class="d-inline" onsubmit="return confirm('Archive this document?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="archive_documenso_document" value="<?php echo intval($doc['documenso_document_id']); ?>">
                                        <button type="submit" class="dropdown-item text-danger">
                                            <i class="fas fa-archive mr-2"></i>Archive
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if ($total_rows === 0): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="fas fa-file-signature fa-3x mb-3 d-block"></i>
                            No documents found.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <nav>
            <ul class="pagination pagination-sm justify-content-center">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?archived=<?php echo $archived; ?>&page=<?php echo $p; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?><?php echo $search_query ? '&q=' . urlencode($_GET['q']) : ''; ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php
// The send modal (template dropdown + client picker) is included here.
require_once "modals/documenso_document/documenso_document_send.php";

require_once "../../includes/footer.php";
