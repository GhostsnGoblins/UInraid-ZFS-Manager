<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib.php';

function scrub_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    scrub_respond(405, ['ok' => false, 'message' => 'Nur POST-Anfragen sind erlaubt.']);
}
if (!zsm_is_enabled()) {
    scrub_respond(403, ['ok' => false, 'message' => 'Der ZFS Snapshot Manager ist deaktiviert.']);
}

$action = $_POST['action'] ?? '';
$guid = $_POST['guid'] ?? '';
if (!is_string($action) || !in_array($action, ['toggle', 'start', 'stop'], true)) {
    scrub_respond(400, ['ok' => false, 'message' => 'Unbekannte Scrub-Aktion.']);
}
if (!is_string($guid) || !zsm_is_valid_pool_guid($guid)) {
    scrub_respond(400, ['ok' => false, 'message' => 'Ungültige Pool-GUID.']);
}

$lock = @fopen('/var/run/zfs.snapshot.manager.scrubs.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    scrub_respond(409, ['ok' => false, 'message' => 'Eine andere Scrub-Aktion läuft bereits.']);
}

$poolResult = zsm_run(['list-pools']);
if ($poolResult['exitCode'] !== 0) {
    scrub_respond(409, ['ok' => false, 'message' => $poolResult['stderr'] ?: 'Die ZFS-Pools konnten nicht geprüft werden.']);
}
$pools = zsm_parse_tsv($poolResult['stdout'], ['guid', 'name', 'health', 'scan_type', 'scan_state', 'scan_started', 'scan_completed', 'repaired', 'errors', 'progress', 'eta', 'summary']);
$pool = array_column($pools, null, 'guid')[$guid] ?? null;
if ($pool === null) {
    scrub_respond(404, ['ok' => false, 'message' => 'Der ausgewählte ZFS-Pool wurde nicht gefunden.']);
}

if ($action === 'toggle') {
    $enabled = $_POST['enabled'] ?? '';
    if (!is_string($enabled) || !in_array($enabled, ['0', '1'], true)) {
        scrub_respond(400, ['ok' => false, 'message' => 'Ungültige Auto-Scrub-Einstellung.']);
    }
    $previous = zsm_load_auto_scrub_guids();
    $updated = array_values(array_filter($previous, static fn(string $item): bool => $item !== $guid));
    if ($enabled === '1') {
        $updated[] = $guid;
    }
    if (!zsm_save_auto_scrub_guids($updated)) {
        scrub_respond(500, ['ok' => false, 'message' => 'Die Auto-Scrub-Einstellung konnte nicht gespeichert werden.']);
    }
    $cronResult = zsm_apply_cron();
    if ($cronResult['exitCode'] !== 0) {
        zsm_save_auto_scrub_guids($previous);
        zsm_apply_cron();
        scrub_respond(500, ['ok' => false, 'message' => 'Die Einstellung wurde zurückgesetzt, weil Unraid Cron nicht aktualisiert werden konnte: ' . $cronResult['stderr']]);
    }
    scrub_respond(200, [
        'ok' => true,
        'enabled' => $enabled === '1',
        'message' => $enabled === '1'
            ? 'Der monatliche Auto-Scrub wurde für ' . $pool['name'] . ' aktiviert.'
            : 'Der monatliche Auto-Scrub wurde für ' . $pool['name'] . ' deaktiviert.',
    ]);
}

$arguments = $action === 'start'
    ? ['start-scrub', $guid, 'manual']
    : ['stop-scrub', $guid];
$result = zsm_run($arguments, 30);
if ($result['exitCode'] !== 0) {
    scrub_respond(409, ['ok' => false, 'message' => $result['stderr'] ?: 'Die Scrub-Aktion ist fehlgeschlagen.']);
}

scrub_respond(200, [
    'ok' => true,
    'message' => $action === 'start'
        ? 'Der Scrub wurde für ' . $pool['name'] . ' gestartet.'
        : 'Der Scrub wurde für ' . $pool['name'] . ' abgebrochen.',
]);
