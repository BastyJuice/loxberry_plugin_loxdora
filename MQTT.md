# MQTT und Loxone

Der Standard-Präfix lautet `pandora`. Jeder Fahrzeugeintrag bekommt eine stabile ID wie `fahrzeug_1`. Die Pandora-Geräte-ID ist ein separater Messwert und nicht Teil dieses Topic-Pfades.

## Status

Statuswerte werden mit QoS 0 und Retain veröffentlicht. Ein neuer Abonnent erhält damit sofort den letzten bekannten Stand. Leere Werte entfernen einen bisherigen gespeicherten Wert am Broker. In Loxone deshalb Gültigkeit und Aktualität mit auswerten: `state/available = 1`, `state/<feld>_valid = 1` und `state/stale = 0`. `available` bezeichnet einen erfolgreichen Plugin-Abruf und ersetzt nicht den separat gemeldeten Fahrzeugstatus `online`.

| Topic unter `pandora/fahrzeug_1/` | Inhalt |
| --- | --- |
| `state/name` | Fahrzeugname aus Pandora, bei Bedarf eigener Anzeigename aus dem Plugin |
| `state/device_id` | Pandora-Fahrzeug-ID |
| `state/available` | `1`: Daten abrufbar, `0`: deaktiviert oder Abruffehler |
| `state/online` | `1` / `0`, falls Pandora einen Verbindungsstatus liefert |
| `state/stale` | `1`: Fahrzeugdaten älter als fünf Minuten oder Zeitstempel fehlt |
| `state/updated_at` | Zeitpunkt der Fahrzeugdaten als ISO-8601 |
| `state/last_poll` | Zeitpunkt des letzten erfolgreichen Abrufs |
| `state/error` | Öffentliche Fehlermeldung, sonst leer |
| `state/armed`, `state/alarm` | Schutz scharf bzw. Alarm aktiv: `1` / `0` |
| `state/ignition`, `state/engine_running`, `state/moving` | Zündung, Motor und Bewegung: `1` / `0` |
| `state/doors_open`, `state/trunk_open`, `state/hood_open` | Öffnungszustände: `1` / `0` |
| `state/heater_on` | Standheizung: `1` / `0` |
| `state/battery_voltage` | Spannung in V |
| `state/fuel_percent` | Kraftstoff in % |
| `state/cabin_temperature`, `state/engine_temperature`, `state/outside_temperature` | Temperaturen in °C |
| `state/speed`, `state/mileage_km` | Geschwindigkeit in km/h und CAN-Kilometerstand in km |
| `state/gsm_level` | Von Pandora gemeldete Mobilfunksignalstärke |
| `state/latitude`, `state/longitude`, `state/position_updated_at` | Koordinaten und Zeitstempel der Position |
| `state/model` | Modellbezeichnung, soweit verfügbar |
| `events` | Letzte verfügbare Meldungen als JSON-Liste |
| `events/latest` | Letzte Meldung als JSON |
| `events/latest/text` | Text der letzten Meldung für einen Loxone-Texteingang |
| `state/last_command_status`, `state/last_command_message` | Letztes Befehlsergebnis als einzelne Textwerte |

Für jeden Telemetriewert wird zusätzlich `state/<feld>_valid` veröffentlicht, beispielsweise `state/battery_voltage_valid`. Für Verwaltungswerte wie `name`, `stale` und `error` gibt es kein solches Topic. Ein nicht unterstützter Wert bleibt unbekannt. Wird ein Fahrzeug entfernt oder der Präfix geändert, löscht der Dienst seine gespeicherten alten Topics bei der nächsten erfolgreichen MQTT-Verbindung.

`pandora/bridge/availability` enthält `online` oder `offline`. Ein MQTT-Last-Will setzt den Wert auch bei einem unerwarteten Verbindungsabbruch auf `offline`. Dieser Wert beschreibt den MQTT-Dienst, nicht die Erreichbarkeit der Pandora-Cloud oder des Fahrzeugs.

## Steuerbefehle

Jeder Befehl wird als einzelner Impuls mit dem Textwert `1`, QoS 0 und **ohne Retain** gesendet. `0`, andere Werte, unbekannte Befehle und nach Neuverbindung gelieferte gespeicherte Nachrichten werden verworfen. Die Steuerung muss im Fahrzeugeintrag freigegeben sein; zusätzlich gelten die Pandora-Kontoberechtigungen und Anlagenfunktionen.

| Topic | Wirkung |
| --- | --- |
| `pandora/fahrzeug_1/set/lock` | Schutz aktivieren |
| `pandora/fahrzeug_1/set/unlock` | Schutz deaktivieren |
| `pandora/fahrzeug_1/set/heater_on` | Standheizung einschalten |
| `pandora/fahrzeug_1/set/heater_off` | Standheizung ausschalten |
| `pandora/fahrzeug_1/set/channel_on` | Zusatzkanal einschalten |
| `pandora/fahrzeug_1/set/channel_off` | Zusatzkanal ausschalten |

Ausführbare Befehle werden vor dem Sendeversuch aus der lokalen Warteschlange entfernt und niemals automatisch erneut gesendet. Eine Warteschlangen-Anfrage verfällt nach 30 Sekunden; nach einer Änderung des Fahrzeugeintrags wird sie verworfen. Pro Fahrzeug ist nur eine Anfrage gleichzeitig vorgemerkt. Für jedes verarbeitete Kommando liefert `pandora/fahrzeug_1/command/result` eine nicht gespeicherte JSON-Rückmeldung mit Anfrage-ID, Befehl, Status, Nachricht und Zeitstempel. `confirmed` bezeichnet eine passende Fahrzeugrückmeldung samt überprüfbarem Status. `sent` und `timeout` bestätigen die Ausführung nicht. `unknown` bedeutet: Der Sendeversuch hatte keine verlässliche Antwort; der Befehl könnte angekommen sein. Vor erneutem Senden den Fahrzeugstatus prüfen. `rejected` bezeichnet eine vor dem Senden verworfene Anfrage. Der Zusatzkanal hat keinen zuverlässig auswertbaren Rückmeldezustand und wird daher nicht als bestätigt ausgegeben.

## LoxBerry → Loxone

1. Im vorhandenen LoxBerry-MQTT-Gateway die gewünschten **Subscriptions** konfigurieren, beispielsweise `pandora/+/state/#` und `pandora/+/events/latest/text`.
2. Den gewünschten Miniserver und dessen Weiterleitung im Gateway einrichten. Topic-Umwandlung, UDP-Port und virtuelle Eingänge werden durch das native Gateway festgelegt.
3. Die Gateway-Ausgabe im MQTT-Finder bzw. den Gateway-Werkzeugen prüfen und die entsprechenden Eingänge in Loxone Config übernehmen. Für Betriebsfreigaben insbesondere `bridge/availability = online`, `available`, `stale` und die jeweiligen `_valid`-Werte verknüpfen. Dafür auch `pandora/bridge/availability` abonnieren. Die Bridge-Verfügbarkeit muss immer als Bedingung dienen: Nach Stromausfall bleiben letzte Telemetrie- und Gültigkeitswerte am Broker gespeichert, bis der Dienst sie aktualisiert.

Die genaue Eingangsbezeichnung hängt von der gewählten Gateway-Konfiguration ab. Das Plugin verändert keine globalen Subscriptions und keine Miniserver-Zugangsdaten automatisch. [Anleitung MQTT → Loxone](https://wiki.loxberry.de/konfiguration/widget_help/widget_mqtt/mqtt_gateway/mqtt_schritt_fur_schritt_mqtt_loxone).

## Loxone → Pandora

Die native UDP-Schnittstelle des LoxBerry-MQTT-Gateways kann MQTT-Befehle veröffentlichen. In Loxone Config einen virtuellen UDP-Ausgang zum LoxBerry anlegen. Als Zielport den **UDP-In-Port des MQTT-Gateways** aus dem LoxBerry-MQTT-Widget übernehmen; dieser ist ein anderer Port als der MQTT-Broker-Port und die UDP-Eingänge des Miniservers. Als Befehl bei Ein:

```text
publish pandora/fahrzeug_1/set/lock 1
```

Für Unscharfschalten einen eigenen Taster mit folgendem Befehl verwenden:

```text
publish pandora/fahrzeug_1/set/unlock 1
```

Der Aus-Befehl bleibt leer. Einen kurzen Tasterimpuls verwenden, keine zyklische Wiederholung. Danach das Ergebnis und den aktualisierten Fahrzeugstatus anzeigen. Die Gateway-Schnittstelle beschreibt dieses `publish`-Format in der [offiziellen HTTP-/UDP-Dokumentation](https://wiki.loxberry.de/konfiguration/widget_help/widget_mqtt/mqtt_gateway/mqtt_gateway_http_und_udp_interface).
