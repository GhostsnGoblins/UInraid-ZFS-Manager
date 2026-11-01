<?php

declare(strict_types=1);

const ZSM_BACKEND = '/usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager';
const ZSM_CONFIG_FILE = '/boot/config/plugins/zfs.snapshot.manager/zfs.snapshot.manager.cfg';
const ZSM_VERSION = '0.2.2';

function zsm_is_enabled(): bool
{
    if (!is_file(ZSM_CONFIG_FILE)) {
        return false;
    }

    $config = @parse_ini_file(ZSM_CONFIG_FILE, false, INI_SCANNER_RAW);
    return is_array($config) && ($config['ENABLED'] ?? '') === 'yes';
}

function zsm_save_enabled(bool $enabled): bool
{
    $directory = dirname(ZSM_CONFIG_FILE);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }

    $temporaryFile = tempnam($directory, '.zsm-');
    if ($temporaryFile === false) {
        return false;
    }

    $contents = 'ENABLED="' . ($enabled ? 'yes' : 'no') . '"' . PHP_EOL;
    $written = file_put_contents($temporaryFile, $contents, LOCK_EX);
    if ($written !== strlen($contents)) {
        @unlink($temporaryFile);
        return false;
    }

    @chmod($temporaryFile, 0644);
    if (!rename($temporaryFile, ZSM_CONFIG_FILE)) {
        @unlink($temporaryFile);
        return false;
    }

    return true;
}

/**
 * Executes the fixed plugin backend without invoking a shell.
 *
 * @return array{exitCode:int,stdout:string,stderr:string,timedOut:bool}
 */
function zsm_run(array $arguments, int $timeoutSeconds = 15): array
{
    if (!is_file(ZSM_BACKEND) || !is_executable(ZSM_BACKEND)) {
        return [
            'exitCode' => 127,
            'stdout' => '',
            'stderr' => 'Das Plugin-Backend ist nicht installiert oder nicht ausführbar.',
            'timedOut' => false,
        ];
    }

    $command = array_merge([ZSM_BACKEND], array_values($arguments));
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return [
            'exitCode' => 127,
            'stdout' => '',
            'stderr' => 'Das Plugin-Backend konnte nicht gestartet werden.',
            'timedOut' => false,
        ];
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

    return [
        'exitCode' => $exitCode,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
        'timedOut' => $timedOut,
    ];
}

/**
 * @return list<array<string,string>>
 */
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
    $decimals = $unit === 0 ? 0 : 1;
    return number_format($bytes, $decimals, ',', '.') . ' ' . $units[$unit];
}

function zsm_format_timestamp(string $raw): string
{
    if (!ctype_digit($raw)) {
        return $raw;
    }
    return date('d.m.Y H:i:s', (int) $raw);
}

