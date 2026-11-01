# Changelog

## 0.2.4

- `pluginURL`, Paket-URL und SHA-256 als kanonische PLG-Entities deklarieren
- Kompatibilität mit der Unraid-Updateprüfung herstellen

## 0.2.3

- Einstellungsseite auf das native Unraid-Formularraster umstellen
- Auswahlfeld auf die übliche Einstellungsbreite begrenzen
- Doppelte Seitenüberschrift entfernen
- Schaltfläche `Anwenden` erst nach einer Änderung aktivieren

## 0.2.2

- Öffentlichen Updatekanal von Gitea auf GitHub umstellen
- Installations- und Paket-URLs auf `raw.githubusercontent.com` ändern
- Lokale, repositoryübergreifende Git-Helferskripte von der Versionierung ausschließen

## 0.2.1

- Gitea als dauerhaften Unraid-Updatekanal einrichten
- Manifest und TXZ-Paket automatisch über HTTPS laden und per SHA-256 prüfen
- Release-Artefakte bewusst unter `dist/` versionieren
- `.gitignore` für Windows, IDEs, Tests, temporäre Dateien und Geheimnisse härten

## 0.2.0

- Eigenen Hauptreiter `Snapshots` zwischen Docker und VMs ergänzen
- Einstellungsseite auf einen Ein/Aus-Schalter reduzieren
- Hauptreiter im deaktivierten Zustand ausblenden
- Snapshot-Erstellung im deaktivierten Zustand serverseitig sperren
- Vorhandene Snapshots beim Ausschalten unverändert lassen
- Lokalen Upgradepfad für Unraid 7.3.4 dokumentieren, da die Plugin-CLI eine erneute Installation desselben Manifests blockiert

## 0.1.1

- Array-Zustand aus der Unraid-WebGUI anzeigen
- Vor dem Array-Start eine eindeutige Hinweismeldung statt einer irreführenden leeren Ansicht darstellen
- Schaltfläche zum manuellen Aktualisieren ergänzen
- Versionsnummer zentral in der Oberfläche anzeigen

## 0.1.0

- Erste lokal installierbare Testversion
- Anzeige von ZFS-Datasets und Snapshots
- Sichere manuelle Snapshot-Erstellung mit automatischem Namen
- Keine destruktiven ZFS-Operationen

