# Projekt: Unraid ZFS Snapshot Manager

**Arbeitstitel:** Unraid ZFS Manager  
**Projekttyp:** Open-Source-Plugin für Unraid  
**Zielplattform:** Unraid mit OpenZFS-Unterstützung  
**Projektstatus:** Konzeptphase – Entwicklungsstart

## 1. Projektidee und Motivation

Wir entwickeln ein natives Unraid-Plugin, das eine vollständige, benutzerfreundliche Verwaltung von ZFS-Snapshots über die Unraid-Weboberfläche ermöglicht.

Unraid unterstützt ZFS, bietet jedoch nicht alle gewünschten Funktionen zur automatisierten Snapshot-Verwaltung und komfortablen Wiederherstellung direkt in seiner Benutzeroberfläche.

Andere NAS-Systeme wie TrueNAS bieten hierfür bereits integrierte Verwaltungsmöglichkeiten.

Unser Ziel ist es, diese Lücke zu schließen, ohne auf zusätzliche Snapshot-Engines wie Sanoid oder auf umfangreiche Community-Abhängigkeiten zurückgreifen zu müssen.

Das Plugin soll sich wie ein natürlicher Bestandteil von Unraid verhalten: installieren, konfigurieren, benutzen und bei Bedarf vollständig wieder entfernen.

Langfristig soll daraus ein ausgereiftes, sicheres und öffentlich nutzbares Open-Source-Projekt entstehen.

## 2. Grundprinzipien

### Native Unraid-Integration

- Installation als reguläres Unraid-Plugin über eine `.plg`-Datei.

- Integration in die bestehende Unraid-Weboberfläche.

- Nutzung der vorhandenen ZFS-Funktionen.

- Keine zusätzliche Docker-Umgebung.

- Kein eigener Webserver.

- Keine dauerhaft laufenden Hintergrunddienste, sofern technisch vermeidbar.

- Möglichst keine externen Abhängigkeiten.

### Minimale Systemeingriffe

Das Plugin soll möglichst wenig am Unraid-Betriebssystem verändern.

Vorhandene Konfigurationen dürfen nicht unkontrolliert überschrieben werden. Änderungen müssen dokumentiert, nachvollziehbar und rückgängig zu machen sein.

### Sicherheit und Zuverlässigkeit

Datensicherheit hat höchste Priorität. Das Plugin muss konservativ arbeiten und gefährliche Operationen absichern.

Insbesondere dürfen automatische Löschvorgänge niemals versehentlich fremde oder manuell erstellte Snapshots erfassen.

### Vollständige Deinstallierbarkeit

Das Plugin muss sauber entfernt werden können.

Bei der Deinstallation sollen sämtliche pluginbezogenen Prozesse, Zeitpläne und Integrationen entfernt werden. Nutzdaten und vorhandene ZFS-Snapshots bleiben grundsätzlich erhalten.

Es dürfen keine unnötigen Systemveränderungen oder verwaisten Dienste zurückbleiben.

## 3. Geplanter Funktionsumfang

### 3.1 Snapshot-Verwaltung

- Alle vorhandenen ZFS-Pools und Datasets erkennen.

- Bestehende Snapshots auflisten.

- Snapshots manuell erstellen.

- Snapshot-Namen und Erstellungszeit anzeigen.

- Belegten Speicherplatz darstellen.

- Snapshots gezielt löschen.

- Geschützte Snapshots berücksichtigen.

- Filtern und Sortieren nach Dataset, Datum und Name.

- Auch Snapshots anzeigen, die nicht durch unser Plugin erstellt wurden.

### 3.2 Automatische Snapshots

Für jedes Dataset können individuelle Snapshot-Zeitpläne eingerichtet werden.

Unterstützte Intervalle:

- Stündlich

- Täglich

- Wöchentlich

- Monatlich

- Benutzerdefinierte Zeitpläne

Die Konfiguration erfolgt vollständig über die Weboberfläche.

Zeitpläne werden über vorhandene Unraid-Mechanismen ausgeführt, vorzugsweise durch Cron.

Ein wichtiges Entwicklungsziel ist, dass Zeitpläne einen Neustart des Systems zuverlässig überstehen.

### 3.3 Aufbewahrungsregeln (Retention)

Snapshots sollen nach frei definierbaren Regeln automatisch bereinigt werden.

Beispiel:

| Snapshot-Typ | Aufbewahrung |
| ------------ | ------------ |
| Stündlich    | 24           |
| Täglich      | 7            |
| Wöchentlich  | 4            |
| Monatlich    | 6            |

Jedes Dataset kann eigene Regeln erhalten.

Wichtige Sicherheitsanforderungen:

- Ausschließlich eindeutig zugeordnete Snapshots automatisch löschen.

- Manuell erstellte Snapshots bleiben unangetastet.

- Vor einem Löschvorgang die betroffenen Snapshots ermitteln.

- Einen Testlauf ohne tatsächliche Löschung anbieten.

- Fehlgeschlagene Bereinigungen protokollieren.

- Snapshot-Abhängigkeiten, Klone und ZFS-Holds berücksichtigen.

### 3.4 Wiederherstellung

Ein zentraler Bestandteil des Projekts ist die einfache Wiederherstellung über die Weboberfläche.

Geplante Möglichkeiten:

**Datei- und Ordnerwiederherstellung**

- Snapshot auswählen.

- Gespeicherte Dateien und Ordner durchsuchen.

- Einzelne Dateien wiederherstellen.

- Ganze Verzeichnisse wiederherstellen.

- Wiederherstellung an einen alternativen Speicherort.

- Vorhandene Dateien nicht ohne ausdrückliche Bestätigung überschreiben.

**Dataset-Wiederherstellung**

- Vollständigen Zustand eines Datasets wiederherstellen.

- Auswirkungen vorab prüfen.

- Auf potenziellen Datenverlust ausdrücklich hinweisen.

- ZFS-Abhängigkeiten und nachfolgende Snapshots prüfen.

- Destruktive Operationen nur nach gesonderter Bestätigung durchführen.

Der sichere Standard soll die nicht-destruktive Wiederherstellung einzelner Dateien sein. Ein vollständiger Rollback darf nicht mit einer einfachen Dateiwiederherstellung verwechselt werden.

### 3.5 Windows-Vorgängerversionen

Seit Version 0.7.0 macht das Plugin Snapshots über eine private, schreibgeschützte SMB-Freigabe pro Dataset zugänglich. Eine spätere Funktion soll sie zusätzlich direkt in Windows-Vorgängerversionen anzeigen.

Hierfür wird die Integration über Sambas `shadow_copy2` geprüft.

Ziel ist, dass Benutzer im Windows-Explorer über:

**Rechtsklick → Eigenschaften → Vorgängerversionen**

frühere Dateiversionen anzeigen und wiederherstellen können.

Dabei müssen die bestehende Unraid-Samba-Konfiguration, die Berechtigungen und die Kompatibilität sorgfältig geprüft werden.

### 3.6 Docker und virtuelle Maschinen

Perspektivisch soll das Plugin auch für folgende Bereiche geeignet sein:

- Docker-Appdata

- Docker-Volumes auf ZFS

- Virtuelle Maschinen und deren virtuelle Datenträger

- Andere anwendungsbezogene ZFS-Datasets

Dabei muss zwischen einem technisch erfolgreichen Snapshot und einem anwendungskonsistenten Sicherungszustand unterschieden werden.

Die sichere Behandlung laufender VMs, Datenbanken und Container wird separat konzipiert.

## 4. Technische Architektur

Das Plugin soll möglichst schlank und modular aufgebaut werden.

### Backend

Die eigentliche Snapshot-Verwaltung verwendet vorhandene OpenZFS-Befehle:

- `zfs list`

- `zfs snapshot`

- `zfs destroy`

- `zfs get`

- `zfs holds`

- `zfs rollback`

- `zfs clone` (gegebenenfalls für spätere Wiederherstellungsfunktionen)

Die Befehle werden durch kontrollierte Skripte beziehungsweise Backend-Funktionen ausgeführt.

Eine zusätzliche Snapshot-Engine wird nicht eingesetzt.

### Frontend

Die Verwaltungsoberfläche wird in Unraids bestehende WebGUI integriert.

Die genaue Implementierung wird anhand der offiziellen Plugin-Mechanismen und der aktuellen Unraid-Version festgelegt.

Geplante Bereiche:

1. **Dashboard:** Übersicht über Datasets, Snapshots und Speicherbelegung.

2. **Snapshots:** Verwaltung vorhandener Sicherungsstände.

3. **Zeitpläne:** Einrichtung automatischer Snapshots.

4. **Wiederherstellung:** Dateien, Ordner und Datasets wiederherstellen.

5. **Einstellungen:** Allgemeine Konfiguration.

6. **Protokolle:** Aktivitäten, Warnungen und Fehler.

### Konfigurationsverwaltung

Die Konfiguration muss Updates und Systemneustarts überstehen.

Es wird geprüft, wie die persistenten Unraid-Konfigurationsmechanismen korrekt genutzt werden können.

Das Plugin soll vorhandene Systemeinstellungen nicht unkontrolliert verändern.

### Logging und Fehlerbehandlung

Alle wichtigen Aktionen werden nachvollziehbar protokolliert:

- Snapshot erstellt

- Snapshot gelöscht

- Zeitplan ausgeführt

- Bereinigung durchgeführt

- Wiederherstellung ausgeführt

- Fehler und Warnungen

Es muss möglich sein, die Ursache einer fehlgeschlagenen Aktion zu erkennen.

## 5. Sicherheitskonzept

Das Plugin führt teilweise privilegierte ZFS-Operationen aus. Deshalb ist eine besonders sorgfältige Absicherung erforderlich.

Verbindliche Anforderungen:

- Keine unkontrollierte Übergabe von Benutzereingaben an Shell-Befehle.

- Sichere Validierung von Pool-, Dataset- und Snapshot-Namen.

- Keine unbeabsichtigten rekursiven Löschoperationen.

- Explizite Absicherung destruktiver Aktionen.

- Sperrmechanismus gegen konkurrierende Snapshot- und Bereinigungsläufe.

- Sichere Behandlung von Fehlerzuständen und abgebrochenen Aktionen.

- Klare Trennung zwischen lesenden und verändernden Operationen.

- Keine versteckten oder undokumentierten Systemänderungen.

- Keine Manipulation fremder Snapshots durch automatische Retention.

Besonderes Augenmerk liegt auf bestehenden ZFS-Klonen, Snapshots mit Holds und Abhängigkeiten zwischen Datasets.

## 6. Installation, Updates und Deinstallation

Die Installation soll den normalen Unraid-Plugin-Mechanismus verwenden.

**Installation**

- Plugin-Paket installieren.

- Benötigte Dateien bereitstellen.

- Verwaltungsoberfläche integrieren.

- Konfiguration initialisieren.

- Keine automatischen Snapshot-Aktionen ohne Konfiguration starten.

**Updates**

- Bestehende Konfiguration erhalten.

- Änderungen an Konfigurationsformaten kontrolliert migrieren.

- Versionsinformationen anzeigen.

- Kompatibilität vor der Installation berücksichtigen.

**Deinstallation**

- Plugin-eigene geplante Aufgaben deaktivieren.

- Plugin-Dateien entfernen.

- Plugin-eigene WebGUI-Integration entfernen.

- Keine fremden Cron-Aufgaben verändern.

- Keine bestehenden Nutzdaten oder Snapshots löschen.

- Persistente Plugin-Einstellungen entweder kontrolliert entfernen oder dem Benutzer eine dokumentierte Entscheidung ermöglichen.

Ein besonderes Ziel ist die nachweisbare Rückstandsfreiheit bezüglich Systemintegration und laufender Komponenten.

## 7. Entwicklungsphasen

### Phase 1 – Analyse und Architektur

- Aktuelle Unraid-Plugin-Architektur untersuchen.

- Offizielle Integrationsmechanismen identifizieren.

- OpenZFS-Version und verfügbare Funktionen prüfen.

- Geeignete Entwicklungsstruktur definieren.

- Installations- und Deinstallationskonzept entwickeln.

- Bedrohungs- und Fehleranalyse durchführen.

**Ergebnis:** Technisch geprüfte Architektur und Projektstruktur.

### Phase 2 – Minimal funktionsfähiges Plugin (MVP)

- Plugin installierbar machen.

- Erste Integration in die Unraid-WebGUI.

- Pools und Datasets auslesen.

- Snapshots auflisten.

- Snapshots manuell erstellen.

- Snapshots mit Sicherheitsprüfung löschen.

- Grundlegende Protokollierung.

**Ergebnis:** Funktionsfähige erste Version ohne automatische Snapshot-Löschvorgänge.

### Phase 3 – Automatisierung

- Zeitpläne konfigurieren.

- Automatische Snapshot-Erstellung.

- Aufbewahrungsregeln.

- Geschützte und fremde Snapshots berücksichtigen.

- Testmodus für Bereinigungen.

- Cron-Integration und Neustartbeständigkeit.

**Ergebnis:** Vollautomatischer Snapshot-Betrieb.

### Phase 4 – Wiederherstellung

- Dateibrowser für Snapshot-Inhalte.

- Wiederherstellung einzelner Dateien.

- Wiederherstellung ganzer Verzeichnisse.

- Sichere vollständige Dataset-Wiederherstellung.

- Schutz vor unbeabsichtigtem Überschreiben.

**Ergebnis:** Benutzerfreundliche Wiederherstellung ohne CLI.

### Phase 5 – Erweiterte Integrationen

- Windows-Vorgängerversionen über Samba.

- Erweiterte VM- und Docker-Unterstützung.

- Detaillierte Speicherstatistiken.

- Verbesserte Statusanzeigen und Benachrichtigungen.

- Optional spätere Integration externer Sicherungsziele.

### Phase 6 – Qualitätssicherung und Veröffentlichung

- Installation und Deinstallation testen.

- Systemneustarts testen.

- Fehlerfälle simulieren.

- ZFS-Klone und Snapshot-Abhängigkeiten testen.

- Verhalten bei vollem Speicher prüfen.

- Sicherheitsprüfung.

- Dokumentation erstellen.

- Veröffentlichung als Open-Source-Projekt vorbereiten.

## 8. Abgrenzung

Das Projekt ist zunächst ein **ZFS-Snapshot-Manager und kein vollständiges Backup-System**.

Snapshots auf demselben Pool schützen nicht vor dessen vollständigem Ausfall.

Eine spätere Erweiterung um ZFS-Replikation, andere Speicherziele oder externe Backup-Funktionen ist denkbar, gehört aber nicht zum ersten Entwicklungsumfang.

Wir wollen zunächst eine kleine, sichere, zuverlässig funktionierende Lösung schaffen, bevor weitere Funktionen hinzukommen.

## 9. Qualitäts- und Abnahmekriterien

Das Plugin gilt als erfolgreich, wenn:

- Es über den regulären Unraid-Plugin-Mechanismus installiert werden kann.

- Es vorhandene ZFS-Datasets zuverlässig erkennt.

- Snapshots über die GUI erstellt und verwaltet werden können.

- Automatische Zeitpläne einen Neustart überstehen.

- Aufbewahrungsregeln ausschließlich die vorgesehenen Snapshots erfassen.

- Eine einfache Dateiwiederherstellung ohne Terminal möglich ist.

- Gefährliche Operationen abgesichert sind.

- Fehler verständlich angezeigt und protokolliert werden.

- Die Deinstallation keine unerwünschten Systemkomponenten hinterlässt.

- Bestehende ZFS-Daten und fremde Snapshots zuverlässig geschützt bleiben.

## 10. Arbeitsweise im Projekt

Die Entwicklung erfolgt schrittweise und nachvollziehbar.

Vor jeder technischen Entscheidung werden die tatsächlich verfügbaren Unraid- und ZFS-Mechanismen geprüft. Vermutungen über Schnittstellen oder Systemverhalten dürfen nicht als gesicherte Fakten behandelt werden.

Wir bevorzugen vorhandene Betriebssystemfunktionen gegenüber zusätzlichen Software-Abhängigkeiten.

Jede Funktion wird zunächst in einer Testumgebung geprüft, bevor sie auf produktiven Datasets eingesetzt wird.

Bei Änderungen an Systemkomponenten muss jederzeit klar sein:

- Welche Dateien werden angelegt oder verändert?

- Welche Prozesse oder Aufgaben werden ausgeführt?

- Wo liegt die persistente Konfiguration?

- Was geschieht bei einem Systemneustart?

- Wie lässt sich die Änderung rückgängig machen?

- Was passiert bei einer Deinstallation?

**Leitgedanke des Projekts:**

Ein leistungsfähiger, sicherer und intuitiver ZFS Snapshot Manager, der sich vollständig in Unraid integriert, ohne unnötige Abhängigkeiten oder tiefgreifende Systemveränderungen.

**Das Ziel ist kein Skript mit einer grafischen Oberfläche, sondern ein sauber entwickeltes, langfristig wartbares Unraid-Plugin.**
