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

$enabled = array_fill_keys(zsm_load_auto_scrub_guids(), true);
if ($enabled === []) {
    exit(0);
}

$poolResult = zsm_run(['list-pools'], 30);
if ($poolResult['exitCode'] !== 0) {
    fwrite(STDERR, ($poolResult['stderr'] ?: 'Pool-Status konnte nicht geladen werden.') . PHP_EOL);
    exit($poolResult['exitCode']);
}
$pools = zsm_parse_tsv($poolResult['stdout'], ['guid', 'name', 'health', 'scan_type', 'scan_state', 'scan_started', 'scan_completed', 'repaired', 'errors', 'progress', 'eta', 'summary']);

foreach ($pools as $pool) {
    if (in_array($pool['scan_state'], ['in_progress', 'paused'], true)
        && in_array($pool['scan_type'], ['scrub', 'resilver'], true)) {
        exit(0);
    }
}

$due = array_values(array_filter(
    $pools,
    static fn(array $pool): bool => isset($enabled[$pool['guid']])
        && $pool['health'] === 'ONLINE'
        && zsm_scrub_is_due($pool)
));
usort($due, static function (array $first, array $second): int {
    $byTime = zsm_pool_scan_timestamp($first) <=> zsm_pool_scan_timestamp($second);
    return $byTime !== 0 ? $byTime : strcmp($first['guid'], $second['guid']);
});
if ($due === []) {
    exit(0);
}

$result = zsm_run(['start-scrub', $due[0]['guid'], 'automatic'], 30);
if ($result['exitCode'] !== 0) {
    fwrite(STDERR, ($result['stderr'] ?: 'Automatischer Scrub konnte nicht gestartet werden.') . PHP_EOL);
    exit($result['exitCode']);
}
exit(0);
