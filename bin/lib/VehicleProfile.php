<?php
declare(strict_types=1);
namespace PandoraLox;

/** Presentation preferences never change the Pandora device or its MQTT identifier. */
final class VehicleProfile
{
    public static function choices(): array
    {
        return ['auto' => 'Automatisch', 'motorcycle' => 'Motorrad', 'car' => 'Pkw',
            'sports_car' => 'Sportwagen', 'van' => 'Kastenwagen', 'motorhome' => 'Wohnmobil'];
    }

    public static function displayName(array $entry, array $vehicle = []): string
    {
        $custom = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
        if ($custom !== '') { return $custom; }
        $upstream = is_string($vehicle['name'] ?? null) ? trim($vehicle['name']) : '';
        if ($upstream !== '') { return $upstream; }
        $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
        return preg_match('/^fahrzeug_([1-9][0-9]*)$/D', $id, $match) ? 'Fahrzeug ' . $match[1] : 'Pandora-Fahrzeug';
    }

    public static function icon(array $entry, array $vehicle = []): string
    {
        $selected = $entry['vehicle_icon'] ?? 'auto';
        if (is_string($selected) && $selected !== 'auto' && isset(self::choices()[$selected])) { return $selected; }
        // Only explicitly normalized vehicle metadata may select an automatic symbol.
        // Alarm models, arbitrary car_type numbers and user-entered names are not body types.
        $reported = $vehicle['vehicleType'] ?? null;
        return is_string($reported) && $reported !== 'auto' && isset(self::choices()[$reported]) ? $reported : 'car';
    }

    public static function iconLabel(string $icon): string
    {
        return self::choices()[$icon] ?? self::choices()['car'];
    }

    public static function artwork(string $icon): string
    {
        require_once __DIR__ . '/VehicleArtwork.php';
        return vehicleArtwork($icon);
    }
}
