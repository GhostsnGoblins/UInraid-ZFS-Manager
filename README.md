# Unraid ZFS Snapshot Manager

Dies ist eine kleine, bewusst begrenzte Testversion eines nativen Unraid-Plugins.

## Funktionsumfang 0.2.4

- eigener Hauptreiter `Snapshots` neben Docker und VMs
- Ein/Aus-Schalter unter `Settings > User Utilities > ZFS Snapshot Manager`
- ZFS-Dateisysteme und ZVOLs auflisten
- vorhandene ZFS-Snapshots auflisten
- einen manuellen Snapshot für ein ausgewähltes Dataset erstellen
- Aktionen in Syslog und `/var/log/zfs.snapshot.manager.log` protokollieren
- Unraid-Array-Zustand verständlich anzeigen
- Daten nach dem Array-Start manuell aktualisieren

Diese Version löscht keine Snapshots, richtet keine Zeitpläne ein und verändert keine Samba-, Docker- oder VM-Konfiguration.

Beim Ausschalten verschwindet der Hauptreiter nach dem Neuladen der WebGUI. Das Backend verweigert dann neue Snapshot-Aktionen. Bereits vorhandene ZFS-Snapshots werden weder gelöscht noch verändert.

Neu erstellte Snapshots erhalten automatisch einen Namen wie `uzm_manual_20261101T120000Z` sowie die ZFS-Benutzereigenschaft `org.unraidzfsmanager:kind=manual`. Diese Kennzeichnung ist für spätere, sichere Aufbewahrungsregeln vorgesehen. Manuelle Snapshots dürfen von einer späteren automatischen Bereinigung nicht erfasst werden.

## Voraussetzungen

- Unraid 6.12 oder neuer
- ein vorhandener ZFS-Pool
- Zugriff als `root` für Installation und Test
- Windows PowerShell 5.1 oder PowerShell 7 zum lokalen Bauen

## Paket bauen

Im Projektordner:

```powershell
.\build.ps1
```

Die fertigen Testdateien liegen danach in `dist`:

- `zfs.snapshot.manager-0.2.4-noarch-1.txz`
- `zfs.snapshot.manager.plg`

## Auf einem Testsystem installieren

Nutze ausschließlich einen Testserver oder einen entbehrlichen Test-Pool.

1. Öffne in Unraid `Plugins > Install Plugin`.
2. Trage diese URL ein:

```text
https://raw.githubusercontent.com/GhostsnGoblins/UInraid-ZFS-Manager/main/dist/zfs.snapshot.manager.plg
```

3. Installiere das Plugin.
4. Öffne `Settings > User Utilities > ZFS Snapshot Manager` und aktiviere das Plugin.
5. Verwalte die Snapshots über den Hauptreiter `Snapshots`.

Das Manifest lädt das zur Version passende TXZ-Paket automatisch von GitHub und verifiziert dessen SHA-256-Prüfsumme.

### Einmalige Migration von einer lokalen Testinstallation

Die alten lokalen Testmanifeste besitzen noch keine kanonisch deklarierte Update-URL. Entferne deshalb einmalig die alte Testinstallation unter `Plugins` und installiere anschließend Version 0.2.4 über die oben genannte GitHub-URL. Bereits vorhandene ZFS-Snapshots werden bei der Deinstallation nicht verändert.

## Updates

Nach der Installation über GitHub werden neue Versionen unter `Plugins > Check for Updates` erkannt und über die normale Unraid-GUI installiert. Manuelle `upgradepkg`-Befehle sind dann nicht mehr erforderlich.

## Deinstallation

Das Plugin kann in der Unraid-WebGUI unter `Plugins` entfernt werden. Dabei werden die installierte WebGUI-Integration, das Backend, das Testpaket und das Laufzeitprotokoll entfernt. Bereits angelegte ZFS-Snapshots bleiben erhalten.

## Angelegte und veränderte Pfade

| Pfad | Zweck | Dauerhaft |
| --- | --- | --- |
| `/boot/config/plugins/zfs.snapshot.manager.plg` | Plugin-Manifest | Ja |
| `/boot/config/plugins/zfs.snapshot.manager/packages/` | heruntergeladenes, geprüftes Plugin-Paket | Ja |
| `/boot/config/plugins/zfs.snapshot.manager/zfs.snapshot.manager.cfg` | Aktivierungszustand | Ja |
| `/usr/local/emhttp/plugins/zfs.snapshot.manager/` | WebGUI und Backend | Nein, wird beim Start erneut installiert |
| `/var/log/zfs.snapshot.manager.log` | Laufzeitprotokoll | Nein |
| `/var/run/zfs.snapshot.manager.lock` | Sperre gegen parallele Schreibvorgänge | Nein |

## Sicherheitsgrenzen dieser Version

- Dataset-Namen werden gegen eine konservative Positivliste geprüft.
- Snapshot-Namen werden ausschließlich vom Backend erzeugt.
- PHP startet das Backend ohne Shell-Interpolation.
- Ein exklusives Lock verhindert parallele Snapshot-Erstellungen durch das Plugin.
- Die Oberfläche sendet den von Unraid bereitgestellten CSRF-Token mit.
- Der Aktivierungszustand wird serverseitig geprüft. Ein ausgeblendeter Reiter allein gilt nicht als Schutz.
- Es existiert kein Lösch-, Rollback-, Clone-, Mount- oder Retention-Endpunkt.

## Noch offen

- Test auf verschiedenen Unraid- und OpenZFS-Versionen
- paginierte oder gefilterte Snapshot-Liste für große Systeme
- Tests für Holds, Clones und Abhängigkeiten vor dem späteren Löschen
- automatisierte Erstellung von GitHub-Releases und Tags
- endgültige Open-Source-Lizenz

