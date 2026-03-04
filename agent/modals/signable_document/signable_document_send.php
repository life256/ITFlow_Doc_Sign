<?php
// Send Signable Document Modal
?>

<div class="modal fade" id="sendSignableDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="post.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="signable_document_id" id="sendSignableDocId">
                <div class="modal-header bg-dark">
                    <h5 class="modal-title"><i class="fas fa-paper-plane mr-2"></i>Send for Signature</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">

                    <div class="form-group">
                        <label>Recipient Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email_to" id="sendSignableEmail" required>
                    </div>

                    <div class="form-group">
                        <label>Email Subject</label>
                        <input type="text" class="form-control" name="email_subject" id="sendSignableSubject" value="Document Ready for Signature">
                    </div>

                    <div class="form-group">
                        <label>Email Body</label>
                        <textarea class="form-control" name="email_body" id="sendSignableBody" rows="5">Hello,

Please review and sign the attached document at your earliest convenience.

Click the link below to view and sign:
[SIGNING_LINK]

Thank you.</textarea>
                    </div>

                    <small class="text-muted">The placeholder [SIGNING_LINK] will be replaced with the actual signing URL.</small>
                </div>
                <div class="modal-footer bg-white">
                    <button type="submit" name="send_signable_document" class="btn btn-success"><i class="fas fa-paper-plane mr-2"></i>Send</button>
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function loadSendSignableDocument(id) {
    $('#sendSignableDocId').val(id);
    // Pre-fill email from contact if available
    $.get('ajax_signable.php?get_signable_document&signable_document_id=' + id, function(data) {
        var doc = JSON.parse(data);
        if (doc.contact_email) {
            $('#sendSignableEmail').val(doc.contact_email);
        }
        $('#sendSignableSubject').val('Please Sign: ' + doc.signable_document_title);
    });
}
</script>
