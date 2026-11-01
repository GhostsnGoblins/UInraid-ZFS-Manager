#!/usr/bin/php
<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/zfs.snapshot.manager/include/lib.php';

if (PHP_SAPI !== 'cli') {
    exit(64);
}
if (!zsm_is_enabled()) {
    exit(0);
}

$mode = $argv[1] ?? '';
$policies = zsm_load_policies();

if ($mode === 'run') {
    $id = $argv[2] ?? '';
    if (!is_string($id) || !zsm_is_valid_policy_id($id)) {
        fwrite(STDERR, "Ungültige Zeitplan-ID.\n");
        exit(64);
    }
    $policy = zsm_find_policy($policies, $id);
    if ($policy === null) {
        exit(0);
    }
    $result = zsm_run([
        'run-policy',
        $policy['id'],
        $policy['dataset'],
        (string) zsm_policy_retention_seconds($policy),
        $policy['recursive'] ? '1' : '0',
    ], 180);
    if ($result['exitCode'] !== 0) {
        fwrite(STDERR, ($result['stderr'] ?: 'Zeitplan fehlgeschlagen.') . PHP_EOL);
        exit($result['exitCode']);
    }
    exit(0);
}

if ($mode === 'cleanup') {
    $exitCode = 0;
    foreach ($policies as $policy) {
        $result = zsm_run([
            'cleanup-policy',
            $policy['id'],
            $policy['dataset'],
            (string) zsm_policy_retention_seconds($policy),
        ], 120);
        if ($result['exitCode'] !== 0) {
            $exitCode = $result['exitCode'];
            fwrite(STDERR, ($result['stderr'] ?: 'Bereinigung fehlgeschlagen.') . PHP_EOL);
        }
    }
    exit($exitCode);
}

fwrite(STDERR, "Aufruf: policy-runner.php run ID | cleanup\n");
exit(64);
