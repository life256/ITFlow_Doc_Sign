--
-- ITFlow Document Signing Plugin (Documenso edition) - Database Schema
-- Run this SQL against your ITFlow database to add the signing tables.
--
-- This edition integrates with a self-hosted Documenso instance via its API.
-- Signatures, sealing, and the signed PDF are owned by Documenso; ITFlow
-- stores a lightweight record that maps each ITFlow client to a Documenso
-- envelope, tracks status, and remembers where the signed PDF was filed.
--

-- ------------------------------------------------------------------
-- Signing requests: one row per document sent through Documenso.
-- Maps an ITFlow client/contact to a Documenso envelope + document.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `documenso_documents` (
    `documenso_document_id` int(11) NOT NULL AUTO_INCREMENT,
    `documenso_document_title` varchar(255) NOT NULL,
    `documenso_document_type` varchar(100) NOT NULL DEFAULT 'msa' COMMENT 'msa, contract, custom, etc.',

    -- Documenso identifiers (returned by the API)
    `documenso_document_envelope_id` varchar(191) DEFAULT NULL COMMENT 'Documenso envelope string id, e.g. envelope_xxx',
    `documenso_document_numeric_id` int(11) DEFAULT NULL COMMENT 'Numeric document id used for /document/{id}/download',
    `documenso_document_template_envelope_id` varchar(191) DEFAULT NULL COMMENT 'Template envelope this was created from',

    -- Status mirrors Documenso: Draft, Pending, Completed, Cancelled (plus local Error)
    `documenso_document_status` varchar(50) NOT NULL DEFAULT 'Draft',

    -- Prefilled values that were sent (kept for audit / display; source of truth is Documenso)
    `documenso_document_business_name` varchar(255) DEFAULT NULL,
    `documenso_document_business_address` varchar(500) DEFAULT NULL,
    `documenso_document_monthly_rate` varchar(50) DEFAULT NULL,
    `documenso_document_effective_date` varchar(100) DEFAULT NULL,

    -- Where the signed PDF landed in ITFlow's Files section (set on completion)
    `documenso_document_filed_file_id` int(11) DEFAULT NULL COMMENT 'clients files row id once signed PDF is filed',

    -- Timestamps
    `documenso_document_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `documenso_document_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    `documenso_document_sent_at` datetime DEFAULT NULL,
    `documenso_document_completed_at` datetime DEFAULT NULL,
    `documenso_document_archived_at` datetime DEFAULT NULL,

    -- Links to ITFlow core
    `documenso_document_created_by` int(11) NOT NULL DEFAULT 0,
    `documenso_document_client_id` int(11) NOT NULL,
    `documenso_document_contact_id` int(11) NOT NULL DEFAULT 0 COMMENT 'Primary contact used as client signer',

    PRIMARY KEY (`documenso_document_id`),
    KEY `documenso_document_client_id` (`documenso_document_client_id`),
    KEY `documenso_document_envelope_id` (`documenso_document_envelope_id`),
    KEY `documenso_document_status` (`documenso_document_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------------
-- Per-recipient tracking: who the envelope went to and their signing URL.
-- One row per Documenso recipient (you, client signer, approver).
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `documenso_document_recipients` (
    `documenso_recipient_id` int(11) NOT NULL AUTO_INCREMENT,
    `documenso_recipient_documenso_id` int(11) DEFAULT NULL COMMENT 'Documenso per-envelope recipient id',
    `documenso_recipient_role` varchar(50) DEFAULT NULL COMMENT 'SIGNER, APPROVER, etc.',
    `documenso_recipient_name` varchar(255) DEFAULT NULL,
    `documenso_recipient_email` varchar(255) DEFAULT NULL,
    `documenso_recipient_signing_order` int(11) DEFAULT NULL,
    `documenso_recipient_signing_url` varchar(500) DEFAULT NULL,
    `documenso_recipient_signing_status` varchar(50) DEFAULT NULL COMMENT 'NOT_SIGNED, SIGNED, etc.',
    `documenso_recipient_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `documenso_recipient_document_id` int(11) NOT NULL,
    PRIMARY KEY (`documenso_recipient_id`),
    KEY `documenso_recipient_document_id` (`documenso_recipient_document_id`),
    CONSTRAINT `documenso_recipient_fk` FOREIGN KEY (`documenso_recipient_document_id`) REFERENCES `documenso_documents` (`documenso_document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------------
-- Audit log for signing events (Created, Sent, Status changes, Filed, etc.)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `documenso_document_history` (
    `documenso_history_id` int(11) NOT NULL AUTO_INCREMENT,
    `documenso_history_status` varchar(200) NOT NULL,
    `documenso_history_description` varchar(255) NOT NULL,
    `documenso_history_ip` varchar(100) DEFAULT NULL,
    `documenso_history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `documenso_history_document_id` int(11) NOT NULL,
    PRIMARY KEY (`documenso_history_id`),
    KEY `documenso_history_document_id` (`documenso_history_document_id`),
    CONSTRAINT `documenso_doc_hist_fk` FOREIGN KEY (`documenso_history_document_id`) REFERENCES `documenso_documents` (`documenso_document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
