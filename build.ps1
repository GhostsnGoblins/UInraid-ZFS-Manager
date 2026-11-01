[CmdletBinding()]
param(
    [string]$Version = '0.2.2',
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
zfs.snapshot.manager: This test version contains no destructive ZFS action.
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
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="$manifestUrl" launch="Settings/ZFSSnapshotManager" min="6.12.0" icon="fa-history">
<CHANGES><![CDATA[
### $Version

- Uses GitHub as the public Unraid update channel
- Adds a top-level Snapshots tab beside Docker and VMs
- Keeps only the enable/disable switch under Settings
- Blocks write actions while the plugin is disabled
- Preserves all existing snapshots when disabled
]]></CHANGES>

<FILE Name="/boot/config/plugins/zfs.snapshot.manager/packages/$packageName">
<URL>$packageUrl</URL>
<SHA256>$sha256</SHA256>
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

chown -R root:root /usr/local/emhttp/plugins/zfs.snapshot.manager
find /usr/local/emhttp/plugins/zfs.snapshot.manager -type d -exec chmod 755 {} \;
find /usr/local/emhttp/plugins/zfs.snapshot.manager -type f -exec chmod 644 {} \;
chmod 755 /usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager

echo "ZFS Snapshot Manager $Version installed."
echo "Configure it under Settings > User Utilities > ZFS Snapshot Manager."
echo "Manage snapshots from the top-level Snapshots tab."
]]></INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE><![CDATA[
removepkg zfs.snapshot.manager-$Version-noarch-1 2>/dev/null || true
rm -rf /usr/local/emhttp/plugins/zfs.snapshot.manager
rm -rf /boot/config/plugins/zfs.snapshot.manager
rm -f /var/log/zfs.snapshot.manager.log
rm -f /var/run/zfs.snapshot.manager.lock
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
    './usr/local/emhttp/plugins/zfs.snapshot.manager/include/settings.php',
    './usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager',
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

