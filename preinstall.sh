#!/bin/bash
set -eu
trap 'echo "<FAIL> Die Voraussetzungen für Loxdora sind nicht erfüllt."; exit 2' ERR

/usr/bin/php -r '
if (version_compare(PHP_VERSION, "7.4.0", "<")) { fwrite(STDERR, "PHP 7.4 oder neuer erforderlich.\n"); exit(1); }
foreach (["curl", "openssl", "json", "session"] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, "Vorhandene PHP-Erweiterung fehlt: " . $extension . "\n"); exit(1); }
}
if (!stream_resolve_include_path("loxberry_system.php") || !stream_resolve_include_path("loxberry_io.php") || !stream_resolve_include_path("phpMQTT/phpMQTT.php")) {
    fwrite(STDERR, "Die native LoxBerry-PHP-Umgebung ist nicht verfügbar.\n"); exit(1);
}
'
echo "<OK> Native PHP- und MQTT-Komponenten vorhanden. Es werden keine Pakete installiert."
exit 0
