--
-- ITFlow Document Signing Plugin - Database Schema
-- Run this SQL against your ITFlow database to add document signing tables
--

-- Signable documents (quotes, MSAs, contracts, etc.)
CREATE TABLE IF NOT EXISTS `signable_documents` (
    `signable_document_id` int(11) NOT NULL AUTO_INCREMENT,
    `signable_document_title` varchar(255) NOT NULL,
    `signable_document_description` text DEFAULT NULL,
    `signable_document_type` varchar(100) NOT NULL DEFAULT 'custom' COMMENT 'quote, msa, contract, custom',
    `signable_document_content` longtext DEFAULT NULL COMMENT 'HTML content of the document',
    `signable_document_file_name` varchar(255) DEFAULT NULL COMMENT 'Uploaded PDF file name',
    `signable_document_status` varchar(50) NOT NULL DEFAULT 'Draft' COMMENT 'Draft, Sent, Viewed, Signed, Declined, Expired',
    `signable_document_url_key` varchar(64) DEFAULT NULL,
    `signable_document_date` date NOT NULL,
    `signable_document_expire` date DEFAULT NULL,
    `signable_document_note` text DEFAULT NULL,
    `signable_document_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `signable_document_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    `signable_document_archived_at` datetime DEFAULT NULL,
    `signable_document_created_by` int(11) NOT NULL DEFAULT 0,
    `signable_document_client_id` int(11) NOT NULL,
    `signable_document_contact_id` int(11) NOT NULL DEFAULT 0 COMMENT 'Specific contact to sign',
    `signable_document_quote_id` int(11) NOT NULL DEFAULT 0 COMMENT 'Link to quote if type=quote',
    PRIMARY KEY (`signable_document_id`),
    KEY `signable_document_client_id` (`signable_document_client_id`),
    KEY `signable_document_quote_id` (`signable_document_quote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Signatures collected
CREATE TABLE IF NOT EXISTS `signable_document_signatures` (
    `signature_id` int(11) NOT NULL AUTO_INCREMENT,
    `signature_signer_name` varchar(255) NOT NULL,
    `signature_signer_email` varchar(255) NOT NULL,
    `signature_signer_ip` varchar(100) DEFAULT NULL,
    `signature_signer_user_agent` varchar(500) DEFAULT NULL,
    `signature_data` longtext NOT NULL COMMENT 'Base64 encoded signature image',
    `signature_hash` varchar(64) NOT NULL COMMENT 'SHA-256 hash of signature + document for integrity',
    `signature_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `signature_signable_document_id` int(11) NOT NULL,
    PRIMARY KEY (`signature_id`),
    KEY `signature_signable_document_id` (`signature_signable_document_id`),
    CONSTRAINT `signable_doc_sig_fk` FOREIGN KEY (`signature_signable_document_id`) REFERENCES `signable_documents` (`signable_document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Audit log for document signing events
CREATE TABLE IF NOT EXISTS `signable_document_history` (
    `signable_history_id` int(11) NOT NULL AUTO_INCREMENT,
    `signable_history_status` varchar(200) NOT NULL,
    `signable_history_description` varchar(255) NOT NULL,
    `signable_history_ip` varchar(100) DEFAULT NULL,
    `signable_history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `signable_history_signable_document_id` int(11) NOT NULL,
    PRIMARY KEY (`signable_history_id`),
    KEY `signable_history_signable_document_id` (`signable_history_signable_document_id`),
    CONSTRAINT `signable_doc_hist_fk` FOREIGN KEY (`signable_history_signable_document_id`) REFERENCES `signable_documents` (`signable_document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add sidebar link via ITFlow's custom_links table (if it exists)
-- This adds a "Signable Documents" link to the agent sidebar
INSERT INTO `custom_links` (`custom_link_name`, `custom_link_url`, `custom_link_icon`, `custom_link_target`)
SELECT 'Signable Documents', '/agent/signable_documents.php', 'fas fa-file-signature', '_self'
FROM dual
WHERE NOT EXISTS (
    SELECT 1 FROM `custom_links` WHERE `custom_link_url` = '/agent/signable_documents.php'
);
