<?php

/**
 * ITFlow Document Signing Plugin (Documenso edition) - Shared Functions
 *
 * PHP wrappers around the Documenso v2 (envelope-model) API, plus ITFlow-side
 * helpers (history, status badges, filing signed PDFs into client Files).
 *
 * All Documenso API behavior here was verified against a live Documenso
 * v2.13.0 instance. Key facts baked in:
 *   - /envelope/use is multipart/form-data with a single `payload` JSON part
 *   - distribution is REQUIRED to activate signing (distributeDocument alone
 *     leaves a DRAFT whose signing URLs 404)
 *   - /document/{id}/download takes the NUMERIC document id and streams raw PDF
 */

// ====================================================================
// Config loading
// ====================================================================

/**
 * Load the plugin's Documenso config. Returns an associative array of all
 * settings, or false if the config file is missing.
 */
function documensoLoadConfig() {
    // functions_documenso.php lives in includes/; config lives in agent/custom/
    $candidates = [
        __DIR__ . '/../agent/custom/documenso_config.php',
        $_SERVER['DOCUMENT_ROOT'] . '/agent/custom/documenso_config.php',
    ];
    $found = null;
    foreach ($candidates as $p) {
        if (file_exists($p)) { $found = $p; break; }
    }
    if (!$found) {
        return false;
    }

    // The config file sets a series of $documenso_* variables.
    include $found;

    return [
        'base_url'              => rtrim($documenso_base_url ?? '', '/'),
        'api_key'               => $documenso_api_key ?? '',
        'template_envelope_id'  => $documenso_template_envelope_id ?? '',
        'field_labels'          => $documenso_field_labels ?? [],
        'recipients'            => $documenso_recipients ?? [],
        'default_approver_name' => $documenso_default_approver_name ?? '',
        'default_approver_email'=> $documenso_default_approver_email ?? '',
        'signed_files_folder'   => $documenso_signed_files_folder ?? 'Signed Documents',
    ];
}

// ====================================================================
// Low-level HTTP helpers (cURL)
// ====================================================================

/**
 * Perform a JSON GET against Documenso. Returns [http_code, decoded_array|raw].
 */
function documensoApiGet($cfg, $path) {
    $url = $cfg['base_url'] . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $cfg['api_key']],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return [0, ['error' => $err]];
    }
    $decoded = json_decode($resp, true);
    return [$code, $decoded === null ? $resp : $decoded];
}

/**
 * Perform a JSON POST against Documenso. Returns [http_code, decoded_array|raw].
 */
function documensoApiPostJson($cfg, $path, $payload) {
    $url = $cfg['base_url'] . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $cfg['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return [0, ['error' => $err]];
    }
    $decoded = json_decode($resp, true);
    return [$code, $decoded === null ? $resp : $decoded];
}

/**
 * Perform the multipart/form-data POST that /envelope/use requires: a single
 * `payload` form field containing JSON. Returns [http_code, decoded_array|raw].
 */
function documensoApiPostEnvelopeUse($cfg, $payload) {
    $url = $cfg['base_url'] . '/envelope/use';
    // CURLFile is for files; the payload is a plain field whose body is JSON.
    $fields = ['payload' => json_encode($payload)];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $cfg['api_key']],
        // Passing an array makes cURL use multipart/form-data automatically.
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return [0, ['error' => $err]];
    }
    $decoded = json_decode($resp, true);
    return [$code, $decoded === null ? $resp : $decoded];
}

// ====================================================================
// Documenso operations (the proven 5-call sequence)
// ====================================================================

/**
 * Read the template and resolve our four prefill field LABELS to numeric ids.
 * Returns [labelKey => numericFieldId] or false on failure.
 */
function documensoResolveFieldIds($cfg) {
    // Template numeric id is 1 in our instance, but we read by the configured
    // template envelope where possible. The /template/{id} GET takes the
    // numeric id; we derive it from the envelope by listing, falling back to 1.
    // Simplest reliable path proven in testing: GET /template/1.
    list($code, $body) = documensoApiGet($cfg, '/template/1');
    if ($code !== 200 || !is_array($body)) {
        return false;
    }
    $wanted = $cfg['field_labels']; // e.g. ['business_name' => 'Business Name', ...]
    $byLabel = [];
    foreach (($body['fields'] ?? []) as $f) {
        $label = $f['fieldMeta']['label'] ?? null;
        if ($label !== null && $label !== '') {
            $byLabel[$label] = $f['id'];
        }
    }
    $out = [];
    foreach ($wanted as $key => $label) {
        if (!isset($byLabel[$label])) {
            return false; // a required labeled field is missing in the template
        }
        $out[$key] = $byLabel[$label];
    }
    return $out;
}

/**
 * Create an envelope from the template with prefilled fields and mapped
 * recipients. Returns [http_code, decoded] where decoded contains the new
 * envelope id and per-recipient signing URLs on success.
 *
 * @param array $values   ['business_name','business_address','monthly_rate','effective_date']
 * @param array $signer    ['name','email'] for the client signer
 * @param array $approver  ['name','email'] for the final approver
 */
function documensoCreateEnvelope($cfg, $fieldIds, $values, $signer, $approver) {
    $r = $cfg['recipients'];

    $payload = [
        'envelopeId' => $cfg['template_envelope_id'],
        'recipients' => [
            // Provider (you) keeps the template's own email — not overridden here.
            ['id' => $r['client_signer_id'], 'email' => $signer['email'],   'name' => $signer['name']],
            ['id' => $r['approver_id'],      'email' => $approver['email'], 'name' => $approver['name']],
        ],
        'distributeDocument' => false,
        'prefillFields' => [
            ['id' => $fieldIds['business_name'],    'type' => 'text',   'value' => $values['business_name']],
            ['id' => $fieldIds['business_address'], 'type' => 'text',   'value' => $values['business_address']],
            ['id' => $fieldIds['monthly_rate'],     'type' => 'number', 'value' => $values['monthly_rate']],
            ['id' => $fieldIds['effective_date'],   'type' => 'text',   'value' => $values['effective_date']],
        ],
        'override' => ['distributionMethod' => 'NONE'],
    ];

    return documensoApiPostEnvelopeUse($cfg, $payload);
}

/**
 * Distribute an envelope (method NONE) to activate signing without email.
 * REQUIRED — without this, signing URLs 404. Returns [http_code, decoded].
 */
function documensoDistribute($cfg, $envelopeId) {
    return documensoApiPostJson($cfg, '/envelope/distribute', [
        'envelopeId' => $envelopeId,
        'meta' => ['distributionMethod' => 'NONE'],
    ]);
}

/**
 * Fetch an envelope's current state. Returns [http_code, decoded].
 */
function documensoGetEnvelope($cfg, $envelopeId) {
    return documensoApiGet($cfg, '/envelope/' . rawurlencode($envelopeId));
}

/**
 * Download the sealed PDF for a completed document by its NUMERIC id.
 * Returns [http_code, raw_pdf_bytes] — the body is raw PDF, not JSON.
 */
function documensoDownloadSignedPdf($cfg, $numericDocumentId) {
    $url = $cfg['base_url'] . '/document/' . intval($numericDocumentId) . '/download';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $cfg['api_key']],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

/**
 * Derive the numeric document id from an envelope's secondaryId ("document_3" -> 3).
 */
function documensoNumericIdFromEnvelope($envelope) {
    $sid = $envelope['secondaryId'] ?? '';
    if (preg_match('/(\d+)$/', $sid, $m)) {
        return intval($m[1]);
    }
    return null;
}

// ====================================================================
// ITFlow-side helpers
// ====================================================================

/**
 * Status badge HTML for a Documenso-mirrored status.
 */
function documensoStatusBadge($status) {
    $badges = [
        'Draft'     => 'badge-secondary',
        'Pending'   => 'badge-warning',
        'Completed' => 'badge-success',
        'Cancelled' => 'badge-danger',
        'Error'     => 'badge-dark',
    ];
    $class = $badges[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

/**
 * Record a history entry for a documenso document.
 */
function documensoAddHistory($mysqli, $document_id, $status, $description, $ip = null) {
    $document_id = intval($document_id);
    $status = mysqli_real_escape_string($mysqli, $status);
    $description = mysqli_real_escape_string($mysqli, $description);
    $ip = $ip ? "'" . mysqli_real_escape_string($mysqli, $ip) . "'" : "NULL";
    mysqli_query($mysqli, "INSERT INTO documenso_document_history
        SET documenso_history_status = '$status',
            documenso_history_description = '$description',
            documenso_history_ip = $ip,
            documenso_history_document_id = $document_id");
}
