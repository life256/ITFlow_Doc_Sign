<?php

/**
 * ITFlow Document Signing Plugin (Documenso edition) - Configuration
 *
 * Copy this file to documenso_config.php and fill in real values.
 * documenso_config.php is git-ignored and must NEVER be committed.
 *
 *   cp documenso_config.example.php documenso_config.php
 *   chmod 640 documenso_config.php && chown root:www-data documenso_config.php
 *
 * Document types are defined in $documenso_templates below. Adding a
 * no-prefill template is pure config: copy an entry, set its key/label/
 * template_envelope_id, set prefill_profile => 'none', empty field_labels,
 * and fill the three recipient placeholder ids from that Documenso template.
 */

// --- Documenso connection ---
$documenso_base_url = 'https://sign.example.com/api/v2';
$documenso_api_key  = 'api_xxxxxxxxxxxxxxxx';

// --- Default approver (used when no per-send override is given) ---
$documenso_default_approver_name  = 'Your Name';
$documenso_default_approver_email = 'you@example.com';

// --- Where signed PDFs are filed inside the client's ITFlow Files ---
$documenso_signed_files_folder = 'Signed Documents';

// --- Default template pre-selected in the Send dropdown ---
$documenso_default_template_key = 'msa';

/**
 * --- Document templates (selectable in the Send dropdown) ---
 *
 * prefill_profile: one of
 *   'none'         - no field prefill (just maps the client signer)
 *   'client_basic' - prefills business name + address
 *   'msa_full'     - business name + address + monthly rate + effective date
 *                    (rate/date pulled from the client's latest invoice;
 *                     address from the client's primary location)
 *
 * field_labels: maps each profile field to the Documenso field LABEL on
 *   that template (we target by label so template edits that renumber the
 *   underlying field ids don't break anything). Empty for 'none'.
 *
 * recipients: standard provider -> client -> approver flow. The ids are the
 *   placeholder recipient ids on that specific Documenso template.
 */
$documenso_templates = [

    'msa' => [
        'key'                  => 'msa',
        'label'                => 'Master Service Agreement',
        'template_envelope_id' => 'envelope_xxxxxxxxxxxxxxxx',
        'template_numeric_id'  => 1,   // the template's numeric id (GET /template/{n})
        'prefill_profile'      => 'msa_full',
        'field_labels'         => [
            'business_name'    => 'Business Name',
            'business_address' => 'Business Address',
            'monthly_rate'     => 'Monthly Rate',
            'effective_date'   => 'Effective Date',
        ],
        'field_types'          => [
            'monthly_rate'     => 'number',  // others default to 'text'
        ],
        'recipients' => [
            'provider_id'      => 2,   // you (signs first)
            'client_signer_id' => 29,  // client primary contact (signs second)
            'approver_id'      => 30,  // you again (final approver)
        ],
    ],

    // --- Example: a simple no-prefill document. Uncomment + edit to enable. ---
    // 'nda' => [
    //     'key'                  => 'nda',
    //     'label'                => 'Non-Disclosure Agreement',
    //     'template_envelope_id' => 'envelope_REPLACE_ME',
    //     'prefill_profile'      => 'none',
    //     'field_labels'         => [],
    //     'recipients' => [
    //         'provider_id'      => 2,
    //         'client_signer_id' => 5,
    //         'approver_id'      => 6,
    //     ],
    // ],

];
