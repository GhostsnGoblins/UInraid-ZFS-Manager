[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

& (Join-Path $projectRoot 'build.ps1') | Out-Host

$manifestPath = Join-Path $projectRoot 'dist\zfs.snapshot.manager.plg'
$packagePath = Join-Path $projectRoot 'dist\zfs.snapshot.manager-0.8.0-noarch-1.txz'
$backendPath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\scripts\zfs-manager'
$actionPath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\include\action.php'
$libraryPath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\include\lib.php'
$settingsEndpointPath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\include\settings.php'
$policiesEndpointPath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\include\policies.php'
$policyRunnerPath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\scripts\policy-runner.php'
$eventStartedPath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\event\started'

[xml]$manifest = Get-Content -Raw -LiteralPath $manifestPath
if ($manifest.PLUGIN.name -ne 'zfs.snapshot.manager') {
    throw 'Manifest enthält einen unerwarteten Plugin-Namen.'
}
if (-not (Test-Path -LiteralPath $packagePath -PathType Leaf)) {
    throw 'Build hat kein Paket erzeugt.'
}
$packages = @(Get-ChildItem -LiteralPath (Join-Path $projectRoot 'dist') -Filter 'zfs.snapshot.manager-*-noarch-1.txz' -File)
if ($packages.Count -ne 1) {
    throw 'Dist enthält veraltete oder mehrere TXZ-Pakete.'
}

$library = Get-Content -Raw -LiteralPath $libraryPath
if ($library -notmatch "const ZSM_VERSION = '0.8.0';") {
    throw 'Die in der WebGUI sichtbare Plugin-Version ist nicht 0.8.0.'
}
if ($library -notmatch 'function zsm_is_enabled') {
    throw 'Die Bibliothek enthält keine zentrale Aktivierungsprüfung.'
}
if ($library -notmatch 'function zsm_apply_cron' -or $library -notmatch 'function zsm_policy_retention_seconds') {
    throw 'Die Bibliothek enthält keine zentrale Zeitplan- und Aufbewahrungslogik.'
}
if ($library -notmatch 'function zsm_policy_scopes_overlap' -or $library -notmatch "'recursive' =>") {
    throw 'Die Bibliothek enthält keine sichere rekursive Regelverwaltung.'
}
if ($library -notmatch "zsm_run\(\['refresh-cron'\]") {
    throw 'Cron-Aktualisierung wird nicht über das installierte Backend ausgeführt.'
}

$backend = Get-Content -Raw -LiteralPath $backendPath
if ($backend -notmatch 'flock -n') {
    throw 'Backend enthält keine exklusive Schreibsperre.'
}
if ($backend -notmatch '"\$zfs_bin" destroy "\$snapshot"') {
    throw 'Backend enthält keinen klar begrenzten Snapshot-Löschaufruf.'
}
if ($backend -match 'destroy\s+-[A-Za-z]*[rRf]' -or $backend -match 'zfs rollback|zfs clone') {
    throw 'Backend enthält eine rekursive, erzwungene oder anderweitig unerlaubte ZFS-Aktion.'
}
if ($backend -notmatch 'value,source' -or
    -not $backend.Contains('plugin_snapshot_metadata_matches') -or
    -not $backend.Contains('"$kind_source" == "local"') -or
    -not $backend.Contains('"$policy_source" == "local"') -or
    $backend -notmatch 'holds -H') {
    throw 'Backend prüft Eigentumskennzeichnung und ZFS-Holds vor dem Löschen nicht vollständig.'
}
if ($backend -notmatch 'clones_present' -or $backend -notmatch 'holds_present') {
    throw 'Backend protokolliert blockierte Löschungen nicht nachvollziehbar.'
}
if ($backend -notmatch 'org\.unraidzfsmanager:kind') {
    throw 'Backend kennzeichnet erstellte Snapshots nicht.'
}
if ($backend -notmatch 'org\.unraidzfsmanager:policy' -or $backend -notmatch 'cleanup-policy' -or $backend -notmatch 'preview-policy') {
    throw 'Backend enthält keine regelgebundene automatische Aufbewahrung.'
}
if ($backend -notmatch 'refresh_cron' -or $backend -notmatch 'UPDATE_CRON_BIN') {
    throw 'Backend enthält keinen festen Unraid-update_cron-Aufruf.'
}
if ($backend -notmatch 'lock_snapshot_action 60' -or $backend -notmatch 'flock -w "\$wait_seconds" 9') {
    throw 'Geplante Snapshot-Läufe warten nicht kontrolliert auf die gemeinsame Aktionssperre.'
}
if ($backend -notmatch 'uzm_auto_\$\{policy_id\}' -or $backend -notmatch 'mode=retention') {
    throw 'Automatische Snapshots sind nicht eindeutig an ihre Regel gebunden.'
}
if ($backend -notmatch 'list-pools' -or $backend -notmatch 'status -p "\$name"' -or $backend -match '"\$zpool_bin"\s+scrub') {
    throw 'Pool-Status fehlt oder die reine Statusversion enthält eine schreibende Scrub-Aktion.'
}
if ($backend -notmatch 'snapshot_arguments\+=\(-r\)' -or $backend -notmatch 'mapfile -t candidates') {
    throw 'Rekursive Erstellung oder vollständige Löschvorprüfung fehlt.'
}

$action = Get-Content -Raw -LiteralPath $actionPath
if ($action -notmatch "\['create', \`$dataset\]") {
    throw 'PHP-Endpunkt ruft nicht den erwarteten festen Backend-Befehl auf.'
}
if ($action -notmatch "\['delete', \`$snapshot\]") {
    throw 'PHP-Endpunkt ruft den Löschbefehl nicht mit einem festen Argumentvektor auf.'
}
if ($action -notmatch "\['delete-external', \`$snapshot, \`$confirmation\]") {
    throw 'PHP-Endpunkt übergibt die externe Löschbestätigung nicht als festen Argumentvektor.'
}
if ($action -notmatch 'hash_equals\(\$snapshot, \$confirmation\)') {
    throw 'PHP-Endpunkt prüft die eingegebene Bestätigung nicht exakt.'
}
if ($action -notmatch 'zsm_is_enabled\(\)') {
    throw 'PHP-Endpunkt sperrt Schreibaktionen im deaktivierten Zustand nicht.'
}

$pagePath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\SnapshotManager.page'
$page = Get-Content -Raw -LiteralPath $pagePath
if ($page -match "require_once __DIR__") {
    throw 'Page-Datei darf wegen der Unraid-Auswertung nicht auf __DIR__ vertrauen.'
}
if ($page -notmatch '\$docroot/plugins/zfs\.snapshot\.manager/include/lib\.php') {
    throw 'Page-Datei bindet die Bibliothek nicht über den Unraid-Docroot ein.'
}
if ($page -notmatch "mdState") {
    throw 'Page-Datei wertet den Unraid-Array-Zustand nicht aus.'
}
if ($page -notmatch "window\.location\.reload") {
    throw 'Page-Datei enthält keine Aktualisieren-Funktion.'
}
if ($page -notmatch 'window\.confirm' -or $page -notmatch "external \? 'delete-external' : 'delete'") {
    throw 'Page-Datei enthält keine bestätigte Snapshot-Löschaktion.'
}
if ($page -notmatch "\`$snapshot\['owned'\] === '1'") {
    throw 'Page-Datei verwendet nicht die serverseitig geprüfte Snapshot-Zuordnung.'
}
if ($page -match 'window\.prompt' -or $page -notmatch 'requestData\.confirmation = snapshot') {
    throw 'Fremde Snapshots verwenden nicht die einfache Sicherheitsabfrage.'
}
if ($page -notmatch 'zsm_find_vm_orphans' -or $page -notmatch 'zsm_vm_orphan_hint') {
    throw 'Snapshot-Tabelle kennzeichnet keine verwaisten VM-Snapshots.'
}
if ($page -notmatch 'data-zsm-panel="policies"' -or $page -notmatch 'data-zsm-panel="shares"') {
    throw 'Zeitpläne und Freigaben liegen nicht in eigenen Tabs.'
}
if ($page -notmatch 'data-zsm-panel="pools"' -or $page -notmatch "zsm_run\(\['list-pools'\]\)" -or $page -notmatch 'Pools &amp; Scrub') {
    throw 'Pool- und Scrub-Status liegen nicht im vorgesehenen eigenen Tab.'
}
if ($page -notmatch '<details id="zsm-datasets">') {
    throw 'Datasets sind nicht einklappbar oder nicht standardmäßig eingeklappt.'
}
if ($page -notmatch 'data-sort="number">Nächster Lauf') {
    throw 'Zeitplan-Tabelle ist nicht sortierbar.'
}
if ($page -notmatch 'data-sort="number">Letzter Snapshot' -or $page -notmatch 'function sortTable') {
    throw 'Dataset-Tabelle ist nicht sortierbar oder zeigt kein Snapshot-Datum.'
}
if ($page -notmatch 'zsm-policy-form' -or $page -notmatch 'retention_value' -or $page -notmatch 'Löschvorschau') {
    throw 'Hauptseite enthält keine vollständige Zeitplan- und Aufbewahrungsverwaltung.'
}
if ($page -notmatch 'zsm-policy-recursive' -or $page -notmatch 'Betroffene Datasets') {
    throw 'Hauptseite zeigt keine rekursive Auswahl mit betroffenen Datasets.'
}

$settingsPagePath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\ZFSSnapshotManager.page'
$settingsPage = Get-Content -Raw -LiteralPath $settingsPagePath
if ($settingsPage -notmatch '^Menu="OtherSettings"') {
    throw 'Einstellungsseite ist nicht unter OtherSettings registriert.'
}
if ($settingsPage -match 'list-datasets|list-snapshots|Snapshot erstellen') {
    throw 'Einstellungsseite enthält weiterhin Snapshot-Verwaltung.'
}
if ($settingsPage -notmatch '<form markdown="1"' -or $settingsPage -notmatch ': <select id="zsm-enabled"') {
    throw 'Einstellungsseite verwendet nicht das native Unraid-Formularraster.'
}
if ($settingsPage -match '<div class="title"') {
    throw 'Einstellungsseite enthält eine doppelte Seitenüberschrift.'
}

$topPagePath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\Snapshots.page'
$topPage = Get-Content -Raw -LiteralPath $topPagePath
if ($topPage -notmatch '^Menu="Tasks:65"' -or $topPage -notmatch 'Type="xmenu"') {
    throw 'Snapshots ist nicht als eigener Hauptreiter registriert.'
}

$expectedManifestUrl = 'https://raw.githubusercontent.com/GhostsnGoblins/UInraid-ZFS-Manager/main/dist/zfs.snapshot.manager.plg'
if ($manifest.PLUGIN.pluginURL -ne $expectedManifestUrl) {
    throw 'Manifest enthält nicht den erwarteten GitHub-Updatekanal.'
}
$downloadFile = @($manifest.PLUGIN.FILE | Where-Object { $_.Name -like '*zfs.snapshot.manager-0.8.0-noarch-1.txz' })
if ($downloadFile.Count -ne 1 -or -not $downloadFile[0].URL -or -not $downloadFile[0].SHA256) {
    throw 'Manifest enthält keinen eindeutig geprüften Paketdownload.'
}
$manifestText = Get-Content -Raw -LiteralPath $manifestPath
if ($manifestText -notmatch '<!ENTITY pluginURL "https://raw\.githubusercontent\.com/' -or
    $manifestText -notmatch 'pluginURL="&pluginURL;"' -or
    $manifestText -notmatch '<URL>&packageURL;</URL>' -or
    $manifestText -notmatch '<SHA256>&packageSHA256;</SHA256>') {
    throw 'Manifest deklariert die Update-Metadaten nicht als kanonische PLG-Entities.'
}
if ($topPage -notmatch 'zfs\.snapshot\.manager\.cfg') {
    throw 'Der Hauptreiter berücksichtigt die Aktivierungseinstellung nicht.'
}

$settingsEndpoint = Get-Content -Raw -LiteralPath $settingsEndpointPath
if ($settingsEndpoint -notmatch "in_array\(\`$enabled, \['yes', 'no'\], true\)") {
    throw 'Einstellungs-Endpunkt validiert den Schalter nicht strikt.'
}
if ($settingsEndpoint -match 'shell_exec|exec\s*\(|system\s*\(|passthru\s*\(') {
    throw 'Einstellungs-Endpunkt darf keine Shell-Ausführungsfunktion verwenden.'
}
if ($settingsEndpoint -notmatch 'zsm_apply_cron\(\)') {
    throw 'Ein/Aus-Schalter aktualisiert die Unraid-Cron-Einträge nicht.'
}
if ($action -match 'shell_exec|exec\s*\(|system\s*\(|passthru\s*\(') {
    throw 'PHP-Endpunkt darf keine Shell-Ausführungsfunktion verwenden.'
}

$policiesEndpoint = Get-Content -Raw -LiteralPath $policiesEndpointPath
if ($policiesEndpoint -notmatch "'run-policy'" -or $policiesEndpoint -notmatch "\['preview-policy',") {
    throw 'Zeitplan-Endpunkt verwendet nicht die fest definierten Backend-Aktionen.'
}
if ($policiesEndpoint -match 'shell_exec|exec\s*\(|system\s*\(|passthru\s*\(') {
    throw 'Zeitplan-Endpunkt darf keine Shell-Ausführungsfunktion verwenden.'
}

$policyRunner = Get-Content -Raw -LiteralPath $policyRunnerPath
if ($policyRunner -notmatch "'cleanup-policy'" -or $policyRunner -notmatch 'zsm_find_policy') {
    throw 'Cron-Runner löst Regeln nicht anhand ihrer stabilen ID auf.'
}
$eventStarted = Get-Content -Raw -LiteralPath $eventStartedPath
if ($eventStarted -notmatch 'apply-cron\.php') {
    throw 'Array-Start stellt die Cron-Zeitpläne nicht wieder her.'
}

$sharesEndpointPath = Join-Path $projectRoot 'src\usr\local\emhttp\plugins\zfs.snapshot.manager\include\shares.php'
$sharesEndpoint = Get-Content -Raw -LiteralPath $sharesEndpointPath
if ($sharesEndpoint -match 'shell_exec|exec\s*\(|system\s*\(|passthru\s*\(') {
    throw 'Freigabe-Endpunkt darf keine Shell-Ausführungsfunktion verwenden.'
}
if ($sharesEndpoint -notmatch 'zsm_is_enabled\(\)' -or $sharesEndpoint -notmatch 'zsm_save_shares\(\$previousShares\)') {
    throw 'Freigabe-Endpunkt sperrt im deaktivierten Zustand nicht oder stellt nach Fehlern nicht wieder her.'
}
if ($library -notmatch 'read only = yes' -or $library -notmatch 'guest ok = no' -or $library -notmatch 'wide links = no') {
    throw 'Snapshot-Freigaben sind nicht fest privat, schreibgeschützt und ohne wide links.'
}
if ($library -match 'guest ok = yes|read only = no|writeable|writable') {
    throw 'Snapshot-Freigaben dürfen weder Gäste noch Schreibzugriff erlauben.'
}
if ($backend -notmatch 'testparm -s' -or $backend -notmatch 'close-share') {
    throw 'Backend prüft die Samba-Konfiguration nicht oder trennt bestehende Verbindungen nicht.'
}
if ($manifestText -notmatch 'remove-samba-config' -or $manifestText -notmatch 'apply-shares\.php') {
    throw 'Manifest behandelt Snapshot-Freigaben bei Installation oder Entfernen nicht.'
}
if ($eventStarted -notmatch 'apply-shares\.php') {
    throw 'Array-Start stellt die Snapshot-Freigaben nicht wieder her.'
}
if ($page -notmatch 'zsm-share-form' -or $page -notmatch 'zsm-share-user') {
    throw 'Hauptseite enthält keine Verwaltung der Snapshot-Freigaben.'
}

$linuxFiles = Get-ChildItem -LiteralPath (Join-Path $projectRoot 'src') -Recurse -File
foreach ($file in $linuxFiles) {
    $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
    for ($index = 0; $index -lt ($bytes.Length - 1); $index++) {
        if ($bytes[$index] -eq 13 -and $bytes[$index + 1] -eq 10) {
            throw "Linux-Datei enthält CRLF-Zeilenenden: $($file.FullName)"
        }
    }
}

Write-Host 'Alle statischen Tests erfolgreich.'
