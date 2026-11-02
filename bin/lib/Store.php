<?php
declare(strict_types=1);

namespace PandoraLox;
require_once __DIR__ . '/VehicleProfile.php';

/** Private, atomic configuration storage. Passwords and cookie sessions use AES-256-GCM. */
final class Store
{
    private $configDir;
    private $dataDir;
    private $heldLocks = [];

    public function __construct(string $configDir, string $dataDir)
    {
        $this->configDir = rtrim($configDir, '/');
        $this->dataDir = rtrim($dataDir, '/');
        foreach ([$this->configDir, $this->dataDir, $this->configDir . '/sessions', $this->dataDir . '/queue'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Plugin-Verzeichnis konnte nicht angelegt werden.');
            }
            chmod($dir, 0700);
        }
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^fahrzeug_[1-9][0-9]{0,5}$/D', $id);
    }

    private function config(): array
    {
        return $this->read($this->configDir . '/vehicles.json', ['next_id' => 1, 'vehicles' => [], 'settings' => []]);
    }

    public function all(): array
    {
        $result = [];
        foreach ($this->config()['vehicles'] as $id => $vehicle) {
            $vehicle['vehicle_icon'] = $vehicle['vehicle_icon'] ?? 'auto';
            $vehicle['password_set'] = !empty($vehicle['password']);
            unset($vehicle['password']);
            $result[$id] = $vehicle;
        }
        return $result;
    }

    public function credentials(string $id): array
    {
        $this->requireId($id);
        $vehicle = $this->config()['vehicles'][$id] ?? null;
        if (!is_array($vehicle)) { throw new \InvalidArgumentException('Fahrzeug nicht gefunden.'); }
        $vehicle['password'] = $this->unseal($vehicle['password']);
        return $vehicle;
    }

    public function saveVehicle(array $input): string
    {
        $save = function () use ($input): string { return $this->locked('configuration', function () use ($input): string {
            $config = $this->config();
            $id = trim((string) ($input['id'] ?? ''));
            $old = null;
            if ($id !== '') {
                $this->requireId($id);
                $old = $config['vehicles'][$id] ?? null;
                if (!$old) { throw new \InvalidArgumentException('Fahrzeug nicht gefunden.'); }
            } else {
                $id = 'fahrzeug_' . (int) $config['next_id'];
                $this->requireId($id);
                ++$config['next_id'];
            }
            $name = trim((string) ($input['name'] ?? ''));
            $login = trim((string) ($input['login'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            $deviceId = trim((string) ($input['device_id'] ?? ''));
            if (strlen($name) > 120 || $login === '' || strlen($login) > 160 || strlen($password) > 512) {
                throw new \InvalidArgumentException('Bitte gültige Zugangsdaten und höchstens 120 Zeichen für den eigenen Anzeigenamen eingeben.');
            }
            $vehicleIcon = $input['vehicle_icon'] ?? ($old['vehicle_icon'] ?? 'auto');
            if (!is_string($vehicleIcon) || !isset(VehicleProfile::choices()[$vehicleIcon])) {
                throw new \InvalidArgumentException('Bitte ein verfügbares Fahrzeugsymbol auswählen.');
            }
            if ($deviceId !== '' && !preg_match('/^[0-9]{1,20}$/D', $deviceId)) {
                throw new \InvalidArgumentException('Die Pandora-Fahrzeug-ID muss eine Zahl sein.');
            }
            if ($password === '' && !$old) { throw new \InvalidArgumentException('Bitte das Pandora-Passwort eingeben.'); }
            if ($password === '' && $old && $login !== $old['login']) {
                throw new \InvalidArgumentException('Bei einem neuen Benutzernamen bitte auch das Passwort eingeben.');
            }
            $vehicle = ['id' => $id, 'name' => $name, 'vehicle_icon' => $vehicleIcon,
                'login' => $login, 'revision' => bin2hex(random_bytes(16)),
                'password' => $password !== '' ? $this->seal($password) : $old['password'],
                'device_id' => $deviceId, 'enabled' => !empty($input['enabled']), 'allow_control' => !empty($input['allow_control'])];
            $config['vehicles'][$id] = $vehicle;
            $this->write($this->configDir . '/vehicles.json', $config);
            if (!$old || $password !== '' || $login !== $old['login'] || $deviceId !== $old['device_id']) {
                $this->clearSession($id);
                // Never display the previous account's state after reassignment.
                $this->writeState($id, ['updated_at' => null, 'vehicle' => null, 'events' => [], 'discovered' => []]);
            }
            return $id;
        }); };
        $id = trim((string) ($input['id'] ?? ''));
        if ($id !== '') { $this->requireId($id); return $this->locked($id, $save); }
        return $save();
    }

    public function selectDevice(string $id, string $deviceId): void
    {
        $this->locked('configuration', function () use ($id, $deviceId): void {
            $this->requireId($id);
            $config = $this->config();
            if (!isset($config['vehicles'][$id])) { throw new \InvalidArgumentException('Fahrzeug nicht gefunden.'); }
            if ($config['vehicles'][$id]['device_id'] === '') {
                $config['vehicles'][$id]['device_id'] = $deviceId;
                $config['vehicles'][$id]['revision'] = bin2hex(random_bytes(16));
                $this->write($this->configDir . '/vehicles.json', $config);
            }
        });
    }

    public function deleteVehicle(string $id): void
    {
        $this->requireId($id);
        $this->locked($id, function () use ($id): void { $this->locked('configuration', function () use ($id): void {
            $this->requireId($id);
            $config = $this->config();
            unset($config['vehicles'][$id]);
            $this->write($this->configDir . '/vehicles.json', $config);
            $this->clearSession($id);
            @unlink($this->dataDir . '/state-' . $id . '.json');
        }); });
    }

    public function settings(): array
    {
        return array_replace(['poll_interval' => 60, 'topic_prefix' => 'pandora', 'enabled' => true], $this->config()['settings']);
    }

    public function saveSettings(array $settings): void
    {
        $interval = filter_var($settings['poll_interval'] ?? null, FILTER_VALIDATE_INT);
        $prefix = trim((string) ($settings['topic_prefix'] ?? 'pandora'), '/ ');
        if ($interval === false || $interval < 30 || $interval > 3600) {
            throw new \InvalidArgumentException('Das Abrufintervall muss zwischen 30 und 3600 Sekunden liegen.');
        }
        if (!preg_match('~^[a-zA-Z0-9_-]{1,40}(?:/[a-zA-Z0-9_-]{1,40}){0,3}$~D', $prefix)) {
            throw new \InvalidArgumentException('Ungültiger MQTT-Präfix. Erlaubt sind Buchstaben, Zahlen, _ und -.');
        }
        $this->locked('configuration', function () use ($settings, $interval, $prefix): void {
            $config = $this->config();
            $config['settings'] = ['poll_interval' => $interval, 'topic_prefix' => $prefix, 'enabled' => !empty($settings['enabled'])];
            $this->write($this->configDir . '/vehicles.json', $config);
        });
    }

    public function state(string $id): array
    {
        $this->requireId($id);
        return $this->read($this->dataDir . '/state-' . $id . '.json', []);
    }

    public function writeState(string $id, array $state): void
    {
        $this->requireId($id);
        $this->write($this->dataDir . '/state-' . $id . '.json', $state);
    }

    public function session(string $id): array
    {
        $this->requireId($id);
        $sealed = $this->read($this->configDir . '/sessions/' . $id . '.json', []);
        if (!$sealed) { return []; }
        $value = json_decode($this->unseal($sealed), true);
        return is_array($value) ? $value : [];
    }

    public function writeSession(string $id, array $session): void
    {
        $this->requireId($id);
        $this->write($this->configDir . '/sessions/' . $id . '.json', $this->seal(self::json($session)));
    }

    public function clearSession(string $id): void
    {
        $this->requireId($id);
        @unlink($this->configDir . '/sessions/' . $id . '.json');
    }

    public function runtime(): array { return $this->read($this->dataDir . '/runtime.json', []); }
    public function writeRuntime(array $runtime): void { $this->write($this->dataDir . '/runtime.json', $runtime); }
    public function bridgeState(): array { return $this->read($this->dataDir . '/mqtt-published.json', []); }
    public function writeBridgeState(array $state): void { $this->write($this->dataDir . '/mqtt-published.json', $state); }

    public function enqueue(string $id, string $command): string
    {
        $this->requireId($id);
        return $this->locked('queue', function () use ($id, $command): string {
            $pending = glob($this->dataDir . '/queue/*.json') ?: [];
            if (count($pending) >= 100) { throw new \InvalidArgumentException('Die Befehlswarteschlange ist voll. Bitte später erneut versuchen.'); }
            foreach ($pending as $file) {
                $request = $this->read($file, []);
                if (($request['vehicle_id'] ?? '') === $id && time() - ($request['created_at'] ?? 0) <= 30) {
                    throw new \InvalidArgumentException('Für dieses Fahrzeug ist bereits ein Befehl vorgemerkt.');
                }
            }
            $last = $this->state($id)['last_command']['timestamp'] ?? '';
            if ($last !== '' && time() - (strtotime($last) ?: 0) < 3) {
                throw new \InvalidArgumentException('Bitte die Rückmeldung des letzten Befehls abwarten.');
            }
            $requestId = bin2hex(random_bytes(12));
            $vehicle = $this->config()['vehicles'][$id] ?? null;
            if (!$vehicle) { throw new \InvalidArgumentException('Fahrzeug nicht gefunden.'); }
            $this->write($this->dataDir . '/queue/' . $requestId . '.json', [
                'id' => $requestId, 'vehicle_id' => $id, 'revision' => $vehicle['revision'] ?? '',
                'command' => $command, 'created_at' => time()]);
            return $requestId;
        });
    }

    /** Atomically claim requests before execution; a crash must never replay a command. */
    public function takeQueue(): array
    {
        $requests = [];
        foreach (array_slice(glob($this->dataDir . '/queue/*.json') ?: [], 0, 100) as $file) {
            $claimed = $file . '.claimed';
            if (!rename($file, $claimed)) { continue; }
            try { $requests[] = $this->read($claimed, []); } finally { @unlink($claimed); }
        }
        return $requests;
    }

    public function locked(string $name, callable $action)
    {
        if (!preg_match('/^[a-z0-9_-]+$/D', $name)) { throw new \InvalidArgumentException('Ungültige Sperre.'); }
        if (isset($this->heldLocks[$name])) { return $action(); }
        $handle = fopen($this->dataDir . '/' . $name . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX)) { throw new \RuntimeException('Plugin-Sperre nicht verfügbar.'); }
        chmod($this->dataDir . '/' . $name . '.lock', 0600);
        $this->heldLocks[$name] = true;
        try { return $action(); } finally { unset($this->heldLocks[$name]); flock($handle, LOCK_UN); fclose($handle); }
    }

    public static function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) { throw new \RuntimeException('Daten konnten nicht gespeichert werden.'); }
        return $json;
    }

    private function requireId(string $id): void
    {
        if (!self::validId($id)) { throw new \InvalidArgumentException('Ungültige Fahrzeug-ID.'); }
    }

    private function read(string $path, array $default): array
    {
        if (!is_file($path)) { return $default; }
        $value = json_decode((string) file_get_contents($path), true);
        if (!is_array($value)) { throw new \RuntimeException('Gespeicherte Plugin-Datei ist beschädigt.'); }
        return $value;
    }

    private function write(string $path, array $value): void
    {
        $temp = tempnam(dirname($path), '.pandora-');
        if (!$temp) { throw new \RuntimeException('Plugin-Datei konnte nicht angelegt werden.'); }
        try {
            chmod($temp, 0600);
            if (file_put_contents($temp, self::json($value), LOCK_EX) === false || !rename($temp, $path)) {
                throw new \RuntimeException('Plugin-Datei konnte nicht gespeichert werden.');
            }
        } finally { if (is_file($temp)) { unlink($temp); } }
    }

    private function key(): string
    {
        $path = $this->configDir . '/secret.key';
        if (!is_file($path)) {
            $handle = @fopen($path, 'x');
            if ($handle) { chmod($path, 0600); fwrite($handle, random_bytes(32)); fclose($handle); }
        }
        $key = (string) file_get_contents($path);
        if (strlen($key) !== 32) { throw new \RuntimeException('Der Plugin-Schlüssel fehlt oder ist beschädigt.'); }
        return $key;
    }

    private function seal(string $plain): array
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new \RuntimeException('Verschlüsselung fehlgeschlagen.'); }
        return ['v' => 1, 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($cipher)];
    }

    private function unseal(array $value): string
    {
        $iv = base64_decode((string) ($value['iv'] ?? ''), true);
        $tag = base64_decode((string) ($value['tag'] ?? ''), true);
        $data = base64_decode((string) ($value['data'] ?? ''), true);
        if ($iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $data === false) {
            throw new \RuntimeException('Gespeicherte Zugangsdaten sind beschädigt.');
        }
        $plain = openssl_decrypt($data, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) { throw new \RuntimeException('Zugangsdaten konnten nicht entschlüsselt werden.'); }
        return $plain;
    }
}
