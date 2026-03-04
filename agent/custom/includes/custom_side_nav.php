<?php
/**
 * ITFlow Document Signing Plugin - Custom Sidebar Navigation
 *
 * This file is automatically included by ITFlow's agent sidebar
 * when placed in agent/custom/includes/custom_side_nav.php
 *
 * It adds a "Signable Documents" link under the Billing section.
 */
?>

<li class="nav-item">
    <a href="signable_documents.php" class="nav-link <?php if (basename($_SERVER['PHP_SELF']) === 'signable_documents.php' || basename($_SERVER['PHP_SELF']) === 'signable_document.php') echo 'active'; ?>">
        <i class="nav-icon fas fa-file-signature"></i>
        <p>Signable Documents</p>
    </a>
</li>
