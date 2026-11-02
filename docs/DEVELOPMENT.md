# Entwicklung & Technik

Diese Hinweise ergänzen die [Einrichtung in der README](../README.md) und die [MQTT-Dokumentation](../MQTT.md).

## Plattform und Protokoll

Loxdora ist ein natives LoxBerry-4-Plugin mit PHP-7.4-kompatiblem Code. Im Betrieb verwendet es die vorhandenen PHP-Erweiterungen cURL, OpenSSL, JSON und Session, das LoxBerry PHP SDK und die mit LoxBerry gelieferte PHP-MQTT-Bibliothek. Es installiert keine zusätzlichen Pakete, Composer-Abhängigkeiten, Python- oder Node-Laufzeit, Datenbank und keinen eigenen Broker.

Der Pandora-Adapter übernimmt den Ablauf des bestehenden Connect-Portals: Cookie-Anmeldung, Gerätezuordnung, Status, Meldungsverlauf und freigegebene Befehle über `https://p-on.ru`. Verbindungen verwenden HTTPS mit Zertifikatsprüfung. Die Protokollannahmen beruhen auf diesem vorhandenen Portal; Änderungen an der Pandora-Cloud können Anpassungen erfordern.

Abfragen laufen standardmäßig alle 60 Sekunden, einstellbar von 30 bis 3600 Sekunden. Das Abrufintervall ist keine Zusage, dass das Fahrzeug selbst in diesem Takt neue Werte liefert. Nicht gelieferte Messwerte bleiben unbekannt. Nach fünf Minuten ohne verlässlichen Fahrzeugzeitstempel kennzeichnet das Plugin Daten als veraltet.

## Fahrzeugprofile

Jeder Eintrag erhält eine unveränderliche interne ID wie `fahrzeug_1`. Der Anzeigename ist davon unabhängig. Mehrere Einträge können denselben Pandora-Zugang mit unterschiedlichen Pandora-Geräte-IDs verwenden.

Ein leeres Feld **Eigener Anzeigename** übernimmt den Namen aus dem Pandora-Geräteprofil (`name`). Änderungen erscheinen nach der nächsten erfolgreichen Abfrage in Dashboard, Details, Meldungen und `state/name`. Vor dem ersten Abruf wird beispielsweise „Fahrzeug 1“ angezeigt. Bereits gespeicherte eigene Namen bleiben bestehen; durch Leeren des Felds wechselt man zur automatischen Übernahme. Ohne Verbindung bleibt ein vorhandener Name aus dem letzten Abruf sichtbar.

Das passende Symbol lässt sich als Motorrad, Pkw, Sportwagen, Kastenwagen oder Wohnmobil auswählen. **Automatisch** verwendet ausschließlich einen ausdrücklich normalisierten, bekannten Fahrzeugtyp und fällt sonst auf Pkw zurück. Das vorliegende Pandora-Schema enthält eine Anlagenbezeichnung und einen numerischen `car_type`; dessen Zuordnung zu diesen fünf Fahrzeugarten ist nicht verifiziert. Namen, Anlagenbezeichnungen und unbekannte Typnummern werden deshalb nicht zur Klassifizierung verwendet.

Ein leeres Passwortfeld beim Bearbeiten erhält das gespeicherte Passwort. Wird der Benutzername geändert, ist ein neues Passwort nötig. Änderungen des Kontozugangs oder der Gerätezuordnung entfernen die bisherige Sitzung und den zugehörigen Anzeigestand.

## Status, Meldungen und Steuerung

Leserechte für Status, Position und Meldungen werden getrennt geprüft. Verfügbare Messwerte und Steuerbefehle hängen zusätzlich von Anlage und Ausstattung ab. Entzogene Berechtigungen und entfernte Fahrzeuge dürfen keine vorherigen privaten Werte weiterliefern.

Die Meldungsansicht führt begrenzte historische Abfragen und empfangene Update-Meldungen zusammen. Der Zeitraum beträgt sieben Tage mit höchstens 50 Einträgen. Eine vollständige Kontohistorie wird nicht zugesichert. Nicht verifizierte Zeitstempel bleiben unbekannt; unbekannte Ereigniscodes werden nicht mit erfundenen Bedeutungen versehen.

Die sechs unterstützten Befehle sind `lock`, `unlock`, `heater_on`, `heater_off`, `channel_on` und `channel_off`. Motorstart und Motorstopp gehören nicht dazu. Die Steuerung ist pro Fahrzeugeintrag zunächst deaktiviert. Vor jedem Sendeversuch prüft das Plugin Gerätezugehörigkeit, Pandora-Rechte, unterstützte Funktionen und frischen Fahrzeugstatus erneut.

Physische Befehle werden niemals automatisch wiederholt. Ein verlorener Sende- oder Bestätigungsweg kann die tatsächliche Ausführung offenlassen. Insbesondere ein Zusatzkanal liefert über diese Anbindung keinen zuverlässig prüfbaren Zustand. Die einzelnen Rückmeldestatus und die Warteschlangenregeln erläutert [MQTT.md](../MQTT.md).

## Oberfläche und Hintergrunddienst

Das Dashboard zeigt bei einem Fahrzeug eine große Detailkarte und bei mehreren Fahrzeugen ein an die Bildschirmbreite angepasstes Raster. Kennzahlen berücksichtigen nur entsprechend aktuelle, verfügbare Werte. Unbekannte, pausierte, veraltete und fehlerhafte Zustände werden gekennzeichnet. Fahrzeugillustrationen sind allgemeine lokale SVG-Zeichnungen; externe Bilder, Schriften, Karten oder zusätzliche Bibliotheken werden dafür nicht benötigt.

**Ansicht neu laden** liest den lokalen Cache. **Status abrufen** fordert einen neuen Pandora-Abruf für das gewählte Fahrzeug an. **Details & Steuerung** öffnet die Fahrzeugansicht; Kontozugang und Zuordnung liegen unter **Meine Fahrzeuge**. Die **Diagnose** zeigt Hintergrunddienst und MQTT-Verbindung.

Der Worker läuft als LoxBerry-Benutzer, startet beim Booten und wird durch den nativen minütlichen Cron-Aufruf überwacht. Eine Dateisperre verhindert mehrere gleichzeitig aktive Worker. Änderungen an Einträgen und Einstellungen übernimmt der laufende Dienst. Die Plugininstallation benötigt keine Root-Dienste und keine eigenen Sudoers-Regeln.

## Speicherung, Sicherung und Updates

Zugangsdaten und Cookie-Sitzungen liegen außerhalb des Webverzeichnisses in der privaten Plugin-Konfiguration. Passwörter und Sitzungsdaten werden mit AES-256-GCM verschlüsselt; der Schlüssel liegt ebenfalls privat auf diesem LoxBerry. Andere Konfigurationsfelder, etwa der Benutzername, sind dadurch nicht pauschal verschlüsselt. Ein Administrator des Geräts kann auf die gespeicherten Daten zugreifen.

Für die Wiederherstellung müssen **Konfiguration und Schlüssel gemeinsam** gesichert werden. Update-Hooks stoppen den Worker, sichern Konfiguration einschließlich Schlüssel und Daten, stellen diese wieder her und starten den Dienst. Bei einer Deinstallation beendet das Plugin den Worker; die LoxBerry-Pluginverwaltung entfernt die Plugin-Dateien. Die globale MQTT- und Miniserver-Konfiguration bleibt eine Einstellung des LoxBerry und wird nicht automatisch verändert.

## Lokal prüfen

Entwicklung und automatisierte Tests verwenden ausschließlich ausdrücklich erfundene Testwerte und lokale Fixtures. Vorhandene echte Zugangsdaten und Fahrzeugdaten dürfen weder für Anmeldungen noch in Quellcode, Ausgaben, Testdaten oder Plugin-Archiven verwendet werden.

```sh
php tests/client.php
php tests/service.php
php tests/worker.php
php tests/artwork.php
php tests/mqtt.php /path/to/Loxberry/libs/phplib/phpMQTT/phpMQTT.php
python3 tests/ui_test.py
python3 tests/dashboard_test.py
python3 tests/package.py
```

Der MQTT-Test verwendet einen lokalen Test-Broker und die unveränderte LoxBerry-Bibliothek. Er prüft unter anderem, dass gespeicherte Steuerbefehle nach Start oder Neuverbindung verworfen werden. Fehlen native Bibliothek oder PCNTL, meldet dieser Test ausdrücklich `SKIP`. Die lokalen UI-Tests verwenden ein Test-SDK und offizielle LoxBerry-Vorschauressourcen; sie ersetzen weder echte Authentifizierung noch Systemdienste.

**Loxdora wurde bereits getestet und läuft.** Die automatisierten Prüfungen ergänzen die praktischen Tests und verwenden weiterhin ausschließlich erfundene Testwerte.

## Installationsarchiv erstellen

```sh
python3 tools/build.py
```

Python wird nur für lokales Erstellen und Prüfen des ZIP-Archivs verwendet und läuft nicht auf dem LoxBerry. Das Installationsarchiv enthält ausschließlich Plugin-Dateien, keine Testdaten, fremden Vorschau-Bibliotheken oder gespeicherten Zugangsdaten. Die mitgelieferten Entwicklungsressourcen behalten ihre jeweilige ursprüngliche Lizenz und Urheberschaft.

## Technische Grundlage

- [Native LoxBerry-Pluginstruktur und Installationsablauf](https://wiki.loxberry.de/entwickler/plugin_fur_den_loxberry_entwickeln_ab_version_1x/start)
- [Offizielles PHP-Sample-Plugin](https://github.com/christianTF/LoxBerry-Plugin-SamplePlugin-V2-PHP)
- [LoxBerry PHP SDK](https://wiki.loxberry.de/entwickler/php_develop_plugins_with_php/start)
- [LoxBerry MQTT-Integration](https://wiki.loxberry.de/entwickler/mqtt/start)
- [LoxBerry MQTT-Gateway](https://wiki.loxberry.de/konfiguration/widget_help/widget_mqtt/mqtt_gateway/start)
