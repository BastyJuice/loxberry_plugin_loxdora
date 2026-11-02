<?php
declare(strict_types=1);
namespace PandoraLox;

final class Topics
{
    public const FIELDS = [
        'armed' => 'armed', 'alarm' => 'alarm', 'engineRunning' => 'engine_running', 'ignition' => 'ignition',
        'moving' => 'moving', 'doorsOpen' => 'doors_open', 'trunkOpen' => 'trunk_open', 'hoodOpen' => 'hood_open',
        'heaterOn' => 'heater_on', 'batteryVoltage' => 'battery_voltage', 'fuelPercent' => 'fuel_percent',
        'cabinTemperature' => 'cabin_temperature', 'engineTemperature' => 'engine_temperature',
        'outsideTemperature' => 'outside_temperature', 'speed' => 'speed', 'gsmLevel' => 'gsm_level', 'canMileageKm' => 'mileage_km',
    ];

    /** Empty MQTT payload deletes a previous retained value; validity is always published explicitly. */
    public static function snapshot(string $prefix, array $config, array $state): array
    {
        $root = $prefix . '/' . $config['id'];
        $vehicle = is_array($state['vehicle'] ?? null) ? $state['vehicle'] : [];
        $available = $config['enabled'] && !empty($vehicle) && empty($state['error']);
        $topics = [$root . '/state/available' => $available ? '1' : '0', $root . '/state/name' => VehicleProfile::displayName($config, $vehicle),
            $root . '/state/updated_at' => (string) ($vehicle['updatedAt'] ?? ''),
            $root . '/state/last_poll' => empty($state['updated_at']) ? '' : gmdate('c', $state['updated_at']),
            $root . '/state/error' => (string) ($state['error'] ?? '')];
        $recorded = isset($vehicle['updatedAt']) ? strtotime($vehicle['updatedAt']) : false;
        $topics[$root . '/state/stale'] = (!$available || $recorded === false || time() - $recorded > 300 || $recorded > time() + 60) ? '1' : '0';
        $fields = ['online' => $vehicle['online'] ?? null, 'device_id' => $vehicle['id'] ?? null,
            'model' => $vehicle['model'] ?? null, 'latitude' => $vehicle['position']['latitude'] ?? null,
            'longitude' => $vehicle['position']['longitude'] ?? null, 'position_updated_at' => $vehicle['position']['updatedAt'] ?? null];
        foreach (self::FIELDS as $source => $target) { $fields[$target] = $vehicle['status'][$source] ?? null; }
        foreach ($fields as $field => $value) {
            $topics[$root . '/state/' . $field] = $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            $topics[$root . '/state/' . $field . '_valid'] = ($available && $value !== null) ? '1' : '0';
        }
        $events = $state['events'] ?? [];
        $topics[$root . '/events'] = Store::json(array_values($events));
        $topics[$root . '/events/latest'] = $events ? Store::json(reset($events)) : '';
        $topics[$root . '/events/latest/text'] = $events ? (string) (reset($events)['title'] ?? '') : '';
        return $topics;
    }

    public static function parseCommand(string $prefix, string $topic, string $payload): ?array
    {
        if (trim($payload) !== '1') { return null; }
        $pattern = '~^' . preg_quote($prefix, '~') . '/(fahrzeug_[1-9][0-9]{0,5})/set/([a-z_]+)$~D';
        if (!preg_match($pattern, $topic, $match) || !isset(PandoraClient::commands()[$match[2]])) { return null; }
        return ['vehicle_id' => $match[1], 'command' => $match[2]];
    }
}
