# Changelog

## 0.7.1

- neue Snapshot-Freigaben standardmäßig als `snapshot-<dataset>` vorschlagen

## 0.7.0

- pro Dataset eine private, schreibgeschützte SMB-Freigabe auf `.zfs/snapshot` einrichten
- alle Snapshots automatisch und ohne Samba-Neuladen sichtbar machen
- Zugriff nur für explizit ausgewählte Unraid-Benutzer mit Passwort erlauben
- Freigaben über die ZFS-GUID binden, damit sie Dataset-Umbenennungen überstehen
- `wide links` je Freigabe abschalten und Konfiguration vor dem Aktivieren mit `testparm` prüfen
- bestehende Verbindungen bei Änderungen trennen und alles beim Deaktivieren oder Entfernen zurücknehmen

## 0.6.1

- geplante Snapshot-Läufe bei gleichzeitiger 15-Minuten-Bereinigung nicht mehr sofort abbrechen
- geplante Läufe bis zu 60 Sekunden kontrolliert auf die gemeinsame ZFS-Aktionssperre warten lassen
- Zeitlimit des geplanten Runners passend zur Wartezeit auf 180 Sekunden erhöhen

## 0.6.0

- Unter-Datasets optional rekursiv in eine automatische Regel einbeziehen
- alle betroffenen Datasets vor dem Speichern in der WebGUI anzeigen
- überlappende rekursive und untergeordnete Regeln serverseitig verhindern
- vor rekursiver Bereinigung alle fälligen Snapshots auf Eigentum, Klone und Holds prüfen
- Tabellenlayout der Aktionsschaltflächen korrigieren
- bestehende Regeln aus 0.5.x nicht rekursiv und unverändert übernehmen

## 0.5.1

- Unraid `update_cron` über das installierte Bash-Backend statt direkt aus PHP starten
- bestehende automatische Regeln beim Update beibehalten und erneut aktivieren
- Cron-Aktualisierung mit eigener Sperre und Protokollierung absichern

## 0.5.0

- pro Dataset eine automatische Snapshot-Regel mit stabiler ID ergänzen
- stündliche, tägliche und wöchentliche Zeitpläne mit auswählbarem Zeitpunkt ergänzen
- altersbasierte Aufbewahrung in Stunden, Tagen, Wochen oder Monaten ergänzen
- Ablaufprüfung alle 15 Minuten über Unraid `update_cron` einrichten
- automatische Snapshots doppelt über Name und lokale ZFS-Regel-Eigenschaft absichern
- Regelbearbeitung ohne Änderung der stabilen ID ermöglichen
- sofortige Ausführung und nicht destruktive Löschvorschau ergänzen
- Cron-Einträge bei Installation, Ein/Aus-Umschaltung und Array-Start aktualisieren

## 0.4.0

- kontrolliertes Löschen fremder Snapshots ergänzen
- vollständigen Snapshot-Namen als zusätzliche Löschbestätigung verlangen
- Snapshot-Namen konservativ validieren und Mehrfachziele ausschließen
- bestehende Prüfungen auf Klone und ZFS-Holds für alle Löschpfade erzwingen
- Löschprotokoll um den Modus `plugin` oder `external` ergänzen

## 0.3.0

- sicheres Löschen manuell erstellter Plugin-Snapshots ergänzen
- serverseitig Name und lokale Plugin-Eigenschaft erneut validieren
- Löschung bei vorhandenen Klonen oder ZFS-Holds blockieren
- ausschließlich nicht-rekursives `zfs destroy` ohne Force-Option verwenden
- erfolgreiche, fehlgeschlagene und blockierte Löschversuche protokollieren

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

