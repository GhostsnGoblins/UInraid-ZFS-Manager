<?php

declare(strict_types=1);

require __DIR__ . '/../src/usr/local/emhttp/plugins/zfs.snapshot.manager/include/lib.php';

function check(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, 'Fehlgeschlagen: ' . $label . PHP_EOL);
        exit(1);
    }
}

$id = str_repeat('a', 32);
$share = ['id' => $id, 'dataset_guid' => '123', 'dataset' => 'alt/name', 'name' => 'data-snapshots', 'users' => ['sonja', 'claudio', 'claudio']];
$filesystem = ['guid' => '123', 'name' => 'disk1/data', 'mountpoint' => '/mnt/disk1/data', 'mounted' => 'yes'];

check(zsm_normalize_share($share)['users'] === ['claudio', 'sonja'], 'Benutzer werden bereinigt');
check(zsm_normalize_share(array_merge($share, ['users' => []])) === null, 'Freigabe ohne Benutzer wird abgelehnt');
check(zsm_normalize_share(array_merge($share, ['users' => ['root']])) === null, 'root wird abgelehnt');
check(zsm_normalize_share(array_merge($share, ['users' => ["a\nb"]])) === null, 'Zeilenumbruch im Benutzer wird abgelehnt');
check(zsm_normalize_share(array_merge($share, ['users' => ['%U']])) === null, 'Samba-Makro im Benutzer wird abgelehnt');
check(zsm_normalize_share(array_merge($share, ['name' => "x]\n[y"])) === null, 'Freigabename kann keine Sektion einschleusen');

check(zsm_is_safe_mountpoint('/mnt/disk1/data'), 'normaler Mountpoint');
foreach (['legacy', 'none', '-', '/', '/mnt/a b', "/mnt/a\nb", '/mnt/%U', '/mnt/../etc'] as $unsafe) {
    check(!zsm_is_safe_mountpoint($unsafe), 'unsicherer Mountpoint: ' . $unsafe);
}

$normalized = zsm_normalize_share($share);
$active = zsm_resolve_shares([$normalized], [$filesystem], ['claudio'])[0];
check($active['problem'] === '' && $active['dataset'] === 'disk1/data', 'GUID bestimmt das aktuelle Dataset');
check($active['path'] === '/mnt/disk1/data/.zfs/snapshot', 'Pfad zeigt auf den Snapshot-Ordner');
check($active['users'] === ['claudio'], 'nur vorhandene Benutzer bleiben');

$config = zsm_render_samba_config([$active]);
$expectedLines = [
    "[data-snapshots]\n",
    "path = /mnt/disk1/data/.zfs/snapshot\n",
    "valid users = claudio\n",
    "read only = yes\n",
    "guest ok = no\n",
    "wide links = no\n",
    "write list =\n",
    "vfs objects =\n",
];
foreach ($expectedLines as $expected) {
    check(str_contains($config, $expected), 'Konfiguration enthält ' . trim($expected));
}

$missing = zsm_resolve_shares([$normalized], [], ['claudio']);
$unmounted = zsm_resolve_shares([$normalized], [['mounted' => 'no'] + $filesystem], ['claudio']);
$noUsers = zsm_resolve_shares([$normalized], [$filesystem], []);
foreach ([$missing, $unmounted, $noUsers] as $inactive) {
    check($inactive[0]['problem'] !== '', 'Problem wird gemeldet');
    check(!str_contains(zsm_render_samba_config($inactive), '['), 'inaktive Freigabe wird nicht veröffentlicht');
}

$iniFile = tempnam(sys_get_temp_dir(), 'zsm');
file_put_contents($iniFile, "[\"root\"]\nname=\"root\"\npasswd=\"yes\"\n[\"claudio\"]\nname=\"claudio\"\npasswd=\"yes\"\n[\"gast\"]\nname=\"gast\"\npasswd=\"no\"\n");
check(zsm_load_unraid_users($iniFile) === ['claudio'], 'nur Benutzer mit Passwort und ohne root');
file_put_contents($iniFile, "[\"Medien\"]\nname=\"Medien\"\n");
check(in_array('medien', zsm_reserved_share_names($iniFile), true), 'bestehende Freigaben sind reserviert');
unlink($iniFile);

$orphanRoot = sys_get_temp_dir() . '/zsm-orphans-' . bin2hex(random_bytes(4));
mkdir("$orphanRoot/db/nox", 0777, true);
mkdir("$orphanRoot/db/Kairo", 0777, true);
mkdir("$orphanRoot/vm/nox", 0777, true);
mkdir("$orphanRoot/vm/Kairo", 0777, true);
mkdir("$orphanRoot/vm/ohne-liste", 0777, true);
file_put_contents("$orphanRoot/db/nox/snapshots.db", '{"S20261101120000":{"name":"S20261101120000"}}');
file_put_contents("$orphanRoot/db/Kairo/snapshots.db", '[]');
file_put_contents("$orphanRoot/vm/nox/S20261101110000.running", 'abc');
file_put_contents("$orphanRoot/vm/nox/memoryS20261101110000.mem", 'abcde');
file_put_contents("$orphanRoot/vm/nox/S20261101120000.running", 'x');
file_put_contents("$orphanRoot/vm/nox/nox.qcow2", 'x');
file_put_contents("$orphanRoot/vm/Kairo/memoryS20261101100000.mem", 'ab');
file_put_contents("$orphanRoot/vm/ohne-liste/S20261101090000.running", 'x');
$orphanSnapshots = [
    ['name' => 'vm/domains/nox@S20261101110000', 'used' => '100'],
    ['name' => 'vm/domains/nox@S20261101120000', 'used' => '100'],
    ['name' => 'vm/domains/nox@S20261101130000', 'used' => '100'],
    ['name' => 'vm/domains/nox@uzm_manual_20261101T120000Z', 'used' => '100'],
    ['name' => 'vm/domains/ohne-liste@S20261101090000', 'used' => '100'],
];
$orphanFiles = zsm_vm_snapshot_files("$orphanRoot/vm/nox");
check(array_keys($orphanFiles) === ['S20261101110000', 'S20261101120000'], 'nur VM-Snapshot-Dateien werden erfasst');
check(array_sum(array_column($orphanFiles['S20261101110000'], 'size')) === 8, 'Dateigrößen werden summiert');

$mountRoot = "$orphanRoot/vm/";
$mounted = [
    ['guid' => '1', 'name' => 'vm/domains/nox', 'mountpoint' => "{$mountRoot}nox", 'mounted' => 'yes'],
    ['guid' => '2', 'name' => 'vm/domains/Kairo', 'mountpoint' => "{$mountRoot}Kairo", 'mounted' => 'yes'],
    ['guid' => '3', 'name' => 'vm/domains/ohne-liste', 'mountpoint' => "{$mountRoot}ohne-liste", 'mounted' => 'yes'],
];
$orphans = zsm_find_vm_orphans($orphanSnapshots, $mounted, "$orphanRoot/db", $mountRoot);
check(array_column($orphans, 'id') === ['S20261101130000', 'S20261101110000', 'S20261101100000'], 'nur nicht gelistete Snapshots gelten als verwaist');
check($orphans[1]['snapshot'] === 'vm/domains/nox@S20261101110000' && $orphans[1]['files_size'] === 8, 'Snapshot und Dateien werden zusammengeführt');
check($orphans[0]['files'] === [], 'Snapshot ohne Dateien wird gemeldet');
check($orphans[2]['snapshot'] === '' && $orphans[2]['vm'] === 'Kairo', 'Dateien ohne ZFS-Snapshot werden gemeldet');
check(zsm_find_vm_orphans($orphanSnapshots, $mounted, "$orphanRoot/db") === [], 'Mountpoint außerhalb des Wurzelpfads wird nicht gelesen');
check(zsm_find_vm_orphans($orphanSnapshots, [['mounted' => 'no'] + $mounted[0]], "$orphanRoot/db", $mountRoot) === [], 'nicht eingehängtes Dataset wird übersprungen');
file_put_contents("$orphanRoot/db/nox/snapshots.db", '');
check(zsm_find_vm_orphans($orphanSnapshots, [$mounted[0]], "$orphanRoot/db", $mountRoot) === [], 'leere Datenbank meldet nichts');
foreach (['vm/nox', 'vm/Kairo', 'vm/ohne-liste', 'db/nox', 'db/Kairo'] as $directory) {
    array_map('unlink', glob("$orphanRoot/$directory/*"));
    rmdir("$orphanRoot/$directory");
}
rmdir("$orphanRoot/vm");
rmdir("$orphanRoot/db");
rmdir($orphanRoot);

check(str_contains(zsm_vm_orphan_hint($orphans[1]), 'memoryS20261101110000.mem'), 'Hinweis nennt die Dateien');
check(!str_contains(zsm_vm_orphan_hint($orphans[0]), 'Dateien'), 'Hinweis ohne Dateien bleibt kurz');

$stats = zsm_snapshot_stats([
    ['name' => 'tank/a@eins', 'creation' => '100'],
    ['name' => 'tank/a@zwei', 'creation' => '300'],
    ['name' => 'tank/a@drei', 'creation' => '200'],
    ['name' => 'tank/b@eins', 'creation' => 'kaputt'],
]);
check($stats['tank/a'] === ['count' => 3, 'latest' => 300], 'Anzahl und neuester Snapshot je Dataset');
check($stats['tank/b'] === ['count' => 1, 'latest' => 0], 'ungültiger Zeitstempel wird ignoriert');

echo 'PHP-Tests erfolgreich.' . PHP_EOL;
