<?php
// Add Signable Document Modal
// Expects $mysqli to be available from the parent page
$clients_result = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
?>

<div class="modal fade" id="addSignableDocumentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="post.php" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="modal-header bg-dark">
                    <h5 class="modal-title"><i class="fas fa-file-signature mr-2"></i>New Signable Document</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">

                    <div class="form-group">
                        <label>Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" required>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" rows="2"></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Client <span class="text-danger">*</span></label>
                                <select class="form-control select2" name="client_id" id="addSignableClientSelect" required>
                                    <option value="">Select a Client</option>
                                    <?php while ($client = mysqli_fetch_assoc($clients_result)): ?>
                                        <option value="<?php echo intval($client['client_id']); ?>">
                                            <?php echo htmlspecialchars($client['client_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Contact</label>
                                <select class="form-control" name="contact_id" id="addSignableContactSelect">
                                    <option value="0">Any Contact</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Document Type</label>
                                <select class="form-control" name="type" id="addSignableTypeSelect">
                                    <option value="custom">Custom Document</option>
                                    <option value="quote">Quote</option>
                                    <option value="msa">MSA</option>
                                    <option value="contract">Contract</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Expiry Date</label>
                                <input type="date" class="form-control" name="expire">
                            </div>
                        </div>
                    </div>

                    <!-- Quote picker (shown when type = quote and client is selected) -->
                    <div class="form-group" id="addSignableQuoteGroup" style="display:none;">
                        <label>Select Quote</label>
                        <select class="form-control" name="quote_id" id="addSignableQuoteSelect">
                            <option value="0">-- Select a Quote --</option>
                        </select>
                        <small class="text-muted">Quote content will be loaded into the document.</small>
                    </div>

                    <div class="form-group">
                        <label>Document Content</label>
                        <textarea class="form-control tinymcehtml" name="content" rows="10"></textarea>
                        <small class="text-muted">Rich text content of the document to be signed.</small>
                    </div>

                    <div class="form-group">
                        <label>Or Upload PDF</label>
                        <input type="file" class="form-control-file" name="document_file" accept=".pdf">
                        <small class="text-muted">Alternatively, upload a PDF document.</small>
                    </div>

                    <div class="form-group">
                        <label>Notes (internal)</label>
                        <textarea class="form-control" name="note" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="submit" name="add_signable_document" class="btn btn-primary"><i class="fas fa-check mr-2"></i>Create Document</button>
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    var typeSelect = $('#addSignableTypeSelect');
    var clientSelect = $('#addSignableClientSelect');
    var quoteGroup = $('#addSignableQuoteGroup');
    var quoteSelect = $('#addSignableQuoteSelect');

    // Load contacts when client is selected (uses ITFlow's built-in endpoint)
    clientSelect.on('change', function() {
        var clientId = $(this).val();
        var contactSelect = $('#addSignableContactSelect');
        contactSelect.html('<option value="0">Any Contact</option>');
        if (clientId) {
            $.get('ajax.php?get_client_contacts&client_id=' + clientId, function(data) {
                var response = JSON.parse(data);
                if (response.contacts) {
                    response.contacts.forEach(function(contact) {
                        contactSelect.append('<option value="' + contact.contact_id + '">' + contact.contact_name + '</option>');
                    });
                }
            });
        }
        // Refresh quotes if type is quote
        if (typeSelect.val() === 'quote') {
            loadClientQuotes(clientId);
        }
    });

    // Show/hide quote picker based on type
    typeSelect.on('change', function() {
        if ($(this).val() === 'quote' && clientSelect.val()) {
            loadClientQuotes(clientSelect.val());
            quoteGroup.show();
        } else {
            quoteGroup.hide();
            quoteSelect.html('<option value="0">-- Select a Quote --</option>');
        }
    });

    // Auto-fill title when a quote is selected (PDF is generated server-side on submit)
    quoteSelect.on('change', function() {
        var selected = $(this).find('option:selected');
        if (selected.val() && selected.val() !== '0') {
            var titleInput = $('#addSignableDocumentModal input[name="title"]');
            if (!titleInput.val()) {
                titleInput.val(selected.text().trim());
            }
        }
    });

    function loadClientQuotes(clientId) {
        quoteSelect.html('<option value="0">Loading...</option>');
        if (clientId) {
            $.ajax({
                url: 'ajax_signable.php?get_client_quotes&client_id=' + clientId,
                dataType: 'json',
                success: function(response) {
                    quoteSelect.html('<option value="0">-- Select a Quote --</option>');
                    if (response.quotes && response.quotes.length > 0) {
                        response.quotes.forEach(function(q) {
                            quoteSelect.append('<option value="' + q.quote_id + '">' + q.quote_prefix + q.quote_number + ' - ' + q.quote_scope + ' (' + q.quote_status + ')</option>');
                        });
                    } else {
                        quoteSelect.html('<option value="0">No quotes found for this client</option>');
                    }
                    quoteGroup.show();
                },
                error: function(xhr) {
                    quoteSelect.html('<option value="0">Error loading quotes</option>');
                    quoteGroup.show();
                    console.error('Quote load error:', xhr.status, xhr.responseText);
                }
            });
        }
    }
});
</script>
