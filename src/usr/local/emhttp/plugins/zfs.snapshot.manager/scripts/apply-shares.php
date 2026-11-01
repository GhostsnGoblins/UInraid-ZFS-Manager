#!/usr/bin/php
<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/zfs.snapshot.manager/include/lib.php';

$lock = zsm_lock_shares(true);
if ($lock === null) {
    fwrite(STDERR, 'Die Freigaben-Sperre konnte nicht gesetzt werden.' . PHP_EOL);
    exit(75);
}

$result = zsm_apply_shares();
if ($result['exitCode'] !== 0) {
    fwrite(STDERR, ($result['stderr'] ?: 'Samba-Aktualisierung fehlgeschlagen.') . PHP_EOL);
    exit($result['exitCode']);
}
exit(0);
