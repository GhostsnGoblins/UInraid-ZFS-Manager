<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib.php';

function policy_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    policy_respond(405, ['ok' => false, 'message' => 'Nur POST-Anfragen sind erlaubt.']);
}
if (!zsm_is_enabled()) {
    policy_respond(403, ['ok' => false, 'message' => 'Der ZFS Snapshot Manager ist deaktiviert.']);
}

$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, ['save', 'delete', 'run', 'preview'], true)) {
    policy_respond(400, ['ok' => false, 'message' => 'Unbekannte Zeitplan-Aktion.']);
}

$lock = @fopen('/var/run/zfs.snapshot.manager.policies.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    policy_respond(409, ['ok' => false, 'message' => 'Eine andere Zeitplan-Aktion läuft bereits.']);
}

$policies = zsm_load_policies();

if ($action === 'save') {
    $requestedId = $_POST['id'] ?? '';
    if (!is_string($requestedId) || ($requestedId !== '' && !zsm_is_valid_policy_id($requestedId))) {
        policy_respond(400, ['ok' => false, 'message' => 'Ungültige Zeitplan-ID.']);
    }
    if ($requestedId !== '' && zsm_find_policy($policies, $requestedId) === null) {
        policy_respond(404, ['ok' => false, 'message' => 'Der zu bearbeitende Zeitplan wurde nicht gefunden.']);
    }
    $existingPolicy = $requestedId !== '' ? zsm_find_policy($policies, $requestedId) : null;

    $candidate = [
        'id' => $requestedId !== '' ? $requestedId : zsm_generate_policy_id(),
        'dataset' => $_POST['dataset'] ?? '',
        'frequency' => $_POST['frequency'] ?? '',
        'minute' => $_POST['minute'] ?? null,
        'hour' => $_POST['hour'] ?? null,
        'weekday' => $_POST['weekday'] ?? null,
        'retention_value' => $_POST['retention_value'] ?? null,
        'retention_unit' => $_POST['retention_unit'] ?? '',
    ];
    $policy = zsm_normalize_policy($candidate);
    if ($policy === null) {
        policy_respond(400, ['ok' => false, 'message' => 'Die Zeitplan-Angaben sind ungültig.']);
    }
    if ($existingPolicy !== null && $existingPolicy['dataset'] !== $policy['dataset']) {
        policy_respond(400, ['ok' => false, 'message' => 'Das Dataset einer bestehenden Regel kann nicht geändert werden.']);
    }

    $datasetsResult = zsm_run(['list-datasets']);
    if ($datasetsResult['exitCode'] !== 0) {
        policy_respond(409, ['ok' => false, 'message' => $datasetsResult['stderr'] ?: 'Datasets konnten nicht geprüft werden.']);
    }
    $datasetExists = false;
    foreach (zsm_parse_tsv($datasetsResult['stdout'], ['name', 'type', 'used', 'available', 'referenced', 'mountpoint']) as $dataset) {
        if ($dataset['name'] === $policy['dataset']) {
            $datasetExists = true;
            break;
        }
    }
    if (!$datasetExists) {
        policy_respond(409, ['ok' => false, 'message' => 'Das ausgewählte Dataset existiert nicht mehr.']);
    }

    foreach ($policies as $existingPolicy) {
        if ($existingPolicy['dataset'] === $policy['dataset'] && $existingPolicy['id'] !== $policy['id']) {
            policy_respond(409, ['ok' => false, 'message' => 'Für dieses Dataset existiert bereits ein Zeitplan. Lösche ihn zuerst.']);
        }
    }

    $updated = false;
    foreach ($policies as $index => $existingPolicy) {
        if ($existingPolicy['id'] === $policy['id']) {
            $policies[$index] = $policy;
            $updated = true;
            break;
        }
    }
    if (!$updated) {
        $policies[] = $policy;
    }
    if (!zsm_save_policies($policies)) {
        policy_respond(500, ['ok' => false, 'message' => 'Der Zeitplan konnte nicht gespeichert werden.']);
    }
    $cronResult = zsm_apply_cron();
    if ($cronResult['exitCode'] !== 0) {
        policy_respond(500, ['ok' => false, 'message' => 'Der Zeitplan wurde gespeichert, aber Unraid Cron konnte nicht aktualisiert werden: ' . $cronResult['stderr']]);
    }

    policy_respond(200, [
        'ok' => true,
        'message' => $updated ? 'Der Zeitplan wurde aktualisiert.' : 'Der Zeitplan wurde gespeichert.',
        'id' => $policy['id'],
    ]);
}

$id = $_POST['id'] ?? '';
if (!is_string($id) || !zsm_is_valid_policy_id($id)) {
    policy_respond(400, ['ok' => false, 'message' => 'Ungültige Zeitplan-ID.']);
}
$policy = zsm_find_policy($policies, $id);
if ($policy === null) {
    policy_respond(404, ['ok' => false, 'message' => 'Der Zeitplan wurde nicht gefunden.']);
}

if ($action === 'delete') {
    $policies = array_values(array_filter($policies, static fn(array $item): bool => $item['id'] !== $id));
    if (!zsm_save_policies($policies)) {
        policy_respond(500, ['ok' => false, 'message' => 'Der Zeitplan konnte nicht gelöscht werden.']);
    }
    $cronResult = zsm_apply_cron();
    if ($cronResult['exitCode'] !== 0) {
        policy_respond(500, ['ok' => false, 'message' => 'Der Zeitplan wurde gelöscht, aber Unraid Cron konnte nicht aktualisiert werden: ' . $cronResult['stderr']]);
    }
    policy_respond(200, ['ok' => true, 'message' => 'Der Zeitplan wurde gelöscht. Bereits vorhandene Snapshots bleiben erhalten.']);
}

$retentionSeconds = (string) zsm_policy_retention_seconds($policy);
if ($action === 'preview') {
    $result = zsm_run(['preview-policy', $policy['id'], $policy['dataset'], $retentionSeconds], 60);
    if ($result['exitCode'] !== 0) {
        policy_respond(409, ['ok' => false, 'message' => $result['stderr'] ?: 'Die Löschvorschau konnte nicht erstellt werden.']);
    }
    $candidates = $result['stdout'] === '' ? [] : preg_split('/\r?\n/', $result['stdout']);
    policy_respond(200, [
        'ok' => true,
        'message' => $candidates === []
            ? 'Aktuell ist kein automatisch verwalteter Snapshot abgelaufen.'
            : count($candidates) . ' Snapshot(s) wären aktuell zur Löschung fällig.',
        'candidates' => $candidates,
    ]);
}

$result = zsm_run(['run-policy', $policy['id'], $policy['dataset'], $retentionSeconds], 120);
if ($result['exitCode'] !== 0) {
    policy_respond(409, ['ok' => false, 'message' => $result['stderr'] ?: 'Der Zeitplan konnte nicht ausgeführt werden.']);
}
policy_respond(200, ['ok' => true, 'message' => 'Automatischer Snapshot wurde erstellt.', 'snapshot' => strtok($result['stdout'], "\r\n")]);
