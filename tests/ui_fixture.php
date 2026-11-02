<?php
declare(strict_types=1);
// Isolated fictitious data for the read-only local UI preview.
require_once __DIR__ . '/../bin/bootstrap.php';
use PandoraLox\Store;

$root = $argv[1] ?? '';
if ($root === '' || substr($root, 0, 1) !== '/') { throw new InvalidArgumentException('Absolute preview directory required.'); }
$store = new Store($root . '/config', $root . '/data');
if ($store->all()) { throw new RuntimeException('Use an empty directory for a new preview fixture.'); }
$one = $store->saveVehicle(['name' => '', 'vehicle_icon' => 'motorhome', 'login' => 'beispiel-camper', 'password' => 'preview-password-only',
    'device_id' => '100001', 'enabled' => true, 'allow_control' => true]);
$two = $store->saveVehicle(['name' => '', 'vehicle_icon' => 'car', 'login' => 'beispiel-pkw', 'password' => 'preview-second-password-only',
    'device_id' => '100002', 'enabled' => true, 'allow_control' => false]);
$now = gmdate('c');
$store->writeState($one, ['updated_at' => time(), 'error' => '', 'discovered' => [['id' => '100001', 'name' => 'Camper'], ['id' => '100003', 'name' => 'Transporter']],
    'vehicle' => ['id' => '100001', 'name' => 'Camper', 'model' => 'Pandora Camper Pro', 'online' => true, 'updatedAt' => $now,
        'position' => ['latitude' => 52.520008, 'longitude' => 13.404954, 'updatedAt' => $now],
        'status' => ['armed' => true, 'alarm' => false, 'engineRunning' => false, 'ignition' => false, 'moving' => false,
            'doorsOpen' => false, 'trunkOpen' => false, 'hoodOpen' => false, 'heaterOn' => false, 'batteryVoltage' => 12.71,
            'fuelPercent' => 68, 'cabinTemperature' => 19.5, 'engineTemperature' => 22, 'outsideTemperature' => 12,
            'speed' => 0, 'gsmLevel' => 4, 'canMileageKm' => 24128],
        'commands' => ['lock', 'unlock', 'heater_on', 'heater_off', 'channel_on', 'channel_off']],
    'events' => [
        ['id' => 'demo-3', 'title' => 'Schutz aktiviert', 'severity' => 'info', 'occurredAt' => $now, 'description' => 'Pandora-Ereigniscode 1 / 0.'],
        ['id' => 'demo-2', 'title' => 'Schutz deaktiviert', 'severity' => 'info', 'occurredAt' => gmdate('c', time() - 3600), 'description' => 'Pandora-Ereigniscode 2 / 0.'],
        ['id' => 'demo-1', 'title' => 'Alarm gemeldet', 'severity' => 'alarm', 'occurredAt' => gmdate('c', time() - 7200), 'description' => 'Pandora-Ereigniscode 3 / 0.'],
    ], 'history_note' => 'Frei erfundene Beispielmeldungen für die lokale Vorschau.', 'history_available' => true,
    'last_command' => ['command' => 'lock', 'status' => 'confirmed', 'message' => 'Beispiel: Fahrzeugstatus bestätigt.']]);
$store->writeState($two, ['updated_at' => time() - 600, 'error' => '', 'vehicle' => ['id' => '100002', 'name' => 'Pkw', 'online' => false,
    'updatedAt' => gmdate('c', time() - 600), 'position' => null, 'status' => ['armed' => false, 'batteryVoltage' => 12.4], 'commands' => []], 'events' => [], 'discovered' => []]);
$store->writeRuntime(['heartbeat' => time(), 'mqtt_connected' => true, 'error' => '']);
echo 'Preview fixture created (fictitious data only).' . PHP_EOL;
