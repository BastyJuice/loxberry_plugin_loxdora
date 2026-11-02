<?php
declare(strict_types=1);

// Anonymous fixtures only. No Pandora network calls or physical commands.
require_once __DIR__ . '/../bin/lib/PandoraClient.php';

use PandoraLox\PandoraClient;
use PandoraLox\PandoraException;

final class PandoraClientFixture
{
    public array $session = [];
    public array $calls = [];
    public array $permissions = ['status' => 3, 'tracks' => 3, 'events' => 3, 'control' => 3];
    public array $profile = [];
    public array $telemetry = [];
    public array $history = ['lenta' => []];
    public array $lenta = [];
    public array $ucr = ['100' => 3];
    public float $now = 1791979200.0;
    public float $recorded;
    public string $flags = '0';
    public bool $removed = false;
    public bool $expired = false;
    public bool $historyFails = false;
    public bool $pollFails = false;
    public bool $effect = true;
    public int $sends = 0;
    public PandoraClient $client;

    public function __construct(array $options = [])
    {
        $this->recorded = $this->now - 2;
        $this->client = new PandoraClient([$this, 'transport'], array_replace([
            'now' => fn (): float => $this->now,
            'command_timeout' => 0.006, 'poll_seconds' => 0.001,
        ], $options));
        $this->session = $this->client->login('fixture-account', 'fixture-password');
    }

    public function transport(string $method, string $path, ?array $data, array &$session, bool $login)
    {
        $this->calls[] = [$method, $path];
        if ($path === '/api/users/login') {
            pandoraEqual($data, ['login' => 'fixture-account', 'password' => 'fixture-password', 'lang' => 'ru']);
            pandoraEqual($login, true);
            $session['cookies'] = ['fixture-cookie'];
            return ['status' => 'success', 'session_id' => 'PRIVATE-SESSION'];
        }
        if ($this->expired) { return ['status' => 'sid-expired', 'error_text' => 'PRIVATE-ERROR']; }
        if ($path === '/api/devices') {
            return $this->removed ? [] : [array_replace(['id' => 100, 'name' => 'Testfahrzeug', 'type' => 'alarm',
                'phone' => 'PRIVATE-PHONE', 'permissions' => $this->permissions,
                'features' => ['heater' => 1, 'channel' => 1, 'autostart' => 1]], $this->profile)];
        }
        if (strpos($path, '/api/updates?') === 0) {
            if ($this->sends && $this->pollFails) { throw new PandoraException(502, 'Simulierter Verbindungsfehler.'); }
            return ['ts' => (int) $this->now,
                'stats' => ['100' => array_replace(['online' => 1, 'move' => 0, 'speed' => 0,
                    'dtime_rec' => $this->recorded, 'bit_state_1' => $this->flags,
                    'x' => 54.3, 'y' => 10.1, 'fuel' => 0, 'out_temp' => 0], $this->telemetry)],
                'time' => ['100' => ['online' => $this->now]], 'lenta' => $this->lenta,
                'ucr' => $this->sends ? $this->ucr : null];
        }
        if (strpos($path, '/api/lenta?') === 0) {
            if ($this->historyFails) { throw new PandoraException(502, 'Simulierter Historienfehler.'); }
            return $this->history;
        }
        if ($path === '/api/devices/command') {
            ++$this->sends;
            pandoraEqual($data['id'], '100');
            if ($this->effect) {
                $targets = ['1' => '1', '2' => '0', '21' => '536870912', '22' => '0'];
                $this->flags = $targets[$data['command']] ?? $this->flags;
                $this->recorded = $this->now;
            }
            return ['action_result' => ['100' => 'sent']];
        }
        throw new RuntimeException('Unerwarteter Fixture-Pfad.');
    }

    public function event(string $id, string $vehicleId = '100', ?float $time = null): array
    {
        return ['type' => 0, 'time' => $this->now + 3600, 'obj' => ['id' => $id, 'dev_id' => $vehicleId,
            'dtime_rec' => $time ?? $this->now - 10, 'eventid1' => 3, 'eventid2' => 1,
            'body' => 'PRIVATE-EVENT', 'x' => 1, 'y' => 2]];
    }
}

function pandoraEqual($actual, $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('Fixture assertion failed: ' . var_export($actual, true) . ' != ' . var_export($expected, true));
    }
}

function pandoraReject(int $status, callable $operation): void
{
    try { $operation(); }
    catch (PandoraException $error) {
        pandoraEqual($error->getStatus(), $status);
        pandoraEqual(strpos($error->getMessage(), 'PRIVATE-'), false);
        return;
    }
    throw new RuntimeException('Expected neutral API error.');
}

$pandoraTests = [];
$pandoraTests['private projection, zero values and credential disposal'] = function (): void {
    $f = new PandoraClientFixture();
    $vehicle = $f->client->vehicles($f->session)['100'];
    pandoraEqual($vehicle['status']['fuelPercent'], 0.0);
    pandoraEqual($vehicle['status']['outsideTemperature'], 0.0);
    pandoraEqual(count($vehicle['commands']), 6);
    pandoraEqual(strpos(json_encode($vehicle), 'PRIVATE-'), false);
    pandoraEqual(strpos(json_encode($f->session), 'fixture-password'), false);
    pandoraEqual(strpos(json_encode($f->session), 'PRIVATE-SESSION'), false);
};
$pandoraTests['source vehicle name and unmapped type code remain separate from icons'] = function (): void {
    $f = new PandoraClientFixture();
    $f->profile = ['name' => 'Synthetisches Testfahrzeug', 'model' => 'Synthetisches Systemmodell', 'car_type' => 17];
    $vehicle = $f->client->vehicles($f->session)['100'];
    pandoraEqual($vehicle['name'], 'Synthetisches Testfahrzeug');
    pandoraEqual($vehicle['model'], 'Synthetisches Systemmodell');
    pandoraEqual($vehicle['carType'], 17);
    pandoraEqual($vehicle['vehicleType'], null);
    // These deliberately misleading synthetic names are never classifications.
    $f->profile = ['name' => 'Camper Motorrad Sportwagen Van', 'model' => 'Camper Pro', 'car_type' => 0,
        'icon' => 'motorhome', 'vehicle_type' => 'motorcycle'];
    $vehicle = $f->client->vehicles($f->session)['100'];
    pandoraEqual($vehicle['carType'], 0);
    pandoraEqual($vehicle['vehicleType'], null);
    pandoraEqual(isset($vehicle['icon']), false);
    foreach ([null, -1, true, 1.5, '0', ['invalid']] as $invalidCode) {
        $f->profile['car_type'] = $invalidCode;
        pandoraEqual($f->client->vehicles($f->session)['100']['carType'], null);
    }
};
$pandoraTests['decimal bits work for 32-bit and 64-bit platform limits'] = function (): void {
    pandoraEqual(PandoraClient::flag(['bit_state_1' => '18446744073709551615'], 29), true);
    pandoraEqual(PandoraClient::flag(['bit_state_1' => '536870912'], 29), true);
    pandoraEqual(PandoraClient::flag(['bit_state_1' => '536870912'], 0), false);
    pandoraEqual(PandoraClient::flag(['bit_state_1' => '0'], 29), false);
    foreach ([null, -1, 1.0, '1.0', '01', 'invalid'] as $value) {
        pandoraEqual(PandoraClient::flag(['bit_state_1' => $value], 0), null);
    }
};
$pandoraTests['nested values merge and explicit null clears telemetry'] = function (): void {
    $f = new PandoraClientFixture();
    $f->telemetry = ['voltage' => 12, 'can' => ['voltage' => 13, 'cabin_temp' => 20],
        'heater' => ['voltage' => 14, 'bit_state_1' => 1]];
    $v = $f->client->vehicles($f->session)['100'];
    pandoraEqual($v['status']['batteryVoltage'], 14.0);
    pandoraEqual($v['status']['armed'], false);
    $f->telemetry = ['voltage' => null];
    $v = $f->client->vehicles($f->session)['100'];
    pandoraEqual($v['status']['batteryVoltage'], null);
    pandoraEqual($v['status']['cabinTemperature'], 20.0);
};
$pandoraTests['permission loss removes position and history cache'] = function (): void {
    $f = new PandoraClientFixture();
    $f->history = ['lenta' => [$f->event('one')]];
    $f->client->events($f->session, '100');
    $f->permissions['tracks'] = $f->permissions['events'] = 0;
    $v = $f->client->vehicles($f->session)['100'];
    pandoraEqual($v['position'], null);
    pandoraEqual(isset($f->session['stats']['100']['x']), false);
    pandoraEqual(isset($f->session['history_cache']['100']), false);
    pandoraReject(404, function () use ($f) { $f->client->events($f->session, '100'); });
};
$pandoraTests['history bounds, deduplication, scope and fallback'] = function (): void {
    $f = new PandoraClientFixture();
    $f->history = ['lenta' => [$f->event('same'), $f->event('foreign', '200'), $f->event('old', '100', $f->now - 8 * 86400)]];
    $f->lenta = [$f->event('same'), $f->event('live')];
    $history = $f->client->events($f->session, '100');
    pandoraEqual($history['historyAvailable'], true);
    pandoraEqual(count($history['events']), 2);
    pandoraEqual($history['events'][0]['title'], 'Alarm gemeldet');
    pandoraEqual(strpos(json_encode($history), 'PRIVATE-'), false);
    $f = new PandoraClientFixture();
    $f->historyFails = true;
    $f->lenta = [$f->event('live')];
    $history = $f->client->events($f->session, '100');
    pandoraEqual($history['historyAvailable'], false);
    pandoraEqual(count($history['events']), 1);
};
$pandoraTests['unsupported, revoked, stale, offline and foreign commands never send'] = function (): void {
    foreach ([['online' => 0], ['dtime_rec' => 1791979200 - 61], ['dtime_rec' => null], ['dtime_rec' => 1791979200 + 6]] as $stats) {
        $f = new PandoraClientFixture();
        $f->telemetry = $stats;
        pandoraReject(403, function () use ($f) { $f->client->command($f->session, '100', 'lock'); });
        pandoraEqual($f->sends, 0);
    }
    $f = new PandoraClientFixture();
    foreach (['start_engine', 'stop_engine', 'unknown'] as $command) {
        pandoraReject(403, function () use ($f, $command) { $f->client->command($f->session, '100', $command); });
    }
    pandoraReject(404, function () use ($f) { $f->client->command($f->session, '200', 'lock'); });
    $f->permissions['control'] = 2;
    pandoraReject(403, function () use ($f) { $f->client->command($f->session, '100', 'lock'); });
    $f->removed = true;
    pandoraReject(404, function () use ($f) { $f->client->command($f->session, '100', 'lock'); });
    pandoraEqual($f->sends, 0);
    pandoraEqual($f->session['stats'], []);
};
$pandoraTests['matching fresh changed state confirms one send'] = function (): void {
    foreach (['lock', 'heater_on'] as $command) {
        $f = new PandoraClientFixture();
        pandoraEqual($f->client->command($f->session, '100', $command)['status'], 'confirmed');
        pandoraEqual($f->sends, 1);
    }
};
$pandoraTests['wrong ack and unchanged target never confirm'] = function (): void {
    $f = new PandoraClientFixture();
    $f->ucr = ['200' => 3];
    pandoraEqual($f->client->command($f->session, '100', 'lock')['status'], 'timeout');
    pandoraEqual($f->sends, 1);
    $f = new PandoraClientFixture();
    $f->effect = false;
    pandoraEqual($f->client->command($f->session, '100', 'lock')['status'], 'timeout');
    pandoraEqual($f->sends, 1);
};
$pandoraTests['channel ack and lost response report sent without repeat'] = function (): void {
    $f = new PandoraClientFixture();
    pandoraEqual($f->client->command($f->session, '100', 'channel_on')['status'], 'sent');
    pandoraEqual($f->sends, 1);
    $f = new PandoraClientFixture();
    $f->pollFails = true;
    pandoraEqual($f->client->command($f->session, '100', 'unlock')['status'], 'sent');
    pandoraEqual($f->sends, 1);
};
$pandoraTests['upstream expiry is neutral and cannot trigger a send'] = function (): void {
    $f = new PandoraClientFixture();
    $f->expired = true;
    pandoraReject(401, function () use ($f) { $f->client->vehicles($f->session); });
    pandoraReject(401, function () use ($f) { $f->client->command($f->session, '100', 'unlock'); });
    pandoraEqual($f->sends, 0);
};

foreach ($pandoraTests as $name => $test) {
    $test();
    echo 'OK ' . $name . PHP_EOL;
}
echo count($pandoraTests) . ' Pandora client fixture groups passed.' . PHP_EOL;
