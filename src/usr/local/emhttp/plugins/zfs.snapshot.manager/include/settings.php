<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib.php';

function settings_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    settings_respond(405, ['ok' => false, 'message' => 'Nur POST-Anfragen sind erlaubt.']);
}

$enabled = $_POST['enabled'] ?? '';
if (!is_string($enabled) || !in_array($enabled, ['yes', 'no'], true)) {
    settings_respond(400, ['ok' => false, 'message' => 'Ungültige Einstellung.']);
}

if (!zsm_save_enabled($enabled === 'yes')) {
    settings_respond(500, ['ok' => false, 'message' => 'Die Einstellung konnte nicht gespeichert werden.']);
}

$cronResult = zsm_apply_cron();
if ($cronResult['exitCode'] !== 0) {
    settings_respond(500, [
        'ok' => false,
        'message' => 'Die Einstellung wurde gespeichert, aber Unraid Cron konnte nicht aktualisiert werden: ' . $cronResult['stderr'],
    ]);
}

$sharesLock = zsm_lock_shares(true);
$sharesResult = $sharesLock !== null
    ? zsm_apply_shares()
    : ['exitCode' => 75, 'stderr' => 'Die Freigaben-Sperre konnte nicht gesetzt werden.'];
if ($sharesResult['exitCode'] !== 0) {
    settings_respond(500, [
        'ok' => false,
        'message' => 'Die Einstellung wurde gespeichert, aber die Snapshot-Freigaben konnten nicht aktualisiert werden: ' . $sharesResult['stderr'],
    ]);
}

openlog('zfs.snapshot.manager', LOG_PID, LOG_USER);
syslog(LOG_NOTICE, 'setting enabled=' . $enabled);
closelog();

settings_respond(200, [
    'ok' => true,
    'enabled' => $enabled === 'yes',
    'message' => $enabled === 'yes'
        ? 'ZFS Snapshot Manager wurde aktiviert.'
        : 'ZFS Snapshot Manager wurde deaktiviert. Vorhandene Snapshots wurden nicht verändert.',
]);
