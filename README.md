<div align="center">

<img src="icons/icon.svg" width="96" alt="Loxdora Logo">

# Loxdora

**Pandora trifft Loxone.**

Deine Pandora-Fahrzeuge im Smart Home sehen und steuern.

**[Plugin herunterladen](https://github.com/BastyJuice/loxberry_plugin_loxdora/raw/refs/heads/main/dist/loxdora-0.3.1.zip)** · [Einrichtung](#schnellstart) · [MQTT & Loxone](MQTT.md)

LoxBerry 4 · PHP 7.4+ · Version 0.3.1 · Anbindung über die Pandora-Cloud

</div>

---

Loxdora verbindet Pandora-Alarmsysteme mit dem vorhandenen MQTT-System deines LoxBerry. Das Plugin bündelt Fahrzeuge, Konten, Status und Meldungen in einer Oberfläche. Über das LoxBerry-MQTT-Gateway lassen sich die Daten in Loxone nutzen und unterstützte Fahrzeugfunktionen aus der Visualisierung bedienen.

So kannst du beispielsweise den Schutzstatus im Haus anzeigen, eine Fahrzeugmeldung in deine Loxone-Benachrichtigungen einbinden oder eine Warnung bei niedriger Batteriespannung aufbauen. Die passende Logik legst du in Loxone an.

## Alles an einem Ort

| Funktion | Das kannst du damit machen |
| --- | --- |
| **Dashboard** | Fahrzeuge mit Schutz- und Verbindungsstatus, Batteriespannung, Temperatur, Kraftstoff und letzter Meldung im Blick behalten. |
| **Mehrere Fahrzeuge & Konten** | Pandora-Zugänge je Fahrzeugeintrag speichern und mehrere Fahrzeuge desselben Kontos zuordnen. |
| **Namen & Symbole** | Fahrzeugnamen aus Pandora übernehmen oder eigene Anzeigenamen verwenden. Motorrad, Pkw, Sportwagen, Kastenwagen oder Wohnmobil als Symbol auswählen. |
| **Status & Meldungen** | Verfügbare Fahrzeugwerte und die letzten Meldungen mit Zeitstempel anzeigen. Unbekannte und veraltete Werte bleiben gekennzeichnet. |
| **MQTT für Loxone** | Status über den konfigurierten LoxBerry-Broker veröffentlichen und mit dem nativen Gateway an den Miniserver weitergeben. |
| **Steuerung** | Schutz aktivieren/deaktivieren, Standheizung ein-/ausschalten und Zusatzkanal ein-/ausschalten, soweit Anlage und Konto dies erlauben. |
| **Native LoxBerry-Integration** | Oberfläche, PHP SDK, MQTT-Konfiguration und mitgelieferte MQTT-Bibliothek des LoxBerry verwenden. Keine zusätzlichen Laufzeitpakete installieren. |

## Schnellstart

Du brauchst einen **LoxBerry 4 mit PHP 7.4 oder neuer**, ein Pandora-Konto und eine Internetverbindung zur Pandora-Cloud. Die vorhandenen PHP-Erweiterungen cURL, OpenSSL, JSON und Session sowie das LoxBerry PHP SDK werden genutzt. MQTT muss im normalen **LoxBerry-MQTT-Widget** eingerichtet sein.

### 1. Plugin installieren

Das **[Installationspaket 0.3.1 herunterladen](https://github.com/BastyJuice/loxberry_plugin_loxdora/raw/refs/heads/main/dist/loxdora-0.3.1.zip)** und über die Pluginverwaltung des LoxBerry installieren. Anschließend **Loxdora** öffnen.

Das Plugin benötigt weder Composer noch Python, Node.js, eine Datenbank oder einen eigenen MQTT-Broker auf dem LoxBerry.

### 2. Fahrzeuge anlegen

1. Unter **Meine Fahrzeuge** einen Eintrag anlegen und Pandora-Benutzername und Passwort eingeben.
2. **Eigener Anzeigename** leer lassen, um den Namen aus Pandora zu übernehmen, oder einen eigenen Namen eintragen. Bei Bedarf ein **Fahrzeugsymbol** auswählen.
3. Speichern und **Status abrufen** ausführen. Enthält das Konto genau ein Fahrzeug, wird es automatisch zugeordnet. Bei mehreren Fahrzeugen die passende angezeigte Pandora-Fahrzeug-ID im Eintrag auswählen und erneut abrufen.
4. Weitere Fahrzeuge nach demselben Ablauf hinzufügen. Derselbe Pandora-Zugang kann für mehrere Einträge mit unterschiedlichen Fahrzeug-IDs verwendet werden.

Änderungen des Pandora-Namens erscheinen nach dem nächsten erfolgreichen Abruf. Die Symbolauswahl wird pro Fahrzeug gespeichert; **Automatisch** fällt bei einem nicht eindeutig bekannten Fahrzeugtyp auf das allgemeine Pkw-Symbol zurück. Eine Zuordnung aus Fahrzeugnamen oder Pandora-Anlagenbezeichnungen erfolgt nicht.

### 3. Mit Loxone verbinden

Unter **MQTT & Loxone** bei Bedarf den Topic-Präfix und das Abrufintervall einstellen. Standard sind **`pandora` und 60 Sekunden**; das Intervall lässt sich zwischen 30 und 3600 Sekunden wählen. Die Anbindung fragt die Pandora-Cloud regelmäßig ab und liefert keine garantierte Echtzeitübertragung.

Im vorhandenen LoxBerry-MQTT-Gateway die gewünschten Topics abonnieren und die Weiterleitung zum Miniserver einrichten. **[MQTT.md](MQTT.md)** erklärt die Eingänge, Gültigkeitswerte und den Rückweg für Steuerbefehle.

Die Steuerung ist für neue Einträge zunächst ausgeschaltet. Nach erfolgreichem Statusabruf lässt sie sich **pro Fahrzeug** freigeben.

## Status über MQTT nutzen

Jeder Eintrag erhält eine feste ID wie **`fahrzeug_1`**. Ein neuer Anzeigename verändert diese ID und die MQTT-Topics nicht. Beispielsweise veröffentlicht Loxdora:

```text
pandora/fahrzeug_1/state/name
pandora/fahrzeug_1/state/armed
pandora/fahrzeug_1/state/battery_voltage
pandora/fahrzeug_1/state/battery_voltage_valid
pandora/fahrzeug_1/events/latest/text
```

Mit **`pandora/#`** empfängst du alle Plugin-Topics. Statuswerte werden mit **QoS 0 und Retain** veröffentlicht; ein neuer Abonnent erhält den letzten bekannten Stand.

Für die Verarbeitung in Loxone auch **`pandora/bridge/availability = online`**, **`state/available = 1`**, **`state/stale = 0`** und die jeweiligen **`state/<feld>_valid = 1`** auswerten. Gespeicherte Werte sind allein kein Nachweis für eine aktuelle Verbindung. `state/online` zeigt separat den von Pandora gemeldeten Fahrzeug-Verbindungsstatus.

## Mit Loxone steuern

Das native MQTT-Gateway des LoxBerry nimmt Veröffentlichungen über seinen **UDP-In-Port** entgegen. Diesen Port aus dem MQTT-Widget als Ziel eines virtuellen UDP-Ausgangs in Loxone Config übernehmen. Als **Befehl bei Ein** zum Aktivieren des Schutzes:

```text
publish pandora/fahrzeug_1/set/lock 1
```

Zum Deaktivieren einen eigenen Taster verwenden:

```text
publish pandora/fahrzeug_1/set/unlock 1
```

Der Aus-Befehl bleibt leer. Befehle werden als einzelner Impuls mit dem Textwert **`1`**, **QoS 0 und ohne Retain** gesendet. Keine zyklische Wiederholung einrichten. Die vollständige Befehlsliste und Rückmeldungen stehen in **[MQTT.md](MQTT.md)**.

Ein gesendeter Befehl ist noch keine bestätigte Ausführung. Loxdora prüft die Berechtigungen und den aktuellen Fahrzeugzustand und unterscheidet gesendete, bestätigte und unklare Ergebnisse. Motorstart und Motorstopp sind nicht enthalten.

## Was du vor dem Einsatz wissen solltest

Verfügbare Messwerte, Meldungen und Steuerfunktionen hängen von der Pandora-Anlage, ihrer Ausstattung und den Kontoberechtigungen ab. Angezeigt wird der letzte bekannte Zustand. Daten ohne verlässlichen Zeitstempel oder älter als fünf Minuten werden als veraltet markiert. Die Meldungsansicht umfasst die verfügbaren Einträge der letzten sieben Tage, höchstens 50; sie ist keine vollständige Historie.

**Loxdora befindet sich in Entwicklung.** Die lokalen Prüfungen verwenden erfundene Testdaten. Die Abnahme auf einem echten LoxBerry 4 mit PHP 7.4, einem ausdrücklich freigegebenen Pandora-Testkonto und dem Loxone Miniserver steht noch aus. Änderungen an der Pandora-Cloud können Anpassungen am Plugin erfordern.

Passwörter und Cookie-Sitzungen werden verschlüsselt außerhalb des Webverzeichnisses gespeichert. Der Schlüssel liegt auf demselben LoxBerry; ein Administrator des Geräts kann auf diese Daten zugreifen. Weitere Hinweise zu Speicherung, Sicherung und Updates stehen in **[Entwicklung & Technik](docs/DEVELOPMENT.md)**.

## Hintergrund & Weiterentwicklung

Loxdora ist aus der Arbeit als Pandora Excellence Systempartner in Norddeutschland und dem Austausch mit Loxone-Nutzern entstanden. Die technische Grundlage stammt aus dem bestehenden Pandora-Connect-Portal von **[pandora-nord.de](https://www.pandora-nord.de/)**.

Es ist eine eigenständige Entwicklung, kein offizielles Plugin von Pandora, LoxBerry oder Loxone. Für Einrichtung und Fehlersuche helfen **[MQTT & Loxone](MQTT.md)**, die **Diagnose** im Plugin und die **[Entwicklungsdokumentation](docs/DEVELOPMENT.md)**.

## Unterstützen

Loxdora hilft dir im Alltag? Dann freue ich mich über einen virtuellen Kaffee und deine Unterstützung bei der Weiterentwicklung.

**[☕ Spendier mir einen Kaffee](https://www.paypal.com/donate/?hosted_button_id=FDJ5UHKLA8C7A)**

---

Development by **[BastyJuice](https://github.com/BastyJuice)** · [MIT-Lizenz](LICENSE)
