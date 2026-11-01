<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib.php';

function share_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    share_respond(405, ['ok' => false, 'message' => 'Nur POST-Anfragen sind erlaubt.']);
}
if (!zsm_is_enabled()) {
    share_respond(403, ['ok' => false, 'message' => 'Der ZFS Snapshot Manager ist deaktiviert.']);
}

$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, ['save', 'delete'], true)) {
    share_respond(400, ['ok' => false, 'message' => 'Unbekannte Freigabe-Aktion.']);
}

$lock = zsm_lock_shares(false);
if ($lock === null) {
    share_respond(409, ['ok' => false, 'message' => 'Eine andere Freigabe-Aktion läuft bereits.']);
}

$previousShares = zsm_load_shares();
$shares = $previousShares;
$id = $_POST['id'] ?? '';
if (!is_string($id) || ($id !== '' && !zsm_is_valid_policy_id($id))) {
    share_respond(400, ['ok' => false, 'message' => 'Ungültige Freigabe-ID.']);
}
$existingIndex = null;
foreach ($shares as $index => $share) {
    if ($share['id'] === $id) {
        $existingIndex = $index;
        break;
    }
}
if ($id !== '' && $existingIndex === null) {
    share_respond(404, ['ok' => false, 'message' => 'Die Freigabe wurde nicht gefunden.']);
}

if ($action === 'delete') {
    if ($existingIndex === null) {
        share_respond(400, ['ok' => false, 'message' => 'Ungültige Freigabe-ID.']);
    }
    array_splice($shares, $existingIndex, 1);
    $message = 'Die Freigabe wurde entfernt. Snapshots und Daten bleiben erhalten.';
} else {
    $guid = $_POST['dataset_guid'] ?? '';
    $name = $_POST['name'] ?? '';
    $users = $_POST['users'] ?? [];
    if (!is_string($guid) || !zsm_is_valid_dataset_guid($guid)) {
        share_respond(400, ['ok' => false, 'message' => 'Bitte ein Dataset auswählen.']);
    }
    if (!is_string($name) || !zsm_is_valid_share_name($name)) {
        share_respond(400, ['ok' => false, 'message' => 'Der Freigabename darf nur Buchstaben, Ziffern, - und _ enthalten.']);
    }
    if (!is_array($users) || $users === []) {
        share_respond(400, ['ok' => false, 'message' => 'Mindestens ein Benutzer muss ausgewählt werden.']);
    }
    $knownUsers = zsm_load_unraid_users();
    foreach ($users as $user) {
        if (!is_string($user) || !in_array($user, $knownUsers, true)) {
            share_respond(400, ['ok' => false, 'message' => 'Ausgewählt werden können nur Unraid-Benutzer mit Passwort.']);
        }
    }

    $loaded = zsm_load_filesystems();
    if (!$loaded['ok']) {
        share_respond(409, ['ok' => false, 'message' => $loaded['error']]);
    }
    $filesystem = array_column($loaded['filesystems'], null, 'guid')[$guid] ?? null;
    if ($filesystem === null) {
        share_respond(409, ['ok' => false, 'message' => 'Das ausgewählte Dataset existiert nicht mehr.']);
    }

    $reservedNames = zsm_reserved_share_names();
    if ($existingIndex === null || strcasecmp($shares[$existingIndex]['name'], $name) !== 0) {
        if (in_array(strtolower($name), $reservedNames, true)) {
            share_respond(409, ['ok' => false, 'message' => 'Dieser Freigabename wird bereits von Unraid verwendet.']);
        }
    }
    foreach ($shares as $index => $share) {
        if ($index === $existingIndex) {
            continue;
        }
        if (strcasecmp($share['name'], $name) === 0) {
            share_respond(409, ['ok' => false, 'message' => 'Dieser Freigabename ist bereits vergeben.']);
        }
        if ($share['dataset_guid'] === $guid) {
            share_respond(409, ['ok' => false, 'message' => 'Für dieses Dataset besteht bereits eine Snapshot-Freigabe.']);
        }
    }

    $share = zsm_normalize_share([
        'id' => $id !== '' ? $id : zsm_generate_policy_id(),
        'dataset_guid' => $guid,
        'dataset' => $filesystem['name'],
        'name' => $name,
        'users' => array_values($users),
    ]);
    if ($share === null) {
        share_respond(400, ['ok' => false, 'message' => 'Die Freigabe-Angaben sind ungültig.']);
    }
    if ($existingIndex === null) {
        $shares[] = $share;
    } else {
        $shares[$existingIndex] = $share;
    }
    $message = 'Die Snapshot-Freigabe wurde gespeichert.';
}

if (!zsm_save_shares($shares)) {
    share_respond(500, ['ok' => false, 'message' => 'Die Freigabe konnte nicht gespeichert werden.']);
}
$result = zsm_apply_shares();
if ($result['exitCode'] !== 0) {
    zsm_save_shares($previousShares);
    share_respond(500, ['ok' => false, 'message' => 'Samba wurde nicht aktualisiert: ' . ($result['stderr'] ?: 'Unbekannter Fehler.')]);
}

share_respond(200, ['ok' => true, 'message' => $message]);
