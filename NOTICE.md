# Hinweise zu Quellen und Marken

Der eigene Loxdora-Quellcode und die zugehörige Dokumentation werden unter der
[MIT-Lizenz](LICENSE) veröffentlicht.

## LoxBerry-Komponenten

Das installierte Plugin verwendet das vorhandene LoxBerry PHP SDK, die native
MQTT-Bibliothek und die LoxBerry-Oberfläche. Diese Komponenten werden nicht mit
dem Plugin-Installationspaket ausgeliefert und behalten ihre eigenen Lizenzen.

Für die lokale Entwicklung enthält `tests/official-lb4/` unveränderte Dateien aus
dem offiziellen LoxBerry-Quellbaum. Die zugehörigen Ursprungsangaben und
Prüfsummen stehen in `tests/official-lb4/README.md` und `SOURCES.json`.
Die Apache-2.0-Lizenz des verwendeten LoxBerry-Tags liegt unter
`tests/official-lb4/LICENSE`; zusätzliche Bibliothekslizenzen sind dort dokumentiert.
Diese Dateien, einschließlich ihrer Bibliotheken, Schriftdateien und Grafiken,
behalten ihre ursprünglichen Lizenzen und Lizenzvermerke. Die MIT-Lizenz von
Loxdora lizenziert diese Drittinhalte nicht neu. Das gesamte Testverzeichnis ist
vom installierbaren Plugin-ZIP ausgeschlossen.

Die Entwicklungsfixtures enthalten insbesondere:

- LoxBerry 4.0.0.15: Apache-2.0, vollständiger Text unter
  `tests/official-lb4/LICENSE`.
- Vue 3.2.23: MIT, Copyright Yuxi (Evan) You und Mitwirkende;
  vollständiger Text unter `tests/official-lb4/LICENSE-Vue-MIT`.
- jQuery 1.12.4 und jQuery Mobile 1.4.5: MIT, jQuery Foundation;
  ursprüngliche Lizenzvermerke bleiben in den Dateien erhalten.
- jQuery Form Validator 2.2.141: MIT, Victor Jonsson;
  ursprünglicher Lizenzvermerk bleibt in der Datei erhalten.
- PrimeIcons: MIT, PrimeTek; ursprüngliche Copyright- und Lizenzangaben
  stehen in der mitgelieferten Schriftdatei.

## Pandora-Anbindung

Der PHP-Client wurde aus dem eigenen Pandora-Connect-Projekt für PHP 7.4
angepasst. Im Quellcode genannte Protokollreferenzen dienen der Zuordnung von
Pandora-Ereigniscodes. Echte Kontoauszüge oder Zugangsdaten sind kein Bestandteil
dieses Repositorys.

## Marken

Pandora, LoxBerry und Loxone sind Bezeichnungen ihrer jeweiligen Rechteinhaber.
Das Loxdora-Icon ist eine eigene Vektorgrafik mit einem an Pandora angelehnten
Schlossmotiv. Die Veröffentlichung dieses Plugins ist keine Aussage über eine
offizielle Freigabe oder Unterstützung durch diese Projekte oder Unternehmen.
