<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib.php';

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Nur POST-Anfragen sind erlaubt.']);
}

if (!zsm_is_enabled()) {
    respond(403, ['ok' => false, 'message' => 'Der ZFS Snapshot Manager ist deaktiviert.']);
}

$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, ['create', 'delete'], true)) {
    respond(400, ['ok' => false, 'message' => 'Unbekannte Aktion.']);
}

if ($action === 'create') {
    $dataset = $_POST['dataset'] ?? '';
    if (!is_string($dataset) || strlen($dataset) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*(\/[A-Za-z0-9][A-Za-z0-9_.:-]*)*$/D', $dataset)) {
        respond(400, ['ok' => false, 'message' => 'Ungültiger Dataset-Name.']);
    }

    $result = zsm_run(['create', $dataset], 30);
    if ($result['exitCode'] !== 0) {
        $message = $result['stderr'] !== '' ? $result['stderr'] : 'Der Snapshot konnte nicht erstellt werden.';
        respond(409, ['ok' => false, 'message' => $message]);
    }

    respond(200, [
        'ok' => true,
        'message' => 'Snapshot wurde erstellt.',
        'snapshot' => $result['stdout'],
    ]);
}

$snapshot = $_POST['snapshot'] ?? '';
if (!is_string($snapshot) || strlen($snapshot) > 320 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*(\/[A-Za-z0-9][A-Za-z0-9_.:-]*)*@uzm_manual_[0-9]{8}T[0-9]{6}Z$/D', $snapshot)) {
    respond(400, ['ok' => false, 'message' => 'Nur eindeutig benannte Plugin-Snapshots dürfen gelöscht werden.']);
}

$result = zsm_run(['delete', $snapshot], 30);
if ($result['exitCode'] !== 0) {
    $message = $result['stderr'] !== '' ? $result['stderr'] : 'Der Snapshot konnte nicht gelöscht werden.';
    respond(409, ['ok' => false, 'message' => $message]);
}

respond(200, [
    'ok' => true,
    'message' => 'Snapshot wurde gelöscht.',
    'snapshot' => $result['stdout'],
]);


