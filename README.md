# Unraid ZFS Snapshot Manager

Dies ist eine kleine, bewusst begrenzte Testversion eines nativen Unraid-Plugins.

## Funktionsumfang 0.8.0

- eigener Hauptreiter `Snapshots` neben Docker und VMs
- Ein/Aus-Schalter unter `Settings > User Utilities > ZFS Snapshot Manager`
- ZFS-Dateisysteme und ZVOLs auflisten
- vorhandene ZFS-Snapshots auflisten
- einen manuellen Snapshot für ein ausgewähltes Dataset erstellen
- pro Dataset einen automatischen Zeitplan einrichten
- Unter-Datasets optional rekursiv in dieselbe Regel einbeziehen
- betroffene Datasets vor dem Speichern anzeigen und überlappende Regeln verhindern
- stündliche, tägliche oder wöchentliche Ausführung mit auswählbarer Minute, Uhrzeit und Wochentag
- automatische Snapshots für eine frei wählbare Anzahl Stunden, Tage, Wochen oder Monate aufbewahren
- abgelaufene automatische Snapshots spätestens bei der nächsten 15-Minuten-Prüfung entfernen
- einen Zeitplan sofort ausführen und die aktuell fälligen Löschungen vorher anzeigen
- ausschließlich eindeutig gekennzeichnete manuelle Plugin-Snapshots löschen
- fremde Snapshots nach einer Sicherheitsabfrage löschen
- verwaiste VM-Snapshots in der Snapshot-Tabelle kennzeichnen
- Löschvorgänge bei vorhandenen Klonen oder ZFS-Holds blockieren
- Aktionen in Syslog und `/var/log/zfs.snapshot.manager.log` protokollieren
- pro Dataset eine private, schreibgeschützte Snapshot-Freigabe für ausgewählte Benutzer einrichten
- Unraid-Array-Zustand verständlich anzeigen
- ZFS-Pool-Zustand und letzten beziehungsweise laufenden Scrub in einem eigenen, rein lesenden Tab anzeigen
- Daten nach dem Array-Start manuell aktualisieren

Diese Version verändert keine Docker- oder VM-Konfiguration. Samba wird nur für eingerichtete Snapshot-Freigaben ergänzt, normale Unraid-Freigaben bleiben unverändert. Das Löschen verwendet keine rekursiven, erzwungenen oder verzögerten ZFS-Optionen.

Für die Ablaufberechnung entspricht ein Monat genau 30 Tagen. Änderungen an Rhythmus und Aufbewahrung behalten die stabile ID der Regel bei. Dadurch bleiben bereits erstellte automatische Snapshots derselben Regel zugeordnet.

Beim Ausschalten verschwindet der Hauptreiter nach dem Neuladen der WebGUI. Das Backend verweigert dann neue Snapshot-Aktionen. Bereits vorhandene ZFS-Snapshots werden weder gelöscht noch verändert.

Manuelle Snapshots erhalten einen Namen wie `uzm_manual_20261101T120000Z` sowie die ZFS-Benutzereigenschaft `org.unraidzfsmanager:kind=manual`. Automatische Snapshots enthalten zusätzlich die stabile ID ihrer Regel im Namen und in `org.unraidzfsmanager:policy`. Die automatische Bereinigung verlangt alle Kennzeichen in exakter Übereinstimmung. Manuelle und fremde Snapshots werden nicht erfasst.

## Snapshot-Freigaben

Pro Dataset lässt sich eine SMB-Freigabe einrichten, zum Beispiel `\\Tower\snapshot-data` für `disk1/data`. Jeder Snapshot erscheint dort automatisch als Ordner, auch manuelle und fremde. Neue Snapshots sind sofort sichtbar, gelöschte verschwinden sofort.

- Die Freigabe ist immer privat und schreibgeschützt.
- Mindestens ein Unraid-Benutzer mit Passwort muss ausgewählt werden. `root` und Konten ohne Passwort sind ausgeschlossen.
- Die Rechte der normalen Freigabe werden nicht übernommen. Wer Zugriff erhält, kann auch inzwischen gelöschte Dateien lesen.
- Unter-Datasets sind nicht enthalten und brauchen eine eigene Freigabe.
- Symlinks, die aus der Freigabe hinausführen, werden nicht verfolgt.
- Die Konfiguration liegt in `/boot/config/plugins/zfs.snapshot.manager/smb-snapshots.conf` und wird über einen markierten Block in `/boot/config/smb-extra.conf` eingebunden. Ohne Freigaben, beim Deaktivieren und beim Entfernen des Plugins wird der Block wieder entfernt.

## Windows-Vorgängerversionen

Die erstellten ZFS-Snapshots werden noch nicht als Windows-Vorgängerversionen veröffentlicht. Dafür muss eine SMB-Freigabe über Sambas `shadow_copy2` eindeutig einem ZFS-Dataset und dessen `.zfs/snapshot`-Verzeichnis zugeordnet werden. Unraid-User-Shares unter `/mnt/user` können Daten mehrerer Datenträger zusammenführen und dürfen deshalb nicht automatisch wie ein einzelnes Dataset behandelt werden. Die Snapshot-Freigaben sind davon unabhängig. Siehe die [offizielle Samba-Dokumentation zu `vfs_shadow_copy2`](https://www.samba.org/samba/samba/docs/man/manpages/vfs_shadow_copy2.8.html).

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

- `zfs.snapshot.manager-0.8.0-noarch-1.txz`
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

Die alten lokalen Testmanifeste besitzen noch keine kanonisch deklarierte Update-URL. Entferne deshalb einmalig die alte Testinstallation unter `Plugins` und installiere anschließend die aktuelle Version über die oben genannte GitHub-URL. Bereits vorhandene ZFS-Snapshots werden bei der Deinstallation nicht verändert.

## Updates

Nach der Installation über GitHub werden neue Versionen unter `Plugins > Check for Updates` erkannt und über die normale Unraid-GUI installiert. Manuelle `upgradepkg`-Befehle sind dann nicht mehr erforderlich.

## Schutz der öffentlichen Git-Historie

Dieses Repository erlaubt ausschließlich die GitHub-Noreply-Adresse `61009679+GhostsnGoblins@users.noreply.github.com` in Autor- und Committer-Metadaten. Nach einem neuen Clone wird der versionierte Schutz einmalig aktiviert:

```powershell
git config core.hooksPath .githooks
```

Der Pre-Commit-Hook prüft die lokale Identität. Der Pre-Push-Hook kontrolliert zusätzlich die vollständige Historie jedes zu veröffentlichenden Branches. GitHub Actions wiederholt die Prüfung nach jedem Push und bei Pull Requests.

## Deinstallation

Das Plugin kann in der Unraid-WebGUI unter `Plugins` entfernt werden. Dabei werden die installierte WebGUI-Integration, das Backend, das Testpaket und das Laufzeitprotokoll entfernt. Bereits angelegte ZFS-Snapshots bleiben erhalten.

## Angelegte und veränderte Pfade

| Pfad | Zweck | Dauerhaft |
| --- | --- | --- |
| `/boot/config/plugins/zfs.snapshot.manager.plg` | Plugin-Manifest | Ja |
| `/boot/config/plugins/zfs.snapshot.manager/packages/` | heruntergeladenes, geprüftes Plugin-Paket | Ja |
| `/boot/config/plugins/zfs.snapshot.manager/zfs.snapshot.manager.cfg` | Aktivierungszustand | Ja |
| `/boot/config/plugins/zfs.snapshot.manager/policies.json` | Zeitpläne und Aufbewahrungsdauer | Ja |
| `/boot/config/plugins/zfs.snapshot.manager/zfs.snapshot.manager.cron` | von Unraid eingelesene Cron-Zeitpläne | Ja |
| `/usr/local/emhttp/plugins/zfs.snapshot.manager/` | WebGUI und Backend | Nein, wird beim Start erneut installiert |
| `/var/log/zfs.snapshot.manager.log` | Laufzeitprotokoll | Nein |
| `/var/run/zfs.snapshot.manager.lock` | Sperre gegen parallele Schreibvorgänge | Nein |

## Sicherheitsgrenzen dieser Version

- Dataset-Namen werden gegen eine konservative Positivliste geprüft.
- Snapshot-Namen werden ausschließlich vom Backend erzeugt.
- PHP startet das Backend ohne Shell-Interpolation.
- Ein exklusives Lock verhindert parallele Schreibaktionen durch das Plugin.
- Die Oberfläche sendet den von Unraid bereitgestellten CSRF-Token mit.
- Der Aktivierungszustand wird serverseitig geprüft. Ein ausgeblendeter Reiter allein gilt nicht als Schutz.
- Eigene Plugin-Snapshots werden über Namensschema und lokal gesetzte Plugin-Eigenschaft erkannt.
- Automatische Löschungen verlangen zusätzlich die exakte stabile Regel-ID im Namen und in einer zweiten lokal gesetzten ZFS-Eigenschaft.
- Vor der Bereinigung einer rekursiven Snapshot-Gruppe werden Eigentum, Klone und Holds aller fälligen Mitglieder geprüft.
- Die nicht destruktive Löschvorschau zeigt nur bereits abgelaufene Snapshots derselben Regel.
- Cron ruft nur die stabile Regel-ID auf. Dataset und Aufbewahrungsdauer werden bei jedem Lauf erneut aus der serverseitigen Konfiguration gelesen.
- Fremde Snapshots verlangen im Backend weiterhin den vollständigen Namen als Bestätigung, die Oberfläche übergibt ihn nach der Sicherheitsabfrage.
- Vor dem Löschen werden abhängige Klone und ZFS-Holds geprüft.
- Es existiert kein Rollback-, Clone- oder Mount-Endpunkt.

## Noch offen

- Test auf verschiedenen Unraid- und OpenZFS-Versionen
- paginierte oder gefilterte Snapshot-Liste für große Systeme
- Tests für Holds, Clones und Abhängigkeiten vor dem späteren Löschen
- automatisierte Erstellung von GitHub-Releases und Tags
- endgültige Open-Source-Lizenz

