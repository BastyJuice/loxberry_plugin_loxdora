#!/usr/bin/php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once 'loxberry_system.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Topics.php';
require_once __DIR__ . '/lib/Mqtt.php';

use PandoraLox\Store;
use PandoraLox\Service;
use PandoraLox\Topics;
use PandoraLox\Mqtt;

umask(0077);
$store = new Store($lbpconfigdir, $lbpdatadir);
$maintenance = $lbpdatadir . '/maintenance';
$lock = fopen($lbpdatadir . '/worker.lock', 'c');
if (!$lock) { fwrite(STDERR, "Worker-Sperre nicht verfügbar.\n"); exit(1); }
$mode = $argv[1] ?? '';
if ($mode === '--resume') { @unlink($maintenance); exit; }
if ($mode === '--stop') {
    file_put_contents($maintenance, '1', LOCK_EX);
    $deadline = microtime(true) + 90;
    do {
        if (flock($lock, LOCK_EX | LOCK_NB)) { flock($lock, LOCK_UN); exit; }
        usleep(200000);
    } while (microtime(true) < $deadline);
    fwrite(STDERR, "Der Pandora-Dienst beendet seine laufende Anfrage noch. Bitte erneut versuchen.\n");
    exit(1);
}
if (is_file($maintenance) || !flock($lock, LOCK_EX | LOCK_NB)) { exit; }

$service = new Service($store);
$mqtt = null;
$mqttPrefix = '';
$mqttConnected = false;
$mqttNextAttempt = 0;
$lastPoll = [];
$lastStateHash = [];
$lastHeartbeat = 0;
$lastTopology = '';
$runtime = ['heartbeat' => time(), 'started_at' => time(), 'mqtt_connected' => false, 'error' => ''];
$rejected = [];
$running = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

// Successful retained topics are tracked so deletions and prefix changes clear old data.
$published = $store->bridgeState();
$retained = is_array($published['topics'] ?? null) ? $published['topics'] : [];
$publish = static function (array $topics) use (&$mqtt, &$retained): void {
    foreach ($topics as $topic => $payload) {
        $mqtt->publish($topic, $payload, true);
        $retained[$topic] = true;
    }
};

try {
    while ($running && !is_file($maintenance)) {
        $settings = $store->settings();
        $vehicles = $store->all();
        $prefix = $settings['topic_prefix'];
        try {
            if ((!$settings['enabled'] || $prefix !== $mqttPrefix) && $mqtt !== null) {
                if (!$settings['enabled']) {
                    foreach ($vehicles as $id => $vehicle) {
                        $vehicle['enabled'] = false;
                        $publish(Topics::snapshot($mqttPrefix, $vehicle, $store->state($id)));
                    }
                }
                $mqtt->close(); $mqtt = null; $mqttConnected = false; $mqttNextAttempt = 0;
            }
            if ($settings['enabled'] && $mqtt === null && time() >= $mqttNextAttempt) {
                try {
                  $mqtt = new Mqtt($prefix);
                  $mqtt->connect(static function (string $topic, string $payload) use ($prefix, $service, &$rejected): void {
                    $request = Topics::parseCommand($prefix, $topic, $payload);
                    if ($request === null) { return; }
                    try { $service->enqueue($request['vehicle_id'], $request['command']); }
                    catch (\Throwable $error) {
                        if (count($rejected) < 100) {
                            $rejected[] = ['vehicle_id' => $request['vehicle_id'], 'result' => [
                                'request_id' => '', 'command' => $request['command'], 'status' => 'rejected',
                                'message' => Service::safeError($error), 'timestamp' => gmdate('c')]];
                        }
                    }
                  });
                  $mqttPrefix = $prefix;
                  $mqttConnected = true;
                  $lastStateHash = [];
                  $lastTopology = '';
                  $runtime['error'] = '';
                } catch (\Throwable $error) {
                    if ($mqtt !== null) { $mqtt->close(); }
                    $mqtt = null; $mqttConnected = false; $mqttNextAttempt = time() + 15;
                    $runtime['error'] = 'MQTT-Verbindung fehlgeschlagen. Bitte das MQTT-Widget im LoxBerry prüfen.';
                }
            }
            if ($mqtt !== null) { $mqtt->tick(); }
            if (!$running || is_file($maintenance)) { break; }

            $results = $rejected;
            $rejected = [];
            foreach ($store->takeQueue() as $request) {
                if (!$running || is_file($maintenance)) { break; }
                $runtime['heartbeat'] = time();
                $runtime['activity'] = 'Fahrzeugbefehl';
                $store->writeRuntime($runtime);
                if (($request['created_at'] ?? 0) < $runtime['started_at']) { $request['created_at'] = 0; }
                $results[] = ['vehicle_id' => (string) ($request['vehicle_id'] ?? ''), 'result' => $service->execute($request)];
            }
            foreach ($results as $entry) {
                $result = $entry['result'];
                $id = $entry['vehicle_id'];
                if (Store::validId($id) && isset($store->all()[$id])) {
                    $store->locked($id, static function () use ($store, $id, $result): void {
                        if (!isset($store->all()[$id])) { return; }
                        $state = $store->state($id);
                        $state['last_command'] = $result;
                        $store->writeState($id, $state);
                    });
                    if ($mqtt !== null) {
                        $mqtt->publish($prefix . '/' . $id . '/command/result', Store::json($result), false);
                        $publish([$prefix . '/' . $id . '/state/last_command_status' => $result['status'],
                            $prefix . '/' . $id . '/state/last_command_message' => $result['message']]);
                    }
                    $lastPoll[$id] = 0; // Refresh status after the single command attempt.
                }
            }

            // Read one due vehicle per cycle, then return to MQTT and command processing.
            if (!$running || is_file($maintenance)) { break; }
            if ($settings['enabled']) {
                foreach ($vehicles as $id => $vehicle) {
                    if ($vehicle['enabled'] && time() - ($lastPoll[$id] ?? 0) >= $settings['poll_interval']) {
                        $runtime['heartbeat'] = time();
                        $runtime['mqtt_connected'] = $mqttConnected;
                        $runtime['activity'] = 'Statusabruf ' . $id;
                        $store->writeRuntime($runtime);
                        $service->refresh($id);
                        $lastPoll[$id] = time();
                        break;
                    }
                }
            }

            if ($mqtt !== null) {
                $vehicles = $store->all();
                $expected = [$prefix . '/bridge/availability' => true];
                foreach ($vehicles as $id => $vehicle) {
                    $state = $store->state($id);
                    $topics = Topics::snapshot($prefix, $vehicle, $state);
                    if (isset($state['last_command'])) {
                        $topics[$prefix . '/' . $id . '/state/last_command_status'] = $state['last_command']['status'];
                        $topics[$prefix . '/' . $id . '/state/last_command_message'] = $state['last_command']['message'];
                    }
                    $expected += array_fill_keys(array_keys($topics), true);
                    $hash = hash('sha256', Store::json($topics));
                    if (($lastStateHash[$id] ?? '') !== $hash) { $publish($topics); $lastStateHash[$id] = $hash; }
                }
                foreach (array_diff_key($retained, $expected) as $topic => $_) {
                    $mqtt->publish($topic, '', true);
                    unset($retained[$topic]);
                }
                $retained[$prefix . '/bridge/availability'] = true;
                $topology = hash('sha256', Store::json(array_keys($retained)));
                if ($topology !== $lastTopology) {
                    $store->writeBridgeState(['topics' => $retained]);
                    $lastTopology = $topology;
                }
            }
            if ($mqttConnected || !$settings['enabled']) { $runtime['error'] = ''; }
        } catch (\Throwable $error) {
            // All client and storage exceptions are neutral; never persist upstream bodies or secrets.
            $runtime['error'] = 'Hintergrunddienst: Verbindung oder Verarbeitung fehlgeschlagen. MQTT-Einstellungen und Fahrzeugstatus prüfen.';
            if ($mqtt !== null) { $mqtt->close(); }
            $mqtt = null; $mqttConnected = false; $mqttNextAttempt = time() + 15;
        }
        if (time() - $lastHeartbeat >= 5) {
            $runtime['heartbeat'] = time();
            $runtime['mqtt_connected'] = $mqttConnected;
            $runtime['activity'] = $settings['enabled'] ? 'Bereit' : 'Deaktiviert';
            $store->writeRuntime($runtime);
            $lastHeartbeat = time();
        }
        usleep(200000);
    }
} finally {
    if ($mqtt !== null) { $mqtt->close(); }
    $runtime['heartbeat'] = time();
    $runtime['mqtt_connected'] = false;
    $runtime['activity'] = 'Beendet';
    $store->writeRuntime($runtime);
    flock($lock, LOCK_UN);
    fclose($lock);
}
