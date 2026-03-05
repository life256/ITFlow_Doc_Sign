<?php
// Edit Signable Document Modal
?>

<div class="modal fade" id="editSignableDocumentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="post.php" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="signable_document_id" id="editSignableDocId">
                <div class="modal-header bg-dark">
                    <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Edit Signable Document</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">

                    <div class="form-group">
                        <label>Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" id="editSignableTitle" required>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" id="editSignableDescription" rows="2"></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Document Type</label>
                                <select class="form-control" name="type" id="editSignableType">
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
                                <input type="date" class="form-control" name="date" id="editSignableDate" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Expiry Date</label>
                                <input type="date" class="form-control" name="expire" id="editSignableExpire">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Document Content</label>
                        <textarea class="form-control tinymcehtml" name="content" id="editSignableContent" rows="10"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Replace PDF</label>
                        <input type="file" class="form-control-file" name="document_file" accept=".pdf">
                        <small class="text-muted">Upload a new PDF to replace the existing one (if any).</small>
                    </div>

                    <div class="form-group">
                        <label>Notes (internal)</label>
                        <textarea class="form-control" name="note" id="editSignableNote" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="submit" name="edit_signable_document" class="btn btn-primary"><i class="fas fa-check mr-2"></i>Save Changes</button>
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function loadEditSignableDocument(id) {
    // Set the document ID immediately (not inside the callback)
    $('#editSignableDocId').val(id);

    $.get('ajax_signable.php?get_signable_document&signable_document_id=' + id, function(data) {
        var doc = JSON.parse(data);
        $('#editSignableTitle').val(doc.signable_document_title);
        $('#editSignableDescription').val(doc.signable_document_description);
        $('#editSignableType').val(doc.signable_document_type);
        $('#editSignableDate').val(doc.signable_document_date);
        $('#editSignableExpire').val(doc.signable_document_expire);
        $('#editSignableNote').val(doc.signable_document_note);

        // Set TinyMCE content if editor is initialized, otherwise set textarea
        var editor = tinymce.get('editSignableContent');
        if (editor) {
            editor.setContent(doc.signable_document_content || '');
        } else {
            $('#editSignableContent').val(doc.signable_document_content);
        }
    });
}
</script>
