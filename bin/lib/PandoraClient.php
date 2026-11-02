<?php
declare(strict_types=1);

namespace PandoraLox;

/**
 * Native cURL facade, ported from the existing pandora-nord.de portal.
 * Store sessions outside the web root. They contain cookies but no password.
 * Injectable transport: function(method, path, data, array &$session, login).
 */
final class PandoraClient
{
    private ?\Closure $transport;
    private array $options;

    public function __construct(?callable $transport = null, array $options = [])
    {
        $this->transport = $transport !== null ? \Closure::fromCallable($transport) : null;
        $this->options = $options;
    }

    public static function commands(): array
    {
        return [
            'lock' => ['id' => '1', 'label' => 'Schutz aktivieren'],
            'unlock' => ['id' => '2', 'label' => 'Schutz deaktivieren'],
            'heater_on' => ['id' => '21', 'label' => 'Standheizung einschalten'],
            'heater_off' => ['id' => '22', 'label' => 'Standheizung ausschalten'],
            'channel_on' => ['id' => '33', 'label' => 'Zusatzkanal einschalten'],
            'channel_off' => ['id' => '34', 'label' => 'Zusatzkanal ausschalten'],
        ];
    }

    public static function flag(array $stats, int $bit): ?bool { return PandoraProtocol::flag($stats, $bit); }
    public static function number($value): ?float { return PandoraProtocol::number($value); }
    public static function binary($value): ?bool { return PandoraProtocol::binary($value); }
    public static function timestamp($value): ?string { return PandoraProtocol::timestamp($value); }

    private function protocol(array &$session): PandoraProtocol
    {
        $request = function (string $method, string $path, ?array $data = null, bool $login = false) use (&$session) {
            if ($this->transport !== null) {
                return ($this->transport)($method, $path, $data, $session, $login);
            }
            return $this->nativeRequest($method, $path, $data, $session, $login);
        };
        return new PandoraProtocol($session, array_replace([
            'allow_commands' => true, 'cache_seconds' => 0,
            'command_timeout' => 8, 'poll_seconds' => 1,
        ], $this->options), $request);
    }

    public function login(string $login, string $password): array
    {
        if ($login === '' || $password === '' || strlen($login) > 320 || strlen($password) > 2048) {
            throw new PandoraException(400, 'Bitte gültige Pandora-Zugangsdaten eingeben.');
        }
        $session = ['cookies' => []];
        $this->protocol($session)->login($login, $password);
        return $session;
    }

    public function vehicles(array &$session): array
    {
        $protocol = $this->protocol($session);
        $protocol->refresh(true);
        return $this->currentVehicles($session);
    }

    /** Project the latest session after a history or command refresh, without I/O. */
    public function currentVehicles(array &$session): array
    {
        $vehicles = [];
        foreach ($this->protocol($session)->vehicles() as $vehicle) {
            $vehicles[$vehicle['id']] = $vehicle;
        }
        return $vehicles;
    }

    public function updates(array &$session): array { return $this->vehicles($session); }
    public function events(array &$session, string $deviceId): array { return $this->protocol($session)->events($deviceId); }
    public function command(array &$session, string $deviceId, string $command): array
    {
        return $this->protocol($session)->command($deviceId, $command);
    }

    private function nativeRequest(string $method, string $path, ?array $data, array &$session, bool $login)
    {
        if (!extension_loaded('curl')) {
            throw new PandoraException(503, 'Die vorhandene PHP-cURL-Erweiterung ist nicht verfügbar.');
        }
        $parts = parse_url($path);
        $allowed = ['GET' => ['/api/devices', '/api/updates', '/api/lenta'],
            'POST' => ['/api/users/login', '/api/devices/command']];
        if (!is_array($parts) || isset($parts['host']) || isset($parts['scheme']) || isset($parts['fragment'])
            || strlen($path) > 1024 || !in_array($parts['path'] ?? '', $allowed[$method] ?? [], true)
            || ($login !== (($parts['path'] ?? '') === '/api/users/login'))) {
            throw new PandoraException(502, 'Die Pandora-Anfrage konnte nicht ausgeführt werden.');
        }
        $curl = curl_init('https://p-on.ru' . $path);
        if ($curl === false) {
            throw new PandoraException(503, 'Die Pandora-Verbindung konnte nicht vorbereitet werden.');
        }
        $body = '';
        $tooLarge = false;
        curl_setopt_array($curl, [
            CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => 3000, CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Fedora; Linux x86_64; rv:79.0) Gecko/20100101 Firefox/79.0',
            CURLOPT_ENCODING => '', CURLOPT_COOKIEFILE => '', CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 2097152) { $tooLarge = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        foreach ($session['cookies'] ?? [] as $cookie) {
            if (is_string($cookie) && strlen($cookie) <= 8192 && strpos($cookie, "\n") === false && strpos($cookie, "\r") === false) {
                curl_setopt($curl, CURLOPT_COOKIELIST, $cookie);
            }
        }
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data ?? [], '', '&', PHP_QUERY_RFC3986));
        }
        try {
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $cookies = curl_getinfo($curl, CURLINFO_COOKIELIST);
            $session['cookies'] = is_array($cookies) ? array_slice($cookies, 0, 100) : [];
            if ($ok === false || $tooLarge) {
                throw new PandoraException(502, 'Pandora ist derzeit nicht erreichbar. Bitte später erneut versuchen.');
            }
            if ($status === 401 || $status === 403) {
                throw new UpstreamExpired($login ? 'Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.' : 'Die Pandora-Sitzung ist abgelaufen.');
            }
            if ($status < 200 || $status >= 300) {
                throw new PandoraException(502, 'Pandora ist derzeit nicht erreichbar. Bitte später erneut versuchen.');
            }
            try {
                $typed = json_decode($body, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\Throwable $error) {
                throw new PandoraException(502, 'Pandora hat keine gültige Antwort geliefert.');
            }
            if (!is_array($payload) || (($parts['path'] ?? '') === '/api/devices' && !is_array($typed))) {
                throw new PandoraException(502, 'Pandora hat keine gültige Antwort geliefert.');
            }
            if (is_object($typed) && property_exists($typed, 'lenta') && !is_array($typed->lenta)) {
                $payload['lenta'] = $typed->lenta;
            }
            if (in_array($payload['status'] ?? null, ['sid-expired', 'expired'], true)) { throw new UpstreamExpired(); }
            if ($login && (($payload['status'] ?? null) !== 'success' || $session['cookies'] === [])) {
                throw new UpstreamExpired('Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.');
            }
            return $payload;
        } finally {
            curl_close($curl);
        }
    }
}


/** Neutral errors only; no private Pandora response bodies are exposed. */
class PandoraException extends \RuntimeException
{
    public int $status;
    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
    public function getStatus(): int { return $this->status; }
}

class UpstreamExpired extends PandoraException
{
    public function __construct(string $message = 'Die Pandora-Sitzung ist abgelaufen. Bitte erneut anmelden.')
    {
        parent::__construct(401, $message);
    }
}

/** A command request was attempted; repeating it could duplicate a physical action. */
class CommandUncertain extends PandoraException
{
    public function __construct()
    {
        parent::__construct(502, 'Befehl möglicherweise übermittelt. Bitte den Fahrzeugstatus prüfen. Keine automatische Wiederholung.');
    }
}

/**
 * PHP 7.4 port of the existing pandora-nord.de/services/pandora-connect-php client.
 * The original portal is not a runtime dependency.
 * Fixed-path Pandora protocol. The transport owns the private cookie jar and
 * validates HTTP/JSON responses; this class only persists bounded account data.
 * Ported from services/pandora-connect/connect/{pandora,event_history,event_labels}.py.
 */
final class PandoraProtocol
{
    private const COMMAND_IDS = [
        'lock' => '1', 'unlock' => '2', 'heater_on' => '21', 'heater_off' => '22',
        'channel_on' => '33', 'channel_off' => '34',
    ];
    private const TELEMETRY = ['online', 'move', 'speed', 'bit_state_1', 'dtime_rec', 'x', 'y',
        'voltage', 'fuel', 'cabin_temp', 'engine_temp', 'out_temp', 'gsm_level', 'mileage_CAN'];
    private const DISPLAY_TELEMETRY = ['mileage_CAN', 'fuel', 'cabin_temp', 'engine_temp', 'out_temp', 'voltage', 'gsm_level'];
    private const HISTORY_LIMIT = 50;
    private const HISTORY_SECONDS = 7 * 86400;
    private const HISTORY_CACHE_SECONDS = 30;
    private const AVAILABLE_NOTE = 'Verfügbare Pandora-Meldungen und Fahrteinträge der letzten 7 Tage, höchstens 50 Einträge. Dies ist keine vollständige Historie. Ohne verifizierten Aufzeichnungszeitpunkt bleibt das Datum unbekannt; unbekannte Ereigniscodes bleiben sichtbar.';
    private const UNAVAILABLE_NOTE = 'Die Pandora-Historie ist derzeit nicht verfügbar. Angezeigt werden nur während dieser Anmeldung empfangene Meldungen und Fahrteinträge, höchstens 50 Einträge. Dies ist keine vollständige Historie.';
    // Fixed reference: alryaz/python-pandora-cas PrimaryEventID, commit
    // 16d1e42f758578cd37ac2037a8582e90cd79ce46. Secondary meanings remain unknown.
    private const PRIMARY_LABELS = [
        1 => ['Schutz aktiviert', 'info'], 2 => ['Schutz deaktiviert', 'info'], 3 => ['Alarm gemeldet', 'alarm'],
        4 => ['Motor gestartet', 'info'], 5 => ['Motor gestoppt', 'info'], 6 => ['Motor blockiert', 'info'],
        7 => ['Servicemodus aktiviert', 'info'], 8 => ['Einstellungen geändert', 'info'], 9 => ['Betankung gemeldet', 'info'],
        10 => ['Kollision gemeldet', 'alarm'], 11 => ['Mobilfunkverbindung gemeldet', 'info'], 12 => ['Notruf gemeldet', 'alarm'],
        13 => ['Motorstart fehlgeschlagen', 'warning'], 14 => ['Tracking aktiviert', 'info'], 15 => ['Tracking deaktiviert', 'info'],
        16 => ['Stromversorgung unterbrochen', 'warning'], 17 => ['Kofferraumöffnung gemeldet', 'info'],
        18 => ['Produktionstest gemeldet', 'info'], 19 => ['Spannungseinbruch gemeldet', 'warning'], 20 => ['CHECK-Anfrage empfangen', 'info'],
        29 => ['Systemanmeldung gemeldet', 'info'], 32 => ['Aktive Überwachung aktiviert', 'info'], 33 => ['Aktive Überwachung deaktiviert', 'info'],
        34 => ['Alarm der aktiven Überwachung', 'alarm'], 35 => ['Standheizung aktiviert', 'info'], 36 => ['Standheizung deaktiviert', 'info'],
        37 => ['Unebene Straße gemeldet', 'info'], 38 => ['Fahrereignis gemeldet', 'info'], 40 => ['Motorlaufzeit verlängert', 'info'],
        41 => ['Servicemodus deaktiviert', 'info'], 42 => ['Mobilfunkkanal aktiviert', 'info'], 43 => ['Mobilfunkkanal deaktiviert', 'info'],
        48 => ['NAV-11-Status gemeldet', 'info'], 166 => ['Fehlercodes angefordert', 'info'],
        167 => ['Lesen der Fehlercodes fehlgeschlagen', 'warning'], 168 => ['Lesen der Fehlercodes aktiv', 'info'],
        169 => ['Löschen der Fehlercodes angefordert', 'info'], 170 => ['Löschen der Fehlercodes aktiv', 'info'],
        176 => ['Systemmeldung', 'info'], 177 => ['Energiesparmodus aktiviert', 'info'], 178 => ['Energiesparmodus deaktiviert', 'info'],
        179 => ['Niedriger Reifendruck gemeldet', 'warning'], 220 => ['Bluetooth-Status gemeldet', 'info'],
        230 => ['Transponder erforderlich', 'info'], 231 => ['Transponderanforderung deaktiviert', 'info'],
        232 => ['Transponderabfrage aktiviert', 'info'], 233 => ['Transponderabfrage deaktiviert', 'info'], 250 => ['Positionspunkt gemeldet', 'info'],
    ];

    private array $state;
    private array $config;
    private \Closure $transport;
    private bool $commandBusy = false;

    public function __construct(array &$state, array $config, callable $transport)
    {
        $this->state =& $state;
        $this->config = $config;
        $this->transport = \Closure::fromCallable($transport);
        foreach (['devices', 'stats', 'times', 'events', 'history_cache'] as $bucket) {
            if (!isset($this->state[$bucket]) || !is_array($this->state[$bucket])) {
                $this->state[$bucket] = [];
            }
        }
        $this->state['cursor'] = max(0, (int) (self::number($this->state['cursor'] ?? null) ?? 0));
        $this->state['last_fetch'] = self::number($this->state['last_fetch'] ?? null) ?? 0.0;
    }

    private function now(): float
    {
        return isset($this->config['now']) && is_callable($this->config['now'])
            ? (float) ($this->config['now'])() : microtime(true);
    }

    private static function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function requireActive(): void
    {
        if (isset($this->config['session_active']) && is_callable($this->config['session_active'])
            && !($this->config['session_active'])()) {
            throw new UpstreamExpired();
        }
    }

    private function request(string $method, string $path, ?array $data = null, bool $login = false)
    {
        $this->requireActive();
        try {
            $payload = ($this->transport)($method, $path, $data, $login);
        } catch (PandoraException $error) {
            $this->requireActive();
            throw $error;
        }
        $this->requireActive();
        if (is_array($payload)) {
            $status = $payload['status'] ?? null;
            if (is_string($status) && in_array($status, ['Session is expired', 'Invalid session', 'sid-expired'], true)) {
                throw new UpstreamExpired();
            }
            if ($status === 'fail') {
                // error_text may contain private account/session information.
                throw new PandoraException($login ? 401 : 502, $login ? 'Anmeldung fehlgeschlagen.' : 'Pandora hat die Anfrage abgelehnt.');
            }
        }
        return $payload;
    }

    public function login(string $username, string $password): void
    {
        $payload = $this->request('POST', '/api/users/login', ['login' => $username, 'password' => $password, 'lang' => 'ru'], true);
        // The transport must additionally require a nonempty private cookie jar.
        // No password or upstream session_id is copied into persistent state.
        if (!is_array($payload) || ($payload['status'] ?? null) !== 'success') {
            throw new PandoraException(401, 'Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.');
        }
        $this->refresh(true);
    }

    public function refresh(bool $force = false): void
    {
        $this->requireActive();
        $now = $this->now();
        $cache = max(0.0, (float) ($this->config['cache_seconds'] ?? 5));
        $age = $now - (float) $this->state['last_fetch'];
        if (!$force && $this->state['last_fetch'] && $age >= 0 && $age < $cache) {
            return;
        }
        $devices = $this->request('GET', '/api/devices');
        if (!is_array($devices) || !self::isList($devices) || count($devices) > 200) {
            throw new PandoraException(502, 'Pandora hat ein unbekanntes Antwortformat geliefert.');
        }
        $owned = [];
        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = self::identifier($device['id'] ?? null);
            if ($id !== null) {
                $owned[$id] = $device;
            }
        }
        $this->state['devices'] = $owned;
        foreach (['stats', 'times', 'events', 'history_cache'] as $bucket) {
            $this->state[$bucket] = array_intersect_key($this->state[$bucket], $owned);
        }
        foreach ($owned as $key => $info) {
            if (!self::permission($info, 'status')) {
                unset($this->state['stats'][$key], $this->state['times'][$key]);
            } elseif (!self::permission($info, 'tracks')) {
                unset($this->state['stats'][$key]['x'], $this->state['stats'][$key]['y'], $this->state['stats'][$key]['_position_time']);
            }
            if (!self::permission($info, 'events')) {
                unset($this->state['events'][$key], $this->state['history_cache'][$key]);
            }
        }
        $this->update();
        $this->state['last_fetch'] = $this->now();
    }

    private function update()
    {
        return $this->applyUpdate($this->request('GET', '/api/updates?ts=' . ($this->state['cursor'] - 1)));
    }

    private function applyUpdate($payload)
    {
        if (!is_array($payload)) {
            throw new PandoraException(502, 'Pandora hat ein unbekanntes Antwortformat geliefert.');
        }
        $stats = $payload['stats'] ?? [];
        $times = $payload['time'] ?? [];
        $lenta = $payload['lenta'] ?? [];
        if (!is_array($stats) || !is_array($times) || !is_array($lenta) || !self::isList($lenta)) {
            throw new PandoraException(502, 'Pandora hat ein unbekanntes Antwortformat geliefert.');
        }
        $cursor = self::number($payload['ts'] ?? null);
        if ($cursor !== null && $cursor >= 0 && $cursor <= PHP_INT_MAX) {
            $this->state['cursor'] = (int) $cursor;
        }
        foreach ($stats as $key => $updates) {
            $info = $this->state['devices'][$key] ?? null;
            if (!is_array($info) || !self::permission($info, 'status') || !is_array($updates)) {
                continue;
            }
            $safe = array_intersect_key($updates, array_flip(self::TELEMETRY));
            foreach (['can', 'heater'] as $group) {
                if (isset($updates[$group]) && is_array($updates[$group])) {
                    $safe = array_replace($safe, array_intersect_key($updates[$group], array_flip(self::DISPLAY_TELEMETRY)));
                }
            }
            if (!self::permission($info, 'tracks')) {
                unset($safe['x'], $safe['y']);
            } elseif (array_key_exists('x', $safe) && array_key_exists('y', $safe)) {
                $safe['_position_time'] = $safe['dtime_rec'] ?? null;
            } else {
                // Keep a previous coordinate pair and its original recording time.
                unset($safe['x'], $safe['y']);
            }
            $this->state['stats'][$key] = array_replace($this->state['stats'][$key] ?? [], $safe);
        }
        foreach ($times as $key => $updates) {
            $info = $this->state['devices'][$key] ?? null;
            if (is_array($info) && self::permission($info, 'status') && is_array($updates)) {
                $this->state['times'][$key] = array_replace($this->state['times'][$key] ?? [], $updates);
            }
        }
        foreach (array_slice($lenta, 0, 200) as $item) {
            $key = is_array($item) && is_array($item['obj'] ?? null) ? self::identifier($item['obj']['dev_id'] ?? null) : null;
            $info = $key !== null ? ($this->state['devices'][$key] ?? null) : null;
            if (!is_array($info) || !self::permission($info, 'events')) {
                continue;
            }
            $event = self::normaliseEvent($item, $key);
            if ($event === null) {
                continue;
            }
            $this->state['events'][$key][$event['id']] = $event;
            if (count($this->state['events'][$key]) > 100) {
                $this->state['events'][$key] = array_slice($this->state['events'][$key], -100, null, true);
            }
        }
        return $payload['ucr'] ?? null;
    }

    public function vehicles(): array
    {
        $this->requireActive();
        $result = [];
        $now = $this->now();
        foreach ($this->state['devices'] as $id => $info) {
            $result[] = $this->vehicleView((string) $id, $info, $this->state['stats'][$id] ?? [], $this->state['times'][$id] ?? [], $now);
        }
        return $result;
    }

    private function vehicleView(string $id, array $info, array $stats, array $times, float $now): array
    {
        if (!self::permission($info, 'status')) {
            $stats = $times = [];
        }
        $lat = self::number($stats['x'] ?? null);
        $lon = self::number($stats['y'] ?? null);
        $position = null;
        if (self::permission($info, 'tracks') && $lat !== null && $lon !== null
            && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180 && ($lat != 0 || $lon != 0)) {
            $position = ['latitude' => $lat, 'longitude' => $lon,
                'updatedAt' => self::timestamp(array_key_exists('_position_time', $stats) ? $stats['_position_time'] : ($stats['dtime_rec'] ?? null))];
        }
        $doors = array_map(fn (int $bit): ?bool => self::flag($stats, $bit), [21, 22, 23, 24]);
        $mileage = self::number($stats['mileage_CAN'] ?? null);
        $carType = $info['car_type'] ?? null;
        return [
            'id' => $id, 'name' => self::text($info['name'] ?? null, 'Pandora-Fahrzeug'),
            'model' => !empty($info['model']) ? self::text($info['model']) : null,
            // The captured device schema has an integer car_type, but its enum
            // meanings are unverified. Preserve the code without guessing a body
            // style from an alarm product model or a customer's vehicle name.
            'carType' => is_int($carType) && $carType >= 0 ? $carType : null,
            'vehicleType' => null,
            'online' => self::online($stats, $times, $now), 'updatedAt' => self::timestamp($stats['dtime_rec'] ?? null), 'position' => $position,
            'status' => [
                'armed' => self::flag($stats, 0), 'alarm' => self::flag($stats, 1), 'engineRunning' => self::flag($stats, 2),
                'ignition' => self::flag($stats, 3), 'moving' => self::moving($stats),
                'doorsOpen' => in_array(null, $doors, true) ? null : in_array(true, $doors, true),
                'trunkOpen' => self::flag($stats, 25), 'hoodOpen' => self::flag($stats, 26), 'heaterOn' => self::flag($stats, 29),
                'batteryVoltage' => self::number($stats['voltage'] ?? null), 'fuelPercent' => self::number($stats['fuel'] ?? null),
                'cabinTemperature' => self::number($stats['cabin_temp'] ?? null), 'engineTemperature' => self::number($stats['engine_temp'] ?? null),
                'outsideTemperature' => self::number($stats['out_temp'] ?? null), 'speed' => self::number($stats['speed'] ?? null),
                'gsmLevel' => self::number($stats['gsm_level'] ?? null), 'canMileageKm' => $mileage !== null && $mileage >= 0 ? $mileage : null,
            ],
            // Display capabilities independently of the global execution switch.
            'commands' => $this->scopedCommands($id, self::capabilities($info, $stats, $times, $now)),
        ];
    }

    private function requireVehicle(string $id): void
    {
        if (!array_key_exists($id, $this->state['devices'])) {
            throw new PandoraException(404, 'Fahrzeug nicht verfügbar.');
        }
    }

    private function requireEvents(string $id): void
    {
        $this->requireVehicle($id);
        if (!self::permission($this->state['devices'][$id], 'events')) {
            throw new PandoraException(404, 'Fahrzeugmeldungen nicht verfügbar.');
        }
    }

    public function events(string $deviceId): array
    {
        // Refresh permission/membership even for cached history.
        $this->refresh(true);
        $this->requireActive();
        $this->requireEvents($deviceId);
        $now = $this->now();
        $start = $now - self::HISTORY_SECONDS;
        $snapshot = $this->state['history_cache'][$deviceId] ?? null;
        $age = is_array($snapshot) ? $now - (float) ($snapshot['fetched_at'] ?? 0) : INF;
        if (!is_array($snapshot) || $age < 0 || $age >= self::HISTORY_CACHE_SECONDS) {
            $query = http_build_query(['from' => (int) $start, 'to' => (int) $now + 86400, 'id' => $deviceId, 'limit' => self::HISTORY_LIMIT], '', '&', PHP_QUERY_RFC3986);
            try {
                $payload = $this->request('GET', '/api/lenta?' . $query);
                $lenta = is_array($payload) ? ($payload['lenta'] ?? null) : null;
                if (!is_array($lenta) || !self::isList($lenta) || ($lenta && !array_filter(array_slice($lenta, 0, 200), [self::class, 'knownEventShape']))) {
                    throw new PandoraException(502, 'Pandora hat ein unbekanntes Historienformat geliefert.');
                }
                $projected = [];
                foreach (array_slice($lenta, 0, 200) as $item) {
                    $event = self::normaliseEvent($item, $deviceId);
                    if ($event !== null) {
                        $projected[] = $event;
                    }
                }
                [$bounded, $overflow] = self::mergeEvents($deviceId, [$projected], $start, $now);
                $snapshot = ['fetched_at' => $this->now(), 'available' => true, 'events' => $bounded,
                    'truncated' => count($lenta) >= self::HISTORY_LIMIT || $overflow];
            } catch (UpstreamExpired $error) {
                throw $error;
            } catch (PandoraException $error) {
                $this->requireActive();
                $snapshot = ['fetched_at' => $this->now(), 'available' => false, 'events' => [], 'truncated' => false];
            }
            $this->requireActive();
            $this->state['history_cache'][$deviceId] = $snapshot;
        }
        [$events, $overflow] = self::mergeEvents($deviceId, [$snapshot['events'], array_values($this->state['events'][$deviceId] ?? [])], $start, $now);
        $truncated = $snapshot['truncated'] || $overflow;
        $note = $snapshot['available'] ? self::AVAILABLE_NOTE : self::UNAVAILABLE_NOTE;
        if ($truncated) {
            $note .= ' Die Anzeigegrenze wurde erreicht; weitere Einträge können vorhanden sein.';
        }
        $this->requireActive();
        return ['events' => $events, 'historyAvailable' => $snapshot['available'], 'note' => $note, 'fetchedAt' => self::timestamp($this->now()),
            'history' => ['status' => $snapshot['available'] ? 'available' : 'unavailable', 'from' => self::timestamp($start),
                'to' => self::timestamp($now), 'limit' => self::HISTORY_LIMIT, 'truncated' => $truncated]];
    }

    public function command(string $deviceId, string $command): array
    {
        if (($this->config['allow_commands'] ?? false) !== true) {
            throw new PandoraException(403, 'Fahrzeugbefehle sind auf diesem Server noch nicht freigeschaltet.');
        }
        if ($this->commandBusy) {
            throw new PandoraException(409, 'Ein Fahrzeugbefehl wird bereits verarbeitet.');
        }
        $this->commandBusy = true;
        try {
            $this->refresh(true);
            $this->requireVehicle($deviceId);
            $allowed = $this->scopedCommands($deviceId, self::capabilities($this->state['devices'][$deviceId],
                $this->state['stats'][$deviceId] ?? [], $this->state['times'][$deviceId] ?? [], $this->now()));
            if (!isset(self::COMMAND_IDS[$command]) || !in_array($command, $allowed, true)) {
                throw new PandoraException(403, 'Dieser Befehl ist für das Fahrzeug oder seinen aktuellen Zustand nicht verfügbar.');
            }
            $this->requireActive();
            $submittedAt = $this->now();
            $before = $this->state['stats'][$deviceId] ?? [];
            try {
                $payload = $this->request('POST', '/api/devices/command', ['id' => $deviceId, 'command' => self::COMMAND_IDS[$command]]);
                $result = is_array($payload) ? ($payload['action_result'] ?? null) : null;
                if (!is_array($result) || ($result[$deviceId] ?? null) !== 'sent') {
                    throw new PandoraException(502, 'Pandora hat den Befehl nicht als gesendet bestätigt.');
                }
            } catch (\Throwable $error) {
                // A lost or malformed POST response cannot prove that the vehicle did nothing.
                throw new CommandUncertain();
            }
            // A physical command is submitted exactly once. Polls are read-only.
            $timeout = min(8.0, max(0.0, (float) ($this->config['command_timeout'] ?? 8)));
            $poll = max(0.001, (float) ($this->config['poll_seconds'] ?? 1));
            $deadline = self::monotonic() + $timeout;
            $acknowledged = false;
            while (self::monotonic() < $deadline) {
                $this->requireActive();
                $delay = min($poll, max(0.0, $deadline - self::monotonic()));
                usleep((int) ($delay * 1_000_000));
                try {
                    $ucr = $this->update();
                    $this->state['last_fetch'] = $this->now();
                } catch (UpstreamExpired $error) {
                    $this->state['cookies'] = [];
                    $this->state['last_fetch'] = 0.0;
                    return ['status' => 'sent', 'message' => 'Befehl an Pandora gesendet. Die Sitzung ist während der Rückmeldung abgelaufen; die Ausführung ist nicht bestätigt. Bitte den Status prüfen.'];
                } catch (PandoraException $error) {
                    return ['status' => 'sent', 'message' => 'Befehl an Pandora gesendet. Die Rückmeldung ist derzeit nicht erreichbar; die Ausführung ist nicht bestätigt.'];
                }
                $acknowledged = $acknowledged || (is_array($ucr) && array_key_exists($deviceId, $ucr));
                $stats = $this->state['stats'][$deviceId] ?? [];
                $targets = ['lock' => [0, true], 'unlock' => [0, false], 'heater_on' => [29, true], 'heater_off' => [29, false]];
                $target = $targets[$command] ?? null;
                $recorded = self::number($stats['dtime_rec'] ?? null);
                $previous = self::number($before['dtime_rec'] ?? null);
                if ($acknowledged && $target !== null && $recorded !== null && $previous !== null
                    && $recorded > $previous && $recorded >= (int) $submittedAt
                    && self::flag($stats, $target[0]) === $target[1] && self::flag($before, $target[0]) !== $target[1]) {
                    return ['status' => 'confirmed', 'message' => 'Pandora hat eine Rückmeldung dieses Fahrzeugs und den passenden aktualisierten Fahrzeugstatus geliefert.'];
                }
                if ($acknowledged && $target === null) {
                    return ['status' => 'sent', 'message' => 'Pandora hat eine Rückmeldung dieses Fahrzeugs geliefert. Der Zusatzkanalzustand ist über diese Anbindung nicht nachweisbar.'];
                }
            }
            return ['status' => 'timeout', 'message' => 'Befehl an Pandora gesendet. Innerhalb des Prüfzeitraums wurde die Ausführung nicht durch den Fahrzeugstatus bestätigt. Vor erneutem Senden den Status prüfen.'];
        } finally {
            $this->commandBusy = false;
        }
    }

    private function scopedCommands(string $id, array $commands): array
    {
        $ids = $this->config['control_device_ids'] ?? [];
        if ($ids && !in_array($id, array_map('strval', $ids), true)) {
            return [];
        }
        $scope = $this->config['control_commands'] ?? [];
        return array_values(array_filter($commands, fn (string $command): bool => isset(self::COMMAND_IDS[$command]) && (!$scope || in_array($command, $scope, true))));
    }

    private static function capabilities(array $info, array $stats, array $times, float $now): array
    {
        if (!self::permission($info, 'control') || !self::permission($info, 'status') || self::online($stats, $times, $now) !== true) {
            return [];
        }
        $recorded = self::number($stats['dtime_rec'] ?? null);
        if ($recorded === null || $now - $recorded < -5 || $now - $recorded > 60) {
            return [];
        }
        $commands = ($info['type'] ?? null) === 'alarm' ? ['lock', 'unlock'] : [];
        $features = is_array($info['features'] ?? null) ? $info['features'] : [];
        if (self::binary($features['heater'] ?? null) === true) {
            $commands = [...$commands, 'heater_on', 'heater_off'];
        }
        if (self::binary($features['channel'] ?? null) === true) {
            $commands = [...$commands, 'channel_on', 'channel_off'];
        }
        return $commands;
    }

    private static function permission(array $info, string $name): bool
    {
        $permissions = $info['permissions'] ?? null;
        return is_array($permissions) && in_array($permissions[$name] ?? null, [3, 3.0, '3'], true);
    }

    public static function number($value): ?float
    {
        if ($value === null || is_bool($value) || !(is_int($value) || is_float($value) || is_string($value)) || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    public static function binary($value): ?bool
    {
        if (in_array($value, [true, 1, 1.0, '1'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, 0.0, '0'], true)) {
            return false;
        }
        return null;
    }

    public static function flag(array $stats, int $bit): ?bool
    {
        $raw = $stats['bit_state_1'] ?? null;
        if (!(is_int($raw) || is_string($raw)) || $bit < 0 || $bit > 63
            || !preg_match('/^(0|[1-9][0-9]{0,19})$/D', (string) $raw)) {
            return null;
        }
        // Decimal long division avoids platform-sized shifts on 32-bit Raspberry Pis.
        $digits = (string) $raw;
        for ($place = 0; $place < $bit; ++$place) {
            $quotient = '';
            $carry = 0;
            for ($index = 0; $index < strlen($digits); ++$index) {
                $value = $carry * 10 + (int) $digits[$index];
                $quotient .= (string) intdiv($value, 2);
                $carry = $value % 2;
            }
            $digits = ltrim($quotient, '0');
            if ($digits === '') {
                return false;
            }
        }
        return ((int) substr($digits, -1) % 2) === 1;
    }

    private static function online(array $stats, array $times, float $now): ?bool
    {
        $explicit = self::binary($stats['online'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        $last = self::number($times['online'] ?? null);
        return $last === null || $last <= 0 ? null : ($now - $last >= -60 && $now - $last <= 120);
    }

    private static function moving(array $stats): ?bool
    {
        $explicit = self::binary($stats['move'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        $speed = self::number($stats['speed'] ?? null);
        return $speed === null || $speed < 0 ? null : $speed > 0;
    }

    private static function text($value, string $fallback = ''): string
    {
        $value = is_scalar($value) && $value ? (string) $value : $fallback;
        // Unicode-safe even when shared hosting does not provide mbstring.
        return preg_match('/^.{0,120}/us', $value, $matches) ? $matches[0] : $fallback;
    }

    public static function timestamp($value): ?string
    {
        $number = self::number($value);
        if ($number === null || $number <= 0 || $number > 253402300799) {
            return null;
        }
        $seconds = (int) floor($number);
        $micros = (int) round(($number - $seconds) * 1_000_000);
        if ($micros === 1_000_000) {
            ++$seconds;
            $micros = 0;
        }
        return gmdate('Y-m-d\TH:i:s', $seconds) . ($micros ? sprintf('.%06d', $micros) : '') . 'Z';
    }

    private static function identifier($value): ?string
    {
        if (!(is_int($value) || is_string($value))) {
            return null;
        }
        $id = (string) $value;
        return $id !== '' && strlen($id) <= 128 ? $id : null;
    }

    private static function displayCode($value): string
    {
        return (is_int($value) || is_string($value)) && preg_match('/^.{0,40}/us', (string) $value, $matches) ? $matches[0] : '–';
    }

    private static function describeEvent($primary, $secondary): array
    {
        $code = is_int($primary) ? $primary : (is_string($primary) && preg_match('/^[0-9]{1,6}$/D', $primary) ? (int) $primary : null);
        [$title, $severity] = $code !== null ? (self::PRIMARY_LABELS[$code] ?? ['Pandora-Meldung', 'info']) : ['Pandora-Meldung', 'info'];
        $p = self::displayCode($primary);
        $s = self::displayCode($secondary);
        return ['title' => $title, 'description' => $p === '–' && $s === '–'
            ? 'Pandora-Meldung ohne dokumentierte Zuordnung.' : "Pandora-Ereigniscode {$p} / {$s}.", 'severity' => $severity];
    }

    private static function knownEventShape($item): bool
    {
        return is_array($item) && is_array($item['obj'] ?? null)
            && self::identifier($item['obj']['id'] ?? null) !== null && self::identifier($item['obj']['dev_id'] ?? null) !== null;
    }

    private static function normaliseEvent($item, string $deviceId): ?array
    {
        if (!is_array($item) || !is_array($item['obj'] ?? null)) {
            return null;
        }
        $obj = $item['obj'];
        $id = self::identifier($obj['id'] ?? null);
        if ($id === null || self::identifier($obj['dev_id'] ?? null) !== $deviceId) {
            return null;
        }
        $primary = $obj['eventid1'] ?? null;
        $secondary = $obj['eventid2'] ?? null;
        $content = self::describeEvent($primary, $secondary);
        $start = self::number($obj['start'] ?? null);
        $end = self::number($obj['end'] ?? null);
        $points = self::number($obj['points'] ?? null);
        if (($item['type'] ?? null) === 2 && $primary === null && $secondary === null && $start !== null && $start > 0
            && $end !== null && $end >= $start && $points !== null && $points >= 0 && floor($points) === $points) {
            $content = ['title' => 'Fahrteintrag', 'description' => 'Gespeicherter Fahrteintrag aus Pandora.', 'severity' => 'info'];
        }
        return array_merge(['id' => $id, 'vehicleId' => $deviceId, 'occurredAt' => self::timestamp($obj['dtime_rec'] ?? null), 'primaryCode' => self::displayCode($primary), 'secondaryCode' => self::displayCode($secondary)], $content);
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private static function mergeEvents(string $id, array $groups, float $start, float $end): array
    {
        $combined = [];
        foreach ($groups as $group) {
            foreach ($group as $event) {
                if (($event['vehicleId'] ?? null) !== $id) {
                    continue;
                }
                $recorded = $event['occurredAt'] ?? null;
                if ($recorded !== null) {
                    try {
                        $moment = (float) (new \DateTimeImmutable($recorded))->format('U.u');
                    } catch (\Exception $error) {
                        continue;
                    }
                    if ($moment < $start || $moment > $end) {
                        continue;
                    }
                }
                $previous = $combined[$event['id']] ?? null;
                if ($previous === null || ($recorded ?? '') > ($previous['occurredAt'] ?? '')) {
                    $combined[$event['id']] = $event;
                }
            }
        }
        $ordered = array_values($combined);
        usort($ordered, fn (array $a, array $b): int => strcmp($b['occurredAt'] ?? '', $a['occurredAt'] ?? ''));
        return [array_slice($ordered, 0, self::HISTORY_LIMIT), count($ordered) > self::HISTORY_LIMIT];
    }
}
