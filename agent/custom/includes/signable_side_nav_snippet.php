
                <!-- ITFlow Document Signing Plugin -->
                <li class="nav-header">DOCUMENT SIGNING</li>

                <li class="nav-item">
                    <a href="signable_documents.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "signable_documents.php") { echo "active"; } ?>">
                        <i class="fas fa-file-signature nav-icon"></i>
                        <p>All Documents</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="signable_documents.php?status=Draft" class="nav-link">
                        <i class="fas fa-pencil-alt nav-icon"></i>
                        <p>Drafts</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="signable_documents.php?status=Sent" class="nav-link">
                        <i class="fas fa-paper-plane nav-icon"></i>
                        <p>Sent</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="signable_documents.php?status=Signed" class="nav-link">
                        <i class="fas fa-check-circle nav-icon"></i>
                        <p>Signed</p>
                    </a>
                </li>
                <!-- /ITFlow Document Signing Plugin -->
