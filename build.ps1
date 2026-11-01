[CmdletBinding()]
param(
    [string]$Version = '0.7.2',
    [string]$OutputDirectory = 'dist',
    [string]$RepositoryRawBase = 'https://raw.githubusercontent.com/GhostsnGoblins/UInraid-ZFS-Manager/main'
)

$ErrorActionPreference = 'Stop'

$pluginName = 'zfs.snapshot.manager'
$packageName = "$pluginName-$Version-noarch-1.txz"
$projectRoot = $PSScriptRoot
$sourceRoot = Join-Path $projectRoot 'src'
$outputRoot = Join-Path $projectRoot $OutputDirectory
$buildRoot = Join-Path $projectRoot '.build'
$stageRoot = Join-Path $buildRoot 'package'
$packagePath = Join-Path $outputRoot $packageName
$manifestPath = Join-Path $outputRoot "$pluginName.plg"
$manifestUrl = "$RepositoryRawBase/dist/$pluginName.plg"
$packageUrl = "$RepositoryRawBase/dist/$packageName"

if (-not (Test-Path -LiteralPath $sourceRoot -PathType Container)) {
    throw "Quellordner fehlt: $sourceRoot"
}

if (Test-Path -LiteralPath $buildRoot) {
    Remove-Item -LiteralPath $buildRoot -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $stageRoot, $outputRoot | Out-Null

Copy-Item -Path (Join-Path $sourceRoot '*') -Destination $stageRoot -Recurse -Force

$versionLibrary = Join-Path $stageRoot 'usr\local\emhttp\plugins\zfs.snapshot.manager\include\lib.php'
$versionSource = Get-Content -Raw -LiteralPath $versionLibrary
if ($versionSource -notmatch [regex]::Escape("const ZSM_VERSION = '$Version';")) {
    throw "Plugin-Version in lib.php stimmt nicht mit der Build-Version $Version überein."
}

$slackInstall = Join-Path $stageRoot 'install'
New-Item -ItemType Directory -Force -Path $slackInstall | Out-Null
$slackDescription = @'
zfs.snapshot.manager: zfs.snapshot.manager
zfs.snapshot.manager:
zfs.snapshot.manager: Native Unraid WebGUI for listing ZFS datasets and snapshots.
zfs.snapshot.manager: Provides a top-level Snapshots tab and an enable setting.
zfs.snapshot.manager: Adds per-dataset schedules with age-based retention and safe cleanup.
zfs.snapshot.manager:
zfs.snapshot.manager: Project: Unraid ZFS Snapshot Manager
zfs.snapshot.manager:
zfs.snapshot.manager:
zfs.snapshot.manager:
zfs.snapshot.manager:
'@
Set-Content -LiteralPath (Join-Path $slackInstall 'slack-desc') -Value $slackDescription -Encoding ascii

if (Test-Path -LiteralPath $packagePath) {
    Remove-Item -LiteralPath $packagePath -Force
}
Get-ChildItem -LiteralPath $outputRoot -Filter "$pluginName-*-noarch-1.txz" -File |
    Where-Object { $_.Name -ne $packageName } |
    Remove-Item -Force

& tar.exe -cJf $packagePath -C $stageRoot .
if ($LASTEXITCODE -ne 0) {
    throw 'Das TXZ-Paket konnte nicht erstellt werden.'
}

$sha256 = (Get-FileHash -LiteralPath $packagePath -Algorithm SHA256).Hash.ToLowerInvariant()

$manifest = @"
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name "$pluginName">
<!ENTITY author "Claudio">
<!ENTITY version "$Version">
<!ENTITY pluginURL "$manifestUrl">
<!ENTITY packageURL "$packageUrl">
<!ENTITY packageSHA256 "$sha256">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" launch="Settings/ZFSSnapshotManager" min="6.12.0" icon="fa-history">
<CHANGES><![CDATA[
### $Version

- Suggests snapshot-<dataset> as the default snapshot share name
- Adds one private, read-only SMB snapshot share per dataset with explicit users
- Shows every snapshot of the dataset automatically and leaves normal Unraid shares unchanged
- Prevents scheduled snapshots from failing when the 15-minute cleanup starts at the same time
- Lets scheduled snapshot runs wait up to 60 seconds for the shared ZFS action lock
- Adds optional recursive snapshots for a dataset and all child datasets
- Shows every affected dataset before a recursive rule is saved
- Rejects overlapping recursive and child-dataset rules
- Preflights ownership, clones, and holds for the complete expired snapshot set
- Fixes the action-button row layout in the Unraid table
- Adds one automatic snapshot rule per selected dataset
- Supports hourly, daily, and weekly schedules with selectable time
- Supports age-based retention in hours, days, weeks, or months
- Runs expiration checks every 15 minutes while the plugin is enabled
- Deletes only automatic snapshots with matching name and local ZFS ownership properties
- Adds run-now and non-destructive deletion preview actions
- Keeps manual and foreign snapshots outside automatic cleanup
- Restores Unraid cron entries after installation and array start
]]></CHANGES>

<FILE Name="/boot/config/plugins/zfs.snapshot.manager/packages/$packageName">
<URL>&packageURL;</URL>
<SHA256>&packageSHA256;</SHA256>
</FILE>

<FILE Run="/bin/bash">
<INLINE><![CDATA[
set -euo pipefail

PACKAGE="/boot/config/plugins/zfs.snapshot.manager/packages/$packageName"
EXPECTED_SHA256="$sha256"

if [[ ! -f "`$PACKAGE" ]]; then
  echo "Missing local test package: `$PACKAGE"
  exit 1
fi

ACTUAL_SHA256="`$(sha256sum "`$PACKAGE" | awk '{print `$1}')"
if [[ "`$ACTUAL_SHA256" != "`$EXPECTED_SHA256" ]]; then
  echo "Package checksum mismatch. Installation stopped."
  exit 1
fi

/sbin/upgradepkg --install-new --reinstall "`$PACKAGE"
find /boot/config/plugins/zfs.snapshot.manager/packages -maxdepth 1 -type f -name 'zfs.snapshot.manager-*.txz' ! -name '$packageName' -delete

CONFIG_FILE="/boot/config/plugins/zfs.snapshot.manager/zfs.snapshot.manager.cfg"
if [[ ! -f "`$CONFIG_FILE" ]]; then
  printf 'ENABLED="yes"\n' > "`$CONFIG_FILE"
fi
chmod 644 "`$CONFIG_FILE"

POLICIES_FILE="/boot/config/plugins/zfs.snapshot.manager/policies.json"
if [[ ! -f "`$POLICIES_FILE" ]]; then
  printf '[]\n' > "`$POLICIES_FILE"
fi
chmod 600 "`$POLICIES_FILE"

chown -R root:root /usr/local/emhttp/plugins/zfs.snapshot.manager
find /usr/local/emhttp/plugins/zfs.snapshot.manager -type d -exec chmod 755 {} \;
find /usr/local/emhttp/plugins/zfs.snapshot.manager -type f -exec chmod 644 {} \;
chmod 755 /usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager
chmod 755 /usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/policy-runner.php
chmod 755 /usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/apply-cron.php
chmod 755 /usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/apply-shares.php
chmod 755 /usr/local/emhttp/plugins/zfs.snapshot.manager/event/started

if ! /usr/bin/php /usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/apply-cron.php; then
  echo "Warning: Unraid cron entries could not be refreshed. They will be retried after the array starts."
fi

if ! /usr/bin/php /usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/apply-shares.php; then
  echo "Warning: Snapshot shares could not be refreshed. They will be retried after the array starts."
fi

echo "ZFS Snapshot Manager $Version installed."
echo "Configure it under Settings > User Utilities > ZFS Snapshot Manager."
echo "Manage snapshots from the top-level Snapshots tab."
]]></INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE><![CDATA[
/usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager remove-samba-config || echo "Warning: Snapshot shares could not be removed from Samba."
removepkg zfs.snapshot.manager-$Version-noarch-1 2>/dev/null || true
rm -rf /usr/local/emhttp/plugins/zfs.snapshot.manager
rm -rf /boot/config/plugins/zfs.snapshot.manager
rm -f /var/log/zfs.snapshot.manager.log
rm -f /var/run/zfs.snapshot.manager.lock
rm -f /var/run/zfs.snapshot.manager.cron.lock
rm -f /var/run/zfs.snapshot.manager.policies.lock
rm -f /var/run/zfs.snapshot.manager.shares.lock
rm -f /var/run/zfs.snapshot.manager.samba.lock
if [[ -x /usr/local/sbin/update_cron ]]; then
  /usr/local/sbin/update_cron || true
fi
echo "ZFS Snapshot Manager removed. Existing ZFS snapshots were not changed."
]]></INLINE>
</FILE>
</PLUGIN>
"@

Set-Content -LiteralPath $manifestPath -Value $manifest -Encoding utf8

[xml](Get-Content -Raw -LiteralPath $manifestPath) | Out-Null

$archiveEntries = & tar.exe -tf $packagePath
if ($LASTEXITCODE -ne 0) {
    throw 'Das erstellte Paket kann nicht gelesen werden.'
}

$requiredEntries = @(
    './usr/local/emhttp/plugins/zfs.snapshot.manager/ZFSSnapshotManager.page',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/Snapshots.page',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/SnapshotManager.page',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/include/action.php',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/include/lib.php',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/include/policies.php',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/include/shares.php',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/include/settings.php',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/policy-runner.php',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/apply-cron.php',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/apply-shares.php',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/event/started',
    './install/slack-desc'
)
foreach ($entry in $requiredEntries) {
    if ($archiveEntries -notcontains $entry) {
        throw "Paketprüfung fehlgeschlagen. Eintrag fehlt: $entry"
    }
}

Remove-Item -LiteralPath $buildRoot -Recurse -Force

Write-Host "Build erfolgreich:"
Write-Host "  Paket:   $packagePath"
Write-Host "  Manifest: $manifestPath"
Write-Host "  SHA-256: $sha256"

