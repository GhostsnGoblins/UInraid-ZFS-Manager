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

$schedules = zsm_load_scrub_schedules();
if ($schedules === []) {
    exit(0);
}
$dispatcherLock = @fopen('/var/run/zfs.snapshot.manager.scrub-dispatch.lock', 'c');
if ($dispatcherLock === false || !flock($dispatcherLock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$schedulesByGuid = array_column($schedules, null, 'guid');
$now = new DateTimeImmutable('now');

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
    static fn(array $pool): bool => isset($schedulesByGuid[$pool['guid']])
        && $pool['health'] === 'ONLINE'
        && zsm_scrub_schedule_ready($schedulesByGuid[$pool['guid']], $now)
        && zsm_scrub_is_due($pool)
));
usort($due, static function (array $first, array $second) use ($schedulesByGuid): int {
    $firstSchedule = $schedulesByGuid[$first['guid']];
    $secondSchedule = $schedulesByGuid[$second['guid']];
    $bySchedule = ($firstSchedule['hour'] * 60 + $firstSchedule['minute'])
        <=> ($secondSchedule['hour'] * 60 + $secondSchedule['minute']);
    if ($bySchedule !== 0) {
        return $bySchedule;
    }
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
