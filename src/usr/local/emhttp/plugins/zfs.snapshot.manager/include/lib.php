<?php

declare(strict_types=1);

const ZSM_BACKEND = '/usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager';
const ZSM_CONFIG_FILE = '/boot/config/plugins/zfs.snapshot.manager/zfs.snapshot.manager.cfg';
const ZSM_POLICIES_FILE = '/boot/config/plugins/zfs.snapshot.manager/policies.json';
const ZSM_CRON_FILE = '/boot/config/plugins/zfs.snapshot.manager/zfs.snapshot.manager.cron';
const ZSM_POLICY_RUNNER = '/usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/policy-runner.php';
const ZSM_SCRUBS_FILE = '/boot/config/plugins/zfs.snapshot.manager/scrubs.json';
const ZSM_SCRUB_RUNNER = '/usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/scrub-runner.php';
const ZSM_SHARES_FILE = '/boot/config/plugins/zfs.snapshot.manager/shares.json';
const ZSM_SAMBA_CANDIDATE_FILE = '/boot/config/plugins/zfs.snapshot.manager/smb-snapshots.conf.new';
const ZSM_UNRAID_USERS_FILE = '/var/local/emhttp/users.ini';
const ZSM_UNRAID_SHARES_FILE = '/var/local/emhttp/shares.ini';
const ZSM_LIBVIRT_SNAPSHOT_DB = '/etc/libvirt/qemu/snapshotdb';
const ZSM_VERSION = '0.9.1';
const ZSM_SCRUB_INTERVAL_SECONDS = 2592000;

function zsm_is_enabled(): bool
{
    if (!is_file(ZSM_CONFIG_FILE)) {
        return false;
    }

    $config = @parse_ini_file(ZSM_CONFIG_FILE, false, INI_SCANNER_RAW);
    return is_array($config) && ($config['ENABLED'] ?? '') === 'yes';
}

function zsm_atomic_write(string $path, string $contents, int $mode): bool
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }

    $temporaryFile = tempnam($directory, '.zsm-');
    if ($temporaryFile === false) {
        return false;
    }

    $written = file_put_contents($temporaryFile, $contents, LOCK_EX);
    if ($written !== strlen($contents)) {
        @unlink($temporaryFile);
        return false;
    }

    @chmod($temporaryFile, $mode);
    if (!rename($temporaryFile, $path)) {
        @unlink($temporaryFile);
        return false;
    }

    return true;
}

function zsm_save_enabled(bool $enabled): bool
{
    $contents = 'ENABLED="' . ($enabled ? 'yes' : 'no') . '"' . PHP_EOL;
    return zsm_atomic_write(ZSM_CONFIG_FILE, $contents, 0644);
}

function zsm_is_valid_dataset(string $dataset): bool
{
    return strlen($dataset) <= 255
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*(\/[A-Za-z0-9][A-Za-z0-9_.:-]*)*$/D', $dataset) === 1;
}

function zsm_is_valid_policy_id(string $id): bool
{
    return preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
}

function zsm_is_valid_pool_guid(string $guid): bool
{
    return preg_match('/^[0-9]{1,20}$/D', $guid) === 1;
}

/** @return array<string,string> */
function zsm_frequency_labels(): array
{
    return ['hourly' => 'Stündlich', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich'];
}

/** @return array<string,string> */
function zsm_retention_unit_labels(): array
{
    return ['hours' => 'Stunden', 'days' => 'Tage', 'weeks' => 'Wochen', 'months' => 'Monate'];
}

/** @return array<int,string> */
function zsm_weekday_labels(): array
{
    return [
        0 => 'Sonntag',
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag',
        6 => 'Samstag',
    ];
}

/** @param array<string,string> $pool */
function zsm_pool_scan_label(array $pool): string
{
    $type = $pool['scan_type'] ?? 'unknown';
    $state = $pool['scan_state'] ?? 'unknown';
    if ($type === 'none') {
        return 'Noch kein Scrub protokolliert';
    }
    if ($type === 'resilver') {
        return $state === 'in_progress' ? 'Resilver läuft' : 'Letzter Scan war ein Resilver';
    }
    if ($type !== 'scrub') {
        return $state === 'unavailable' ? 'Nicht verfügbar' : 'Unbekannter Scan-Status';
    }

    $labels = [
        'in_progress' => 'Scrub läuft',
        'paused' => 'Scrub pausiert',
        'completed' => 'Scrub abgeschlossen',
        'canceled' => 'Scrub abgebrochen',
        'unavailable' => 'Nicht verfügbar',
    ];
    return $labels[$state] ?? 'Unbekannter Scrub-Status';
}

/** @param array<string,string> $pool */
function zsm_pool_scan_timestamp(array $pool): int
{
    $state = $pool['scan_state'] ?? '';
    $raw = in_array($state, ['completed', 'canceled'], true)
        ? ($pool['scan_completed'] ?? '0')
        : ($pool['scan_started'] ?? '0');
    return ctype_digit($raw) ? (int) $raw : 0;
}

/** @param array<string,string> $pool */
function zsm_pool_scan_result(array $pool): string
{
    if (($pool['scan_type'] ?? '') !== 'scrub'
        || !in_array($pool['scan_state'] ?? '', ['completed', 'in_progress', 'paused'], true)) {
        return '-';
    }
    $repaired = zsm_format_bytes($pool['repaired'] ?? '0');
    $errors = ctype_digit($pool['errors'] ?? '') ? (int) $pool['errors'] : 0;
    return $repaired . ' repariert, ' . $errors . ' Fehler';
}

/** @param array<string,string> $pool */
function zsm_pool_scan_progress(array $pool): string
{
    $progress = $pool['progress'] ?? '';
    if ($progress === '') {
        return ($pool['scan_state'] ?? '') === 'in_progress' ? 'Wird ermittelt' : '-';
    }
    $label = $progress . ' %';
    $eta = trim($pool['eta'] ?? '');
    return $eta === '' ? $label : $label . ', etwa ' . $eta;
}

/**
 * @param mixed $candidate
 * @return array{guid:string,weekday:int,hour:int,minute:int}|null
 */
function zsm_normalize_scrub_schedule(mixed $candidate): ?array
{
    if (is_string($candidate)) {
        return zsm_is_valid_pool_guid($candidate)
            ? ['guid' => $candidate, 'weekday' => 0, 'hour' => 3, 'minute' => 0]
            : null;
    }
    if (!is_array($candidate)) {
        return null;
    }

    $guid = $candidate['guid'] ?? '';
    $weekday = filter_var($candidate['weekday'] ?? null, FILTER_VALIDATE_INT);
    $hour = filter_var($candidate['hour'] ?? null, FILTER_VALIDATE_INT);
    $minute = filter_var($candidate['minute'] ?? null, FILTER_VALIDATE_INT);
    if (!is_string($guid) || !zsm_is_valid_pool_guid($guid)
        || $weekday === false || $weekday < 0 || $weekday > 6
        || $hour === false || $hour < 0 || $hour > 23
        || $minute === false || $minute < 0 || $minute > 59) {
        return null;
    }
    return ['guid' => $guid, 'weekday' => $weekday, 'hour' => $hour, 'minute' => $minute];
}

/** @return list<array{guid:string,weekday:int,hour:int,minute:int}> */
function zsm_normalize_scrub_schedules(array $values): array
{
    $schedules = [];
    foreach ($values as $value) {
        $schedule = zsm_normalize_scrub_schedule($value);
        if ($schedule !== null) {
            $schedules[$schedule['guid']] = $schedule;
        }
    }
    ksort($schedules, SORT_STRING);
    return array_values($schedules);
}

/** @return list<array{guid:string,weekday:int,hour:int,minute:int}> */
function zsm_load_scrub_schedules(): array
{
    $raw = is_file(ZSM_SCRUBS_FILE) ? @file_get_contents(ZSM_SCRUBS_FILE) : false;
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) && array_is_list($decoded)
        ? zsm_normalize_scrub_schedules($decoded)
        : [];
}

/** @return list<string> */
function zsm_load_auto_scrub_guids(): array
{
    return array_column(zsm_load_scrub_schedules(), 'guid');
}

function zsm_save_scrub_schedules(array $schedules): bool
{
    $json = json_encode(zsm_normalize_scrub_schedules($schedules), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($json) && zsm_atomic_write(ZSM_SCRUBS_FILE, $json . PHP_EOL, 0600);
}

/**
 * @param list<array{guid:string,weekday:int,hour:int,minute:int}> $schedules
 * @return array{guid:string,weekday:int,hour:int,minute:int}|null
 */
function zsm_find_scrub_schedule(array $schedules, string $guid): ?array
{
    foreach ($schedules as $schedule) {
        if ($schedule['guid'] === $guid) {
            return $schedule;
        }
    }
    return null;
}

/** @param array<string,string> $pool */
function zsm_scrub_due_timestamp(array $pool): int
{
    if (($pool['scan_type'] ?? '') !== 'scrub' || ($pool['scan_state'] ?? '') !== 'completed') {
        return 0;
    }
    $completed = $pool['scan_completed'] ?? '0';
    return ctype_digit($completed) && (int) $completed > 0
        ? (int) $completed + ZSM_SCRUB_INTERVAL_SECONDS
        : 0;
}

/** @param array<string,string> $pool */
function zsm_scrub_is_due(array $pool, ?int $now = null): bool
{
    $due = zsm_scrub_due_timestamp($pool);
    return $due === 0 || $due <= ($now ?? time());
}

/** @param array<string,string> $pool */
function zsm_scrub_schedule_ready(array $schedule, ?DateTimeImmutable $now = null): bool
{
    $now ??= new DateTimeImmutable('now');
    return (int) $now->format('w') === $schedule['weekday']
        && ((int) $now->format('G') * 60 + (int) $now->format('i')) >= ($schedule['hour'] * 60 + $schedule['minute']);
}

/**
 * @param array<string,string> $pool
 * @param array{guid:string,weekday:int,hour:int,minute:int} $schedule
 */
function zsm_next_scrub_run(array $pool, array $schedule, ?DateTimeImmutable $now = null): DateTimeImmutable
{
    $now ??= new DateTimeImmutable('now');
    $due = zsm_scrub_due_timestamp($pool);
    $notBeforeTimestamp = max($now->getTimestamp(), $due);
    $notBefore = (new DateTimeImmutable('@' . $notBeforeTimestamp))->setTimezone($now->getTimezone());
    $currentWeekday = (int) $notBefore->format('w');
    $currentMinutes = (int) $notBefore->format('G') * 60 + (int) $notBefore->format('i');
    $targetMinutes = $schedule['hour'] * 60 + $schedule['minute'];
    if ($currentWeekday === $schedule['weekday'] && $currentMinutes >= $targetMinutes) {
        return $notBefore;
    }

    $daysAhead = ($schedule['weekday'] - $currentWeekday + 7) % 7;
    $candidate = $notBefore->setTime($schedule['hour'], $schedule['minute'], 0);
    if ($daysAhead > 0) {
        $candidate = $candidate->modify('+' . $daysAhead . ' days');
    } elseif ($candidate < $notBefore) {
        $candidate = $candidate->modify('+7 days');
    }
    return $candidate;
}

/**
 * @param array<string,mixed> $policy
 * @return array{id:string,dataset:string,recursive:bool,frequency:string,minute:int,hour:int,weekday:int,retention_value:int,retention_unit:string}|null
 */
function zsm_normalize_policy(array $policy): ?array
{
    $id = $policy['id'] ?? '';
    $dataset = $policy['dataset'] ?? '';
    $recursive = $policy['recursive'] ?? false;
    $frequency = $policy['frequency'] ?? '';
    $retentionUnit = $policy['retention_unit'] ?? '';
    $minute = filter_var($policy['minute'] ?? null, FILTER_VALIDATE_INT);
    $hour = filter_var($policy['hour'] ?? null, FILTER_VALIDATE_INT);
    $weekday = filter_var($policy['weekday'] ?? null, FILTER_VALIDATE_INT);
    $retentionValue = filter_var($policy['retention_value'] ?? null, FILTER_VALIDATE_INT);

    if (!is_string($id) || !zsm_is_valid_policy_id($id)
        || !is_string($dataset) || !zsm_is_valid_dataset($dataset)
        || !is_bool($recursive)
        || !is_string($frequency) || !array_key_exists($frequency, zsm_frequency_labels())
        || !is_string($retentionUnit) || !array_key_exists($retentionUnit, zsm_retention_unit_labels())
        || $minute === false || $minute < 0 || $minute > 59
        || $hour === false || $hour < 0 || $hour > 23
        || $weekday === false || $weekday < 0 || $weekday > 6
        || $retentionValue === false || $retentionValue < 1 || $retentionValue > 999) {
        return null;
    }

    return [
        'id' => $id,
        'dataset' => $dataset,
        'recursive' => $recursive,
        'frequency' => $frequency,
        'minute' => $minute,
        'hour' => $hour,
        'weekday' => $weekday,
        'retention_value' => $retentionValue,
        'retention_unit' => $retentionUnit,
    ];
}

/**
 * @return list<array{id:string,dataset:string,recursive:bool,frequency:string,minute:int,hour:int,weekday:int,retention_value:int,retention_unit:string}>
 */
function zsm_load_policies(): array
{
    if (!is_file(ZSM_POLICIES_FILE)) {
        return [];
    }

    $raw = @file_get_contents(ZSM_POLICIES_FILE);
    if (!is_string($raw)) {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return [];
    }

    $policies = [];
    foreach ($decoded as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $policy = zsm_normalize_policy($candidate);
        if ($policy !== null) {
            $policies[] = $policy;
        }
    }
    return $policies;
}

/** @param list<array<string,mixed>> $policies */
function zsm_save_policies(array $policies): bool
{
    $normalized = [];
    foreach ($policies as $candidate) {
        $policy = zsm_normalize_policy($candidate);
        if ($policy === null) {
            return false;
        }
        $normalized[] = $policy;
    }

    $contents = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($contents)) {
        return false;
    }
    return zsm_atomic_write(ZSM_POLICIES_FILE, $contents . PHP_EOL, 0600);
}

/**
 * @param list<array{id:string,dataset:string,recursive:bool,frequency:string,minute:int,hour:int,weekday:int,retention_value:int,retention_unit:string}> $policies
 * @return array{id:string,dataset:string,recursive:bool,frequency:string,minute:int,hour:int,weekday:int,retention_value:int,retention_unit:string}|null
 */
function zsm_find_policy(array $policies, string $id): ?array
{
    foreach ($policies as $policy) {
        if ($policy['id'] === $id) {
            return $policy;
        }
    }
    return null;
}

function zsm_generate_policy_id(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * @param array{id:string,dataset:string,recursive:bool} $first
 * @param array{id:string,dataset:string,recursive:bool} $second
 */
function zsm_policy_scopes_overlap(array $first, array $second): bool
{
    if ($first['dataset'] === $second['dataset']) {
        return true;
    }

    $firstContainsSecond = str_starts_with($second['dataset'], $first['dataset'] . '/');
    $secondContainsFirst = str_starts_with($first['dataset'], $second['dataset'] . '/');
    return ($first['recursive'] && $firstContainsSecond)
        || ($second['recursive'] && $secondContainsFirst);
}

/** @param array{id:string,dataset:string,recursive:bool,frequency:string,minute:int,hour:int,weekday:int,retention_value:int,retention_unit:string} $policy */
function zsm_policy_cron_expression(array $policy): string
{
    if ($policy['frequency'] === 'hourly') {
        return sprintf('%d * * * *', $policy['minute']);
    }
    if ($policy['frequency'] === 'daily') {
        return sprintf('%d %d * * *', $policy['minute'], $policy['hour']);
    }
    return sprintf('%d %d * * %d', $policy['minute'], $policy['hour'], $policy['weekday']);
}

/** @param array{id:string,dataset:string,recursive:bool,frequency:string,minute:int,hour:int,weekday:int,retention_value:int,retention_unit:string} $policy */
function zsm_policy_schedule_label(array $policy): string
{
    if ($policy['frequency'] === 'hourly') {
        return sprintf('stündlich um Minute %02d', $policy['minute']);
    }
    if ($policy['frequency'] === 'daily') {
        return sprintf('täglich um %02d:%02d Uhr', $policy['hour'], $policy['minute']);
    }
    $weekdays = zsm_weekday_labels();
    return sprintf('%s um %02d:%02d Uhr', $weekdays[$policy['weekday']], $policy['hour'], $policy['minute']);
}

/** @param array{id:string,dataset:string,recursive:bool,frequency:string,minute:int,hour:int,weekday:int,retention_value:int,retention_unit:string} $policy */
function zsm_policy_retention_seconds(array $policy): int
{
    $secondsPerUnit = ['hours' => 3600, 'days' => 86400, 'weeks' => 604800, 'months' => 2592000];
    return $policy['retention_value'] * $secondsPerUnit[$policy['retention_unit']];
}

/** @param array{id:string,dataset:string,recursive:bool,frequency:string,minute:int,hour:int,weekday:int,retention_value:int,retention_unit:string} $policy */
function zsm_policy_next_run(array $policy): DateTimeImmutable
{
    $now = new DateTimeImmutable('now');
    if ($policy['frequency'] === 'hourly') {
        $candidate = $now->setTime((int) $now->format('H'), $policy['minute'], 0);
        return $candidate <= $now ? $candidate->modify('+1 hour') : $candidate;
    }

    $candidate = $now->setTime($policy['hour'], $policy['minute'], 0);
    if ($policy['frequency'] === 'daily') {
        return $candidate <= $now ? $candidate->modify('+1 day') : $candidate;
    }

    $currentWeekday = (int) $now->format('w');
    $daysAhead = ($policy['weekday'] - $currentWeekday + 7) % 7;
    if ($daysAhead > 0) {
        $candidate = $candidate->modify('+' . $daysAhead . ' days');
    }
    return $candidate <= $now ? $candidate->modify('+7 days') : $candidate;
}

/**
 * Executes an argument vector without invoking a shell.
 *
 * @return array{exitCode:int,stdout:string,stderr:string,timedOut:bool}
 */
function zsm_run_process(array $command, int $timeoutSeconds, string $startError): array
{
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['exitCode' => 127, 'stdout' => '', 'stderr' => $startError, 'timedOut' => false];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $lastStatus = null;
    $startedAt = microtime(true);

    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $lastStatus = proc_get_status($process);
        if (!$lastStatus['running']) {
            break;
        }
        if ((microtime(true) - $startedAt) >= $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process, 15);
            usleep(200000);
            $lastStatus = proc_get_status($process);
            if ($lastStatus['running']) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(20000);
    }

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    $exitCode = $closeCode;
    if ($exitCode === -1 && is_array($lastStatus) && $lastStatus['exitcode'] >= 0) {
        $exitCode = $lastStatus['exitcode'];
    }
    if ($timedOut) {
        $exitCode = 124;
        $stderr = 'Zeitüberschreitung beim Aufruf des ZFS-Backends.';
    }

    return ['exitCode' => $exitCode, 'stdout' => trim($stdout), 'stderr' => trim($stderr), 'timedOut' => $timedOut];
}

/** @return array{exitCode:int,stdout:string,stderr:string,timedOut:bool} */
function zsm_run(array $arguments, int $timeoutSeconds = 15): array
{
    if (!is_file(ZSM_BACKEND) || !is_executable(ZSM_BACKEND)) {
        return ['exitCode' => 127, 'stdout' => '', 'stderr' => 'Das Plugin-Backend ist nicht installiert oder nicht ausführbar.', 'timedOut' => false];
    }

    return zsm_run_process(
        array_merge([ZSM_BACKEND], array_values($arguments)),
        $timeoutSeconds,
        'Das Plugin-Backend konnte nicht gestartet werden.'
    );
}

/** @return array{exitCode:int,stdout:string,stderr:string,timedOut:bool} */
function zsm_apply_cron(): array
{
    $lines = ['# Generated by ZFS Snapshot Manager. Do not edit manually.'];
    $policies = zsm_load_policies();
    $scrubSchedules = zsm_load_scrub_schedules();

    if (zsm_is_enabled() && $policies !== []) {
        foreach ($policies as $policy) {
            $lines[] = sprintf(
                '%s /usr/bin/php %s run %s >/dev/null 2>&1',
                zsm_policy_cron_expression($policy),
                ZSM_POLICY_RUNNER,
                $policy['id']
            );
        }
        $lines[] = sprintf('*/15 * * * * /usr/bin/php %s cleanup >/dev/null 2>&1', ZSM_POLICY_RUNNER);
    }
    if (zsm_is_enabled() && $scrubSchedules !== []) {
        $lines[] = sprintf('* * * * * /usr/bin/php %s >/dev/null 2>&1', ZSM_SCRUB_RUNNER);
    }

    if (!zsm_atomic_write(ZSM_CRON_FILE, implode(PHP_EOL, $lines) . PHP_EOL, 0644)) {
        return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Die Cron-Datei konnte nicht geschrieben werden.', 'timedOut' => false];
    }
    return zsm_run(['refresh-cron'], 30);
}

function zsm_is_valid_share_name(string $name): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $name) === 1;
}

function zsm_is_valid_user_name(string $name): bool
{
    return $name !== 'root' && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$/D', $name) === 1;
}

function zsm_is_valid_dataset_guid(string $guid): bool
{
    return preg_match('/^[0-9]{1,20}$/D', $guid) === 1;
}

/** Nur diese Pfade duerfen in die Samba-Konfiguration, damit keine Makros oder Zeilenumbrueche hineingelangen. */
function zsm_is_safe_mountpoint(string $mountpoint): bool
{
    return preg_match('#^(/[A-Za-z0-9_.:-]+)+$#D', $mountpoint) === 1
        && !in_array('..', explode('/', $mountpoint), true);
}

/**
 * @param array<string,mixed> $share
 * @return array{id:string,dataset_guid:string,dataset:string,name:string,users:list<string>}|null
 */
function zsm_normalize_share(array $share): ?array
{
    $id = $share['id'] ?? '';
    $guid = $share['dataset_guid'] ?? '';
    $dataset = $share['dataset'] ?? '';
    $name = $share['name'] ?? '';
    $users = $share['users'] ?? [];

    if (!is_string($id) || !zsm_is_valid_policy_id($id)
        || !is_string($guid) || !zsm_is_valid_dataset_guid($guid)
        || !is_string($dataset) || !zsm_is_valid_dataset($dataset)
        || !is_string($name) || !zsm_is_valid_share_name($name)
        || !is_array($users) || !array_is_list($users) || $users === []) {
        return null;
    }
    foreach ($users as $user) {
        if (!is_string($user) || !zsm_is_valid_user_name($user)) {
            return null;
        }
    }

    $users = array_values(array_unique($users));
    sort($users);
    return ['id' => $id, 'dataset_guid' => $guid, 'dataset' => $dataset, 'name' => $name, 'users' => $users];
}

/** @return list<array{id:string,dataset_guid:string,dataset:string,name:string,users:list<string>}> */
function zsm_load_shares(): array
{
    $raw = is_file(ZSM_SHARES_FILE) ? @file_get_contents(ZSM_SHARES_FILE) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return [];
    }

    $shares = [];
    foreach ($decoded as $candidate) {
        $share = is_array($candidate) ? zsm_normalize_share($candidate) : null;
        if ($share !== null) {
            $shares[] = $share;
        }
    }
    return $shares;
}

/** @param list<array<string,mixed>> $shares */
function zsm_save_shares(array $shares): bool
{
    $normalized = [];
    foreach ($shares as $candidate) {
        $share = zsm_normalize_share($candidate);
        if ($share === null) {
            return false;
        }
        $normalized[] = $share;
    }

    $contents = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($contents) && zsm_atomic_write(ZSM_SHARES_FILE, $contents . PHP_EOL, 0600);
}

/**
 * Unraid-Benutzer mit gesetztem Passwort. Samba laeuft hier mit "null passwords",
 * ein Konto ohne Passwort waere also kein wirksamer Zugriffsschutz.
 *
 * @return list<string>
 */
function zsm_load_unraid_users(string $path = ZSM_UNRAID_USERS_FILE): array
{
    $entries = is_file($path) ? @parse_ini_file($path, true, INI_SCANNER_RAW) : false;
    if (!is_array($entries)) {
        return [];
    }

    $users = [];
    foreach ($entries as $entry) {
        $name = is_array($entry) ? ($entry['name'] ?? '') : '';
        if (is_string($name) && zsm_is_valid_user_name($name) && ($entry['passwd'] ?? '') === 'yes') {
            $users[] = $name;
        }
    }
    sort($users);
    return array_values(array_unique($users));
}

/** @return list<string> Kleingeschriebene Freigabenamen, die Unraid oder Samba bereits verwenden. */
function zsm_reserved_share_names(string $path = ZSM_UNRAID_SHARES_FILE): array
{
    $names = ['global', 'homes', 'printers', 'print$', 'ipc$', 'flash', 'user', 'user0'];
    $entries = is_file($path) ? @parse_ini_file($path, true, INI_SCANNER_RAW) : false;
    if (is_array($entries)) {
        foreach (array_keys($entries) as $name) {
            $names[] = strtolower(trim((string) $name, '"'));
        }
    }
    return $names;
}

/** @return array{ok:bool,error:string,filesystems:list<array<string,string>>} */
function zsm_load_filesystems(): array
{
    $result = zsm_run(['list-filesystems']);
    if ($result['exitCode'] !== 0) {
        return ['ok' => false, 'error' => $result['stderr'] ?: 'Datasets konnten nicht geprüft werden.', 'filesystems' => []];
    }
    return [
        'ok' => true,
        'error' => '',
        'filesystems' => zsm_parse_tsv($result['stdout'], ['guid', 'name', 'mountpoint', 'mounted']),
    ];
}

/**
 * Ordnet Freigaben ueber die Dataset-GUID dem aktuellen Dataset zu. Eine Freigabe
 * wird nur aktiv, wenn Dataset, Mountpoint und mindestens ein Benutzer gueltig sind.
 *
 * @param list<array{id:string,dataset_guid:string,dataset:string,name:string,users:list<string>}> $shares
 * @param list<array<string,string>> $filesystems
 * @param list<string> $users
 * @return list<array{id:string,dataset_guid:string,dataset:string,name:string,users:list<string>,path:string,problem:string}>
 */
function zsm_resolve_shares(array $shares, array $filesystems, array $users): array
{
    $byGuid = array_column($filesystems, null, 'guid');
    $resolved = [];
    foreach ($shares as $share) {
        $filesystem = $byGuid[$share['dataset_guid']] ?? null;
        $allowedUsers = array_values(array_intersect($share['users'], $users));
        $share['users'] = $allowedUsers;
        $share['path'] = '';
        $share['problem'] = '';

        if ($filesystem === null) {
            $share['problem'] = 'Dataset nicht gefunden';
        } elseif ($filesystem['mounted'] !== 'yes' || !zsm_is_safe_mountpoint($filesystem['mountpoint'])) {
            $share['dataset'] = $filesystem['name'];
            $share['problem'] = 'Dataset nicht sicher eingehängt';
        } elseif ($allowedUsers === []) {
            $share['dataset'] = $filesystem['name'];
            $share['problem'] = 'Kein gültiger Benutzer mit Passwort';
        } else {
            $share['dataset'] = $filesystem['name'];
            $share['path'] = $filesystem['mountpoint'] . '/.zfs/snapshot';
        }
        $resolved[] = $share;
    }
    return $resolved;
}

/** @param list<array{dataset:string,name:string,users:list<string>,path:string,problem:string}> $resolvedShares */
function zsm_render_samba_config(array $resolvedShares): string
{
    $lines = ['# Generated by ZFS Snapshot Manager. Do not edit manually.'];
    foreach ($resolvedShares as $share) {
        if ($share['problem'] !== '') {
            continue;
        }
        $users = implode(' ', $share['users']);
        array_push(
            $lines,
            '',
            '[' . $share['name'] . ']',
            "\tcomment = ZFS Snapshots von " . $share['dataset'],
            "\tpath = " . $share['path'],
            "\tvalid users = " . $users,
            "\tread list = " . $users,
            "\twrite list =",
            "\tadmin users =",
            "\tforce user =",
            "\tforce group =",
            "\tread only = yes",
            "\tguest ok = no",
            "\tbrowseable = yes",
            "\taccess based share enum = yes",
            // Unraid setzt global "wide links = Yes", Snapshots duerfen die Freigabe aber nicht verlassen.
            "\twide links = no",
            // Leere VFS-Liste: kein Papierkorb und keine Unraid-Module auf dieser Freigabe.
            "\tvfs objects ="
        );
    }
    return implode("\n", $lines) . "\n";
}

/** @return resource|null */
function zsm_lock_shares(bool $wait)
{
    $lock = @fopen('/var/run/zfs.snapshot.manager.shares.lock', 'c');
    if ($lock === false || !flock($lock, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
        return null;
    }
    return $lock;
}

/** @return array{exitCode:int,stdout:string,stderr:string,timedOut:bool} */
function zsm_apply_shares(): array
{
    $loaded = zsm_load_filesystems();
    if (!$loaded['ok']) {
        return ['exitCode' => 1, 'stdout' => '', 'stderr' => $loaded['error'], 'timedOut' => false];
    }

    $shares = zsm_is_enabled() ? zsm_load_shares() : [];
    $resolved = zsm_resolve_shares($shares, $loaded['filesystems'], zsm_load_unraid_users());
    if (!zsm_atomic_write(ZSM_SAMBA_CANDIDATE_FILE, zsm_render_samba_config($resolved), 0600)) {
        return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Die Samba-Konfiguration konnte nicht geschrieben werden.', 'timedOut' => false];
    }
    return zsm_run(['apply-samba-config'], 60);
}

/**
 * @param list<array<string,string>> $snapshots
 * @return array<string,array{count:int,latest:int}> Anzahl und neuester Zeitpunkt je Dataset
 */
function zsm_snapshot_stats(array $snapshots): array
{
    $stats = [];
    foreach ($snapshots as $snapshot) {
        $dataset = explode('@', $snapshot['name'], 2)[0];
        $stats[$dataset] ??= ['count' => 0, 'latest' => 0];
        $stats[$dataset]['count']++;
        if (ctype_digit($snapshot['creation'])) {
            $stats[$dataset]['latest'] = max($stats[$dataset]['latest'], (int) $snapshot['creation']);
        }
    }
    return $stats;
}

/** @return array<string,list<array{name:string,size:int}>> Dateien je Snapshot-ID */
function zsm_vm_snapshot_files(string $directory): array
{
    $files = [];
    foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $name) {
        if (preg_match('/^(?:(S[0-9]{14})\.running|memory(S[0-9]{14})\.mem)$/D', $name, $match) !== 1) {
            continue;
        }
        $path = $directory . '/' . $name;
        if (is_file($path) && !is_link($path)) {
            $files[$match[1] ?: $match[2]][] = ['name' => $name, 'size' => (int) filesize($path)];
        }
    }
    return $files;
}

/**
 * Findet VM-Snapshots, die Unraid nicht mehr fuehrt. Die VM-Oberflaeche entfernt bei
 * nativen ZFS-Snapshots nur den Listeneintrag, Snapshot und Dateien bleiben liegen.
 * Ohne lesbare snapshots.db wird nichts gemeldet, die Pruefung bleibt reine Anzeige.
 *
 * @param list<array<string,string>> $snapshots
 * @param list<array<string,string>> $filesystems
 * @return list<array{vm:string,dataset:string,id:string,snapshot:string,used:string,files:list<array{name:string,size:int}>,files_size:int}>
 */
function zsm_find_vm_orphans(array $snapshots, array $filesystems, string $databaseRoot = ZSM_LIBVIRT_SNAPSHOT_DB, string $mountRoot = '/mnt/'): array
{
    $byDataset = [];
    foreach ($snapshots as $snapshot) {
        [$dataset, $id] = array_pad(explode('@', $snapshot['name'], 2), 2, '');
        if (preg_match('/^S[0-9]{14}$/D', $id) === 1) {
            $byDataset[$dataset][$id] = $snapshot['used'];
        }
    }

    $orphans = [];
    foreach ($filesystems as $filesystem) {
        $mountpoint = $filesystem['mountpoint'];
        if ($filesystem['mounted'] !== 'yes' || !str_starts_with($mountpoint, $mountRoot) || str_contains($mountpoint, '/..')) {
            continue;
        }

        $vm = basename($filesystem['name']);
        $databasePath = $databaseRoot . '/' . $vm . '/snapshots.db';
        $database = is_file($databasePath) ? file_get_contents($databasePath) : false;
        if (!is_string($database) || $database === '') {
            continue;
        }

        $dataset = $filesystem['name'];
        $zfsSnapshots = $byDataset[$dataset] ?? [];
        $files = zsm_vm_snapshot_files($mountpoint);
        foreach (array_keys($zfsSnapshots + $files) as $id) {
            $id = (string) $id;
            if (str_contains($database, $id)) {
                continue;
            }
            $orphanFiles = $files[$id] ?? [];
            $orphans[] = [
                'vm' => $vm,
                'dataset' => $dataset,
                'id' => $id,
                'snapshot' => isset($zfsSnapshots[$id]) ? $dataset . '@' . $id : '',
                'used' => $zfsSnapshots[$id] ?? '',
                'files' => $orphanFiles,
                'files_size' => array_sum(array_column($orphanFiles, 'size')),
            ];
        }
    }

    usort($orphans, static fn (array $first, array $second): int => strcmp($second['id'], $first['id']));
    return $orphans;
}

/** @param array{files:list<array{name:string,size:int}>,files_size:int} $orphan */
function zsm_vm_orphan_hint(array $orphan): string
{
    $hint = 'Nicht mehr in der Snapshot-Liste von Unraid.';
    if ($orphan['files']) {
        $names = implode(', ', array_column($orphan['files'], 'name'));
        $hint .= ' Zurückgebliebene Dateien im VM-Ordner: ' . $names . ' (' . zsm_format_bytes((string) $orphan['files_size']) . ').';
    }
    return $hint;
}

/** @return list<array<string,string>> */
function zsm_parse_tsv(string $text, array $columns): array
{
    if ($text === '') {
        return [];
    }

    $rows = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $values = explode("\t", $line);
        if (count($values) !== count($columns)) {
            continue;
        }
        $rows[] = array_combine($columns, $values);
    }
    return $rows;
}

function zsm_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zsm_format_bytes(string $raw): string
{
    if (!ctype_digit($raw)) {
        return $raw;
    }

    $bytes = (float) $raw;
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $unit = 0;
    while ($bytes >= 1024 && $unit < count($units) - 1) {
        $bytes /= 1024;
        $unit++;
    }
    return number_format($bytes, $unit === 0 ? 0 : 1, ',', '.') . ' ' . $units[$unit];
}

function zsm_format_timestamp(string $raw): string
{
    return ctype_digit($raw) ? date('d.m.Y H:i:s', (int) $raw) : $raw;
}
