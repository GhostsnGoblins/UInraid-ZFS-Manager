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

echo 'PHP-Tests erfolgreich.' . PHP_EOL;
