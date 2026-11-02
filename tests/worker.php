<?php
declare(strict_types=1);

// Real process lifecycle against an isolated, disabled plugin and stub SDK.
// No broker, account, API network request or vehicle command is involved.
require_once __DIR__ . '/../bin/lib/Store.php';

use PandoraLox\Store;

function workerAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function workerRemove(string $path): void
{
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $child = $path . '/' . $item;
        if (is_dir($child)) { workerRemove($child); } else { unlink($child); }
    }
    rmdir($path);
}

function workerStart(array $command): array
{
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Worker-Prozess konnte nicht gestartet werden.'); }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'exit' => null];
}

function workerFinish(array &$running, float $timeout = 5.0): array
{
    $deadline = microtime(true) + $timeout;
    do {
        $status = proc_get_status($running['process']);
        if (!$status['running']) {
            $running['exit'] = $status['exitcode'];
            $stdout = stream_get_contents($running['stdout']);
            $stderr = stream_get_contents($running['stderr']);
            fclose($running['stdout']);
            fclose($running['stderr']);
            proc_close($running['process']);
            $running['process'] = null;
            return ['exit' => $running['exit'], 'stdout' => $stdout, 'stderr' => $stderr];
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Worker-Prozess hat das Zeitlimit überschritten.');
}

function workerRun(array $command): array
{
    $running = workerStart($command);
    try { return workerFinish($running); }
    finally {
        if (is_resource($running['process'])) {
            proc_terminate($running['process']);
            fclose($running['stdout']);
            fclose($running['stderr']);
            proc_close($running['process']);
        }
    }
}

function workerWaitFor(callable $condition, string $message): void
{
    $deadline = microtime(true) + 5;
    do {
        if ($condition()) { return; }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException($message);
}

$workerBase = sys_get_temp_dir() . '/pandora-worker-test-' . bin2hex(random_bytes(8));
$workerStore = new Store($workerBase . '/config', $workerBase . '/data');
$workerStore->saveSettings(['enabled' => false, 'poll_interval' => 60, 'topic_prefix' => 'pandora-fixture']);
mkdir($workerBase . '/sdk', 0700);
$workerStub = '<?php $lbpconfigdir = ' . var_export($workerBase . '/config', true)
    . '; $lbpdatadir = ' . var_export($workerBase . '/data', true)
    . '; function mqtt_connectiondetails() { throw new RuntimeException("Disabled test must not connect MQTT."); }';
file_put_contents($workerBase . '/sdk/loxberry_system.php', $workerStub);
$workerCommand = [PHP_BINARY, '-d', 'include_path=' . $workerBase . '/sdk', __DIR__ . '/../bin/worker.php'];
$workerProcesses = [];
$workerPassed = 0;

try {
    // Start establishes the lock and periodically reports an intentionally disabled bridge.
    $workerProcesses[] = workerStart($workerCommand);
    $first = count($workerProcesses) - 1;
    workerWaitFor(static function () use ($workerStore): bool {
        return ($workerStore->runtime()['activity'] ?? '') === 'Deaktiviert';
    }, 'Worker meldet keinen deaktivierten Betriebszustand.');
    workerAssert(proc_get_status($workerProcesses[$first]['process'])['running'], 'Worker ist unerwartet beendet.');
    workerAssert($workerStore->runtime()['mqtt_connected'] === false, 'Deaktivierter Worker hat eine MQTT-Verbindung.');
    workerAssert($workerStore->all() === [], 'Lifecycle-Fixture enthält Fahrzeuge.');
    ++$workerPassed;
    echo 'OK isolated disabled worker starts without MQTT or vehicle requests' . PHP_EOL;

    $duplicate = workerRun($workerCommand);
    workerAssert($duplicate['exit'] === 0, 'Zweiter Worker darf keinen Fehler auslösen.');
    workerAssert(proc_get_status($workerProcesses[$first]['process'])['running'], 'Zweiter Start hat den laufenden Worker beendet.');
    workerAssert($duplicate['stderr'] === '', 'Zweiter Start meldet unerwartete Fehler.');
    ++$workerPassed;
    echo 'OK duplicate worker exits while the original holds the single lock' . PHP_EOL;

    $stop = workerRun(array_merge($workerCommand, ['--stop']));
    workerAssert($stop['exit'] === 0, 'Stop-Befehl fehlgeschlagen: ' . $stop['stderr']);
    $finished = workerFinish($workerProcesses[$first]);
    workerAssert($finished['exit'] === 0, 'Worker beendet sich beim Stop nicht sauber: ' . $finished['stderr']);
    workerAssert($workerStore->runtime()['activity'] === 'Beendet', 'Stop hinterlässt keinen abgeschlossenen Betriebszustand.');
    workerAssert(is_file($workerBase . '/data/maintenance'), 'Stop setzt die Wartungssperre nicht.');
    $lockHandle = fopen($workerBase . '/data/worker.lock', 'c');
    workerAssert(flock($lockHandle, LOCK_EX | LOCK_NB), 'Stop gibt die Worker-Sperre nicht frei.');
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    ++$workerPassed;
    echo 'OK stop waits for clean termination and releases the process lock' . PHP_EOL;

    $maintained = workerRun($workerCommand);
    workerAssert($maintained['exit'] === 0, 'Start in Wartung muss ohne Fehler enden.');
    workerAssert($workerStore->runtime()['activity'] === 'Beendet', 'Wartung verhindert den erneuten Start nicht.');
    $resumed = workerRun(array_merge($workerCommand, ['--resume']));
    workerAssert($resumed['exit'] === 0 && !is_file($workerBase . '/data/maintenance'), 'Resume entfernt die Wartungssperre nicht.');
    $workerProcesses[] = workerStart($workerCommand);
    $second = count($workerProcesses) - 1;
    workerWaitFor(static function () use ($workerStore): bool {
        return ($workerStore->runtime()['activity'] ?? '') === 'Deaktiviert';
    }, 'Worker startet nach Resume nicht wieder.');
    ++$workerPassed;
    echo 'OK maintenance blocks restart and resume allows a fresh worker' . PHP_EOL;

    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        proc_terminate($workerProcesses[$second]['process'], 15);
        $terminated = workerFinish($workerProcesses[$second]);
        workerAssert($terminated['exit'] === 0, 'SIGTERM endet nicht sauber: ' . $terminated['stderr']);
        workerAssert($workerStore->runtime()['activity'] === 'Beendet', 'SIGTERM speichert keinen finalen Betriebszustand.');
        ++$workerPassed;
        echo 'OK SIGTERM performs clean worker shutdown' . PHP_EOL;
    } else {
        workerRun(array_merge($workerCommand, ['--stop']));
        workerFinish($workerProcesses[$second]);
        echo 'SKIP signal-specific lifecycle test: pcntl unavailable' . PHP_EOL;
    }
    echo $workerPassed . ' worker lifecycle smoke groups passed.' . PHP_EOL;
} finally {
    // Only isolated child processes and temporary fixture files are cleaned up.
    file_put_contents($workerBase . '/data/maintenance', '1');
    foreach ($workerProcesses as &$child) {
        if (is_resource($child['process'])) {
            proc_terminate($child['process']);
            fclose($child['stdout']);
            fclose($child['stderr']);
            proc_close($child['process']);
        }
    }
    unset($child);
    workerRemove($workerBase);
}
