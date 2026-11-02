<?php
declare(strict_types=1);

// Local temporary storage and anonymous API fixtures only. No network or vehicle calls.
require_once __DIR__ . '/../bin/lib/Store.php';
require_once __DIR__ . '/../bin/lib/PandoraClient.php';
require_once __DIR__ . '/../bin/lib/Service.php';
require_once __DIR__ . '/../bin/lib/Topics.php';

use PandoraLox\Store;
use PandoraLox\Service;
use PandoraLox\Topics;
use PandoraLox\PandoraClient;
use PandoraLox\PandoraException;
use PandoraLox\VehicleProfile;

function serviceEqual($actual, $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('Assertion failed: ' . var_export($actual, true) . ' != ' . var_export($expected, true));
    }
}

function serviceInvalid(callable $operation): void
{
    try { $operation(); }
    catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException('Expected input validation failure.');
}

function serviceRemoveTree(string $path): void
{
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $child = $path . '/' . $entry;
        if (is_dir($child)) { serviceRemoveTree($child); } else { unlink($child); }
    }
    rmdir($path);
}

final class ServiceFixture
{
    public string $base;
    public Store $store;
    public PandoraClient $client;
    public Service $service;
    public string $id;
    public int $logins = 0;
    public int $devicesReads = 0;
    public int $sends = 0;
    public int $requiredGeneration = 1;
    public array $permissions = ['status' => 3, 'tracks' => 3, 'events' => 3, 'control' => 3];
    public array $telemetry = [];
    public bool $removed = false;
    public bool $multiple = false;
    public bool $alwaysExpire = false;
    public bool $networkFails = false;
    public bool $lostPost = false;
    public bool $expirePoll = false;
    public string $upstreamName = 'Testfahrzeug';
    public $onDevices = null;
    public float $now;

    public function __construct(string $deviceId = '100', bool $allowControl = true)
    {
        $this->base = sys_get_temp_dir() . '/pandora-service-test-' . bin2hex(random_bytes(8));
        $this->store = new Store($this->base . '/config', $this->base . '/data');
        $this->id = $this->store->saveVehicle($this->input(['device_id' => $deviceId, 'allow_control' => $allowControl]));
        $this->now = (float) time();
        $this->client = new PandoraClient([$this, 'transport'], [
            'now' => fn (): float => $this->now, 'command_timeout' => 0.006, 'poll_seconds' => 0.001,
        ]);
        $this->service = new Service($this->store, $this->client);
    }

    public function __destruct() { if (is_dir($this->base)) { serviceRemoveTree($this->base); } }

    public function input(array $changes = []): array
    {
        return array_replace(['name' => 'Testfahrzeug', 'login' => 'fixture-account', 'password' => 'fixture-private-password',
            'device_id' => '100', 'enabled' => true, 'allow_control' => true], $changes);
    }

    public function seed(): void
    {
        $session = $this->client->login('fixture-account', 'fixture-private-password');
        $this->store->writeSession($this->id, $session);
    }

    public function request(string $command = 'lock', int $age = 0): array
    {
        return ['id' => 'fixture-request', 'vehicle_id' => $this->id, 'revision' => $this->store->all()[$this->id]['revision'],
            'command' => $command, 'created_at' => time() - $age];
    }

    public function transport(string $method, string $path, ?array $data, array &$session, bool $login)
    {
        if ($path === '/api/users/login') {
            ++$this->logins;
            $session['generation'] = $this->logins;
            $session['cookies'] = ['PRIVATE-COOKIE-' . $this->logins];
            return ['status' => 'success'];
        }
        if ($this->alwaysExpire || ($session['generation'] ?? 0) < $this->requiredGeneration
            || ($this->expirePoll && $this->sends > 0)) { return ['status' => 'sid-expired']; }
        if ($this->networkFails) { throw new PandoraException(502, 'Pandora ist derzeit nicht erreichbar.'); }
        if ($path === '/api/devices') {
            ++$this->devicesReads;
            if ($this->onDevices !== null) { ($this->onDevices)($this); }
            if ($this->removed) { return []; }
            $devices = [['id' => '100', 'name' => $this->upstreamName, 'type' => 'alarm',
                'permissions' => $this->permissions, 'features' => ['heater' => 1, 'channel' => 1]]];
            if ($this->multiple) { $devices[] = array_replace($devices[0], ['id' => '200', 'name' => 'Zweites Testfahrzeug']); }
            return $devices;
        }
        if (strpos($path, '/api/updates?') === 0) {
            return ['ts' => (int) $this->now, 'stats' => ['100' => array_replace([
                'online' => 1, 'bit_state_1' => $this->sends ? '1' : '0', 'dtime_rec' => $this->now - ($this->sends ? 0 : 2),
                'move' => 0, 'speed' => 0, 'fuel' => 0, 'voltage' => 12.5, 'x' => 54.3, 'y' => 10.1,
            ], $this->telemetry)], 'time' => [], 'lenta' => [], 'ucr' => $this->sends ? ['100' => 3] : null];
        }
        if (strpos($path, '/api/lenta?') === 0) {
            return ['lenta' => [['type' => 0, 'obj' => ['id' => 'event-one', 'dev_id' => '100',
                'dtime_rec' => $this->now - 10, 'eventid1' => 3, 'eventid2' => 1]]]];
        }
        if ($path === '/api/devices/command') {
            ++$this->sends;
            if ($this->lostPost) { throw new PandoraException(502, 'Simulierter verlorener POST.'); }
            return ['action_result' => ['100' => 'sent']];
        }
        throw new RuntimeException('Unexpected fixture operation.');
    }
}

$serviceTests = [];
$serviceTests['password and cookies encrypted, public credentials projected, stable IDs'] = function (): void {
    $f = new ServiceFixture();
    $f->seed();
    serviceEqual($f->store->credentials($f->id)['password'], 'fixture-private-password');
    serviceEqual(isset($f->store->all()[$f->id]['password']), false);
    serviceEqual($f->store->all()[$f->id]['password_set'], true);
    serviceEqual(strpos(file_get_contents($f->base . '/config/vehicles.json'), 'fixture-private-password'), false);
    serviceEqual(strpos(file_get_contents($f->base . '/config/sessions/' . $f->id . '.json'), 'PRIVATE-COOKIE'), false);
    serviceEqual($f->store->session($f->id)['cookies'], ['PRIVATE-COOKIE-1']);
    serviceEqual(fileperms($f->base . '/config/secret.key') & 0777, 0600);
    serviceEqual(fileperms($f->base . '/config/vehicles.json') & 0777, 0600);
    $second = $f->store->saveVehicle($f->input(['name' => 'Zweites Fahrzeug']));
    $f->store->deleteVehicle($f->id);
    $third = $f->store->saveVehicle($f->input(['name' => 'Drittes Fahrzeug']));
    serviceEqual([$second, $third], ['fahrzeug_2', 'fahrzeug_3']);
};
$serviceTests['input validation, blank password update and session reset'] = function (): void {
    $f = new ServiceFixture();
    $f->seed();
    $f->store->saveVehicle($f->input(['id' => $f->id, 'password' => '', 'name' => 'Umbenannt']));
    serviceEqual($f->store->credentials($f->id)['password'], 'fixture-private-password');
    serviceEqual($f->store->session($f->id)['cookies'], ['PRIVATE-COOKIE-1']);
    serviceInvalid(function () use ($f) { $f->store->saveVehicle($f->input(['id' => $f->id, 'login' => 'different', 'password' => ''])); });
    serviceInvalid(function () use ($f) { $f->store->credentials('../secret'); });
    serviceInvalid(function () use ($f) { $f->store->saveVehicle($f->input(['device_id' => '100/set/lock'])); });
    serviceInvalid(function () use ($f) { $f->store->saveSettings(['poll_interval' => 1, 'topic_prefix' => 'pandora', 'enabled' => true]); });
    serviceInvalid(function () use ($f) { $f->store->saveSettings(['poll_interval' => 60, 'topic_prefix' => 'pandora/#', 'enabled' => true]); });
    $f->store->saveVehicle($f->input(['id' => $f->id, 'login' => 'replacement', 'password' => 'replacement-password']));
    serviceEqual($f->store->session($f->id), []);
    serviceEqual($f->store->state($f->id)['vehicle'], null);
};
$serviceTests['authenticated encryption rejects tampered password'] = function (): void {
    $f = new ServiceFixture();
    $path = $f->base . '/config/vehicles.json';
    $config = json_decode(file_get_contents($path), true);
    $config['vehicles'][$f->id]['password']['data'] = base64_encode('tampered');
    file_put_contents($path, json_encode($config));
    try { $f->store->credentials($f->id); }
    catch (RuntimeException $error) { return; }
    throw new RuntimeException('Tampered ciphertext must fail closed.');
};
$serviceTests['Pandora names update automatically, overrides remain optional and MQTT uses the same name'] = function (): void {
    $f = new ServiceFixture();
    $f->store->saveVehicle($f->input(['id' => $f->id, 'name' => '', 'password' => '']));
    $entry = $f->store->all()[$f->id];
    serviceEqual(VehicleProfile::displayName($entry), 'Fahrzeug 1');
    $f->upstreamName = 'Frei erfundener Pandora-Name';
    $state = $f->service->refresh($f->id);
    serviceEqual(VehicleProfile::displayName($entry, $state['vehicle']), $f->upstreamName);
    serviceEqual(Topics::snapshot('pandora', $entry, $state)['pandora/fahrzeug_1/state/name'], $f->upstreamName);
    $f->upstreamName = 'Geänderter frei erfundener Name';
    $state = $f->service->refresh($f->id);
    serviceEqual(VehicleProfile::displayName($entry, $state['vehicle']), $f->upstreamName);
    $f->store->saveVehicle($f->input(['id' => $f->id, 'name' => 'Eigener Name', 'password' => '']));
    $entry = $f->store->all()[$f->id];
    serviceEqual(VehicleProfile::displayName($entry, $state['vehicle']), 'Eigener Name');
    serviceEqual(Topics::snapshot('pandora', $entry, $state)['pandora/fahrzeug_1/state/name'], 'Eigener Name');
    $f->store->saveVehicle($f->input(['id' => $f->id, 'name' => '', 'password' => '']));
    serviceEqual(VehicleProfile::displayName($f->store->all()[$f->id], $state['vehicle']), $f->upstreamName);
    serviceEqual($f->store->session($f->id)['cookies'], ['PRIVATE-COOKIE-1']);
    serviceEqual($f->id, 'fahrzeug_1');
    $f->networkFails = true;
    $offline = $f->service->refresh($f->id);
    serviceEqual(VehicleProfile::displayName($f->store->all()[$f->id], $offline['vehicle']), $f->upstreamName);
};
$serviceTests['vehicle symbols persist safely, legacy names remain and presentation edits preserve sessions'] = function (): void {
    $f = new ServiceFixture();
    $f->seed();
    serviceEqual($f->store->all()[$f->id]['vehicle_icon'], 'auto');
    foreach (array_keys(VehicleProfile::choices()) as $icon) {
        $f->store->saveVehicle($f->input(['id' => $f->id, 'password' => '', 'vehicle_icon' => $icon]));
        serviceEqual($f->store->all()[$f->id]['vehicle_icon'], $icon);
        serviceEqual(VehicleProfile::icon($f->store->all()[$f->id]), $icon === 'auto' ? 'car' : $icon);
        serviceEqual($f->store->session($f->id)['cookies'], ['PRIVATE-COOKIE-1']);
    }
    $f->store->saveVehicle($f->input(['id' => $f->id, 'password' => '']));
    serviceEqual($f->store->all()[$f->id]['vehicle_icon'], 'motorhome');
    serviceInvalid(function () use ($f) { $f->store->saveVehicle($f->input(['vehicle_icon' => '../unknown.svg'])); });
    serviceInvalid(function () use ($f) { $f->store->saveVehicle($f->input(['vehicle_icon' => ['car']])); });
    $path = $f->base . '/config/vehicles.json';
    $legacy = json_decode(file_get_contents($path), true);
    unset($legacy['vehicles'][$f->id]['vehicle_icon']);
    file_put_contents($path, json_encode($legacy));
    $entry = $f->store->all()[$f->id];
    serviceEqual($entry['vehicle_icon'], 'auto');
    serviceEqual(VehicleProfile::displayName($entry, ['name' => 'Pandora-Name']), 'Testfahrzeug');
    serviceEqual(VehicleProfile::icon(['name' => 'Motorrad Wohnmobil', 'vehicle_icon' => 'auto'],
        ['name' => 'Camper', 'model' => 'Camper', 'carType' => 0]), 'car');
    serviceEqual(VehicleProfile::icon(['vehicle_icon' => 'auto'], ['vehicleType' => 'motorcycle']), 'motorcycle');
    serviceEqual(VehicleProfile::icon(['vehicle_icon' => 'van'], ['vehicleType' => 'motorhome']), 'van');
};
$serviceTests['single-device discovery and multiple-device explicit selection'] = function (): void {
    $f = new ServiceFixture('');
    $state = $f->service->refresh($f->id);
    serviceEqual($state['vehicle']['id'], '100');
    serviceEqual($f->store->credentials($f->id)['device_id'], '100');
    serviceEqual($state['events'][0]['title'], 'Alarm gemeldet');
    $multiple = new ServiceFixture('');
    $multiple->multiple = true;
    $state = $multiple->service->refresh($multiple->id);
    serviceEqual($state['vehicle'], null);
    serviceEqual(count($state['discovered']), 2);
    serviceEqual($multiple->store->credentials($multiple->id)['device_id'], '');
};
$serviceTests['expired read session renews exactly once'] = function (): void {
    $f = new ServiceFixture();
    $f->seed();
    $f->requiredGeneration = 2;
    $state = $f->service->refresh($f->id);
    serviceEqual($state['error'], '');
    serviceEqual($f->logins, 2);
    $f->alwaysExpire = true;
    $state = $f->service->refresh($f->id);
    serviceEqual($f->logins, 3);
    serviceEqual($f->store->session($f->id), []);
    serviceEqual($state['vehicle'], null);
};
$serviceTests['membership removal clears previously cached private state'] = function (): void {
    $f = new ServiceFixture();
    $f->service->refresh($f->id);
    $f->removed = true;
    $state = $f->service->refresh($f->id);
    serviceEqual($state['vehicle'], null);
    serviceEqual($state['events'], []);
    serviceEqual($state['discovered'], []);
    serviceEqual($state['error'] !== '', true);
};
$serviceTests['history refresh cannot republish status revoked within request'] = function (): void {
    $f = new ServiceFixture();
    $f->seed();
    $revokeAt = $f->devicesReads + 2;
    $f->onDevices = static function (ServiceFixture $fixture) use ($revokeAt): void {
        if ($fixture->devicesReads === $revokeAt) {
            $fixture->permissions['status'] = $fixture->permissions['tracks'] = 0;
        }
    };
    $state = $f->service->refresh($f->id);
    serviceEqual($state['vehicle']['position'], null);
    serviceEqual($state['vehicle']['status']['batteryVoltage'], null);
    serviceEqual($state['vehicle']['commands'], []);
};
$serviceTests['missing history permission keeps authorized current status'] = function (): void {
    $f = new ServiceFixture();
    $f->permissions['events'] = 0;
    $state = $f->service->refresh($f->id);
    serviceEqual($state['vehicle']['id'], '100');
    serviceEqual($state['vehicle']['status']['batteryVoltage'], 12.5);
    serviceEqual($state['events'], []);
    serviceEqual($state['history_available'], false);
};
$serviceTests['credential reassignment during a read cannot persist old account state'] = function (): void {
    $f = new ServiceFixture();
    $f->seed();
    $changeAt = $f->devicesReads + 1;
    $f->onDevices = static function (ServiceFixture $fixture) use ($changeAt): void {
        if ($fixture->devicesReads === $changeAt) {
            $fixture->store->saveVehicle($fixture->input(['id' => $fixture->id, 'login' => 'replacement', 'password' => 'replacement-password']));
        }
    };
    $f->service->refresh($f->id);
    serviceEqual($f->store->credentials($f->id)['login'], 'replacement');
    serviceEqual($f->store->state($f->id)['vehicle'], null);
    serviceEqual($f->store->state($f->id)['events'], []);
    serviceEqual($f->store->session($f->id), []);
};
$serviceTests['failed read preserves last success time and marks MQTT unavailable'] = function (): void {
    $f = new ServiceFixture();
    $before = $f->service->refresh($f->id);
    $f->networkFails = true;
    $after = $f->service->refresh($f->id);
    serviceEqual($after['updated_at'], $before['updated_at']);
    serviceEqual($after['error'] !== '', true);
    $topics = Topics::snapshot('pandora', $f->store->all()[$f->id], $after);
    serviceEqual($topics['pandora/' . $f->id . '/state/available'], '0');
    serviceEqual($topics['pandora/' . $f->id . '/state/battery_voltage_valid'], '0');
};
$serviceTests['queue is claimed once and expired or disabled commands never send'] = function (): void {
    $f = new ServiceFixture();
    $f->service->enqueue($f->id, 'lock');
    serviceEqual(count($f->store->takeQueue()), 1);
    serviceEqual($f->store->takeQueue(), []);
    serviceEqual($f->service->execute($f->request('lock', 31))['status'], 'rejected');
    serviceEqual($f->service->execute($f->request('lock', -2))['status'], 'rejected');
    serviceEqual($f->sends, 0);
    $disabled = new ServiceFixture('100', false);
    serviceInvalid(function () use ($disabled) { $disabled->service->enqueue($disabled->id, 'lock'); });
    serviceEqual($disabled->service->execute($disabled->request())['status'], 'rejected');
    serviceEqual($disabled->sends, 0);
};
$serviceTests['command session renews before send and never repeats an ambiguous send'] = function (): void {
    $f = new ServiceFixture();
    $f->seed();
    $f->requiredGeneration = 2;
    serviceEqual($f->service->execute($f->request())['status'], 'confirmed');
    serviceEqual([$f->sends, $f->logins], [1, 2]);
    $uncertain = new ServiceFixture();
    $uncertain->lostPost = true;
    $result = $uncertain->service->execute($uncertain->request());
    serviceEqual($result['status'], 'unknown');
    serviceEqual([$uncertain->sends, $uncertain->logins], [1, 1]);
    $expired = new ServiceFixture();
    $expired->expirePoll = true;
    serviceEqual($expired->service->execute($expired->request())['status'], 'sent');
    serviceEqual([$expired->sends, $expired->logins], [1, 1]);
};
$serviceTests['queued commands cannot target a reassigned vehicle and duplicate queueing is rejected'] = function (): void {
    $f = new ServiceFixture();
    $f->service->enqueue($f->id, 'lock');
    serviceInvalid(function () use ($f) { $f->service->enqueue($f->id, 'lock'); });
    $request = $f->store->takeQueue()[0];
    $f->store->saveVehicle($f->input(['id' => $f->id, 'device_id' => '200']));
    serviceEqual($f->service->execute($request)['status'], 'rejected');
    serviceEqual($f->sends, 0);
};
$serviceTests['status refresh preserves the last command result'] = function (): void {
    $f = new ServiceFixture();
    $last = ['status' => 'unknown', 'message' => 'Fixture response lost', 'timestamp' => gmdate('c')];
    $f->store->writeState($f->id, ['last_command' => $last]);
    $after = $f->service->refresh($f->id);
    serviceEqual($after['last_command'], $last);
};
$serviceTests['unknown, zero, stale and message MQTT values stay distinguishable'] = function (): void {
    $f = new ServiceFixture();
    $state = $f->service->refresh($f->id);
    $topics = Topics::snapshot('home/pandora', $f->store->all()[$f->id], $state);
    $root = 'home/pandora/' . $f->id;
    serviceEqual($topics[$root . '/state/available'], '1');
    serviceEqual($topics[$root . '/state/fuel_percent'], '0');
    serviceEqual($topics[$root . '/state/fuel_percent_valid'], '1');
    serviceEqual($topics[$root . '/state/armed'], '0');
    serviceEqual($topics[$root . '/state/armed_valid'], '1');
    serviceEqual($topics[$root . '/state/cabin_temperature'], '');
    serviceEqual($topics[$root . '/state/cabin_temperature_valid'], '0');
    serviceEqual($topics[$root . '/events/latest/text'], 'Alarm gemeldet');
    serviceEqual($topics[$root . '/state/stale'], '0');
    $state['vehicle']['updatedAt'] = gmdate('c', time() - 400);
    serviceEqual(Topics::snapshot('home/pandora', $f->store->all()[$f->id], $state)[$root . '/state/stale'], '1');
    serviceEqual(Topics::parseCommand('home/pandora', $root . '/set/lock', '1'), ['vehicle_id' => $f->id, 'command' => 'lock']);
    foreach (['0', 'ON', '', 'true', '{"command":"lock"}'] as $payload) {
        serviceEqual(Topics::parseCommand('home/pandora', $root . '/set/lock', $payload), null);
    }
    serviceEqual(Topics::parseCommand('home/pandora', $root . '/set/start_engine', '1'), null);
    serviceEqual(Topics::parseCommand('home/pandora', $root . '/set/lock/more', '1'), null);
};

$serviceFailures = 0;
foreach ($serviceTests as $name => $test) {
    try { $test(); echo 'OK ' . $name . PHP_EOL; }
    catch (Throwable $error) { ++$serviceFailures; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
}
echo (count($serviceTests) - $serviceFailures) . '/' . count($serviceTests) . ' service/storage/topic fixture groups passed.' . PHP_EOL;
if ($serviceFailures) { exit(1); }
