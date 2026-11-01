#!/usr/bin/php
<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/zfs.snapshot.manager/include/lib.php';

$result = zsm_apply_cron();
if ($result['exitCode'] !== 0) {
    fwrite(STDERR, ($result['stderr'] ?: 'Cron-Aktualisierung fehlgeschlagen.') . PHP_EOL);
    exit($result['exitCode']);
}
exit(0);
