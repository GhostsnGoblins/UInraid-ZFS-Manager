<?php

declare(strict_types=1);

const ZSM_BACKEND = '/usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager';
const ZSM_CONFIG_FILE = '/boot/config/plugins/zfs.snapshot.manager/zfs.snapshot.manager.cfg';
const ZSM_POLICIES_FILE = '/boot/config/plugins/zfs.snapshot.manager/policies.json';
const ZSM_CRON_FILE = '/boot/config/plugins/zfs.snapshot.manager/zfs.snapshot.manager.cron';
const ZSM_POLICY_RUNNER = '/usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/policy-runner.php';
const ZSM_VERSION = '0.6.1';

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

    if (!zsm_atomic_write(ZSM_CRON_FILE, implode(PHP_EOL, $lines) . PHP_EOL, 0644)) {
        return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Die Cron-Datei konnte nicht geschrieben werden.', 'timedOut' => false];
    }
    return zsm_run(['refresh-cron'], 30);
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
