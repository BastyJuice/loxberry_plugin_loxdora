<?php
declare(strict_types=1);

// Integration test against LoxBerry's real, unmodified native MQTT library.
// Usage: php tests/mqtt.php /path/to/Loxberry/libs/phplib/phpMQTT/phpMQTT.php
// The library is not shipped with the plugin. No broker package is required.
$native = $argv[1] ?? stream_resolve_include_path('phpMQTT/phpMQTT.php');
if (!$native || !is_file($native) || !function_exists('pcntl_fork')) {
    echo "SKIP MQTT: native LoxBerry phpMQTT path and pcntl are required.\n";
    exit(0);
}
require_once $native;
require_once dirname(__DIR__) . '/bin/lib/Mqtt.php';

function mqttCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mqttReadBytes($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Fake broker: truncated packet.');
        }
        $data .= $chunk;
    }
    return $data;
}

function mqttReadPacket($socket): array
{
    $header = ord(mqttReadBytes($socket, 1));
    $length = 0;
    $multiplier = 1;
    do {
        $digit = ord(mqttReadBytes($socket, 1));
        $length += ($digit & 127) * $multiplier;
        $multiplier *= 128;
        mqttCheck($multiplier <= 268435456, 'Malformed remaining length.');
    } while ($digit & 128);
    return array($header, mqttReadBytes($socket, $length));
}

function mqttFrame(int $header, string $body): string
{
    $length = strlen($body);
    $packet = chr($header);
    do {
        $digit = $length & 127;
        $length >>= 7;
        $packet .= chr($digit | ($length ? 128 : 0));
    } while ($length);
    return $packet . $body;
}

function mqttPublishFrame(int $header, string $topic, string $payload, string $packetId = ''): string
{
    return mqttFrame($header, pack('n', strlen($topic)) . $topic . $packetId . $payload);
}

function mqttPublication(array $packet): array
{
    $length = unpack('nlength', substr($packet[1], 0, 2))['length'];
    return array($packet[0], substr($packet[1], 2, $length), substr($packet[1], $length + 2));
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
mqttCheck(is_resource($server), 'Could not open local fake broker.');
$mqttTestPort = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
function mqtt_connectiondetails(): array
{
    global $mqttTestPort;
    return array('brokerhost' => '127.0.0.1', 'brokerport' => $mqttTestPort, 'brokeruser' => 'fixture-user', 'brokerpass' => 'fixture-password');
}

$child = pcntl_fork();
mqttCheck($child >= 0, 'Could not fork fake broker.');
if ($child === 0) {
    try {
        // Two sessions ensure retained commands stay blocked after reconnect.
        for ($session = 0; $session < 2; $session++) {
            $socket = stream_socket_accept($server, 5);
            mqttCheck(is_resource($socket), 'Fake broker: connection missing.');
            stream_set_timeout($socket, 5);
            $connect = mqttReadPacket($socket);
            mqttCheck($connect[0] === 0x10 && (ord($connect[1][7]) & 2) === 2, 'MQTT must use clean sessions.');
            mqttCheck(unpack('nseconds', substr($connect[1], 8, 2))['seconds'] === 120, 'Keepalive must permit bounded API calls.');
            fwrite($socket, "\x20\x02\x00\x00");
            $subscribe = mqttReadPacket($socket);
            mqttCheck($subscribe[0] === 0x82 && strpos($subscribe[1], 'pandora/+/set/+') !== false, 'Unexpected subscription.');
            mqttCheck(substr($subscribe[1], -1) === "\0", 'Commands must subscribe at QoS 0.');
            fwrite($socket, "\x90\x03" . substr($subscribe[1], 0, 2) . "\0");
            mqttCheck(mqttPublication(mqttReadPacket($socket)) === array(0x31, 'pandora/bridge/availability', 'online'), 'Online status missing.');

            $topic = 'pandora/v1/set/arm';
            $frames = mqttPublishFrame(0x31, $topic, 'retained')
                . mqttPublishFrame(0x38, $topic, 'duplicate')
                . mqttPublishFrame(0x32, $topic, 'qos1', "\0\x05")
                . mqttFrame(0x30, "\0\xFFx")
                . mqttFrame(0x30, '')
                . mqttPublishFrame(0x30, 'other/v1/set/arm', 'unrelated')
                . mqttPublishFrame(0x30, $topic, '1')
                . mqttPublishFrame(0x30, 'pandora/v1/set/refresh', str_repeat('x', 150));
            fwrite($socket, $frames);
            mqttCheck(mqttPublication(mqttReadPacket($socket)) === array(0x30, 'pandora/v1/state/test', '0'), 'Non-retained scalar publication failed.');
            mqttCheck(mqttPublication(mqttReadPacket($socket)) === array(0x31, 'pandora/bridge/availability', 'offline'), 'Offline status missing.');
            mqttCheck(mqttReadPacket($socket)[0] === 0xE0, 'Graceful disconnect missing.');
            fclose($socket);
        }
        fclose($server);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}

try {
    $mqtt = new PandoraLox\Mqtt('pandora');
    mqttCheck(array_keys(PandoraLox\Mqtt::connectionDetails()) === array('host', 'port', 'tls'), 'Diagnostics expose unexpected fields.');
    for ($session = 0; $session < 2; $session++) {
        $received = array();
        $mqtt->connect(function ($topic, $payload) use (&$received) {
            $received[] = array($topic, $payload);
        });
        $deadline = microtime(true) + 5;
        while (count($received) < 2 && microtime(true) < $deadline) {
            $mqtt->tick();
            usleep(1000);
        }
        mqttCheck($received === array(array('pandora/v1/set/arm', '1'), array('pandora/v1/set/refresh', str_repeat('x', 150))), 'Retained, duplicate, malformed, or unrelated command was delivered.');
        $mqtt->publish('pandora/v1/state/test', false, false);
        $mqtt->close();
        mqttCheck(!$mqtt->details()['connected'], 'Close must reset connection status.');
    }
    pcntl_waitpid($child, $status);
    mqttCheck(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Fake broker failed.');
    fclose($server);
    echo "PASS MQTT: native parser, retained/DUP rejection, malformed frames, multi-byte lengths, clean reconnect, scalar publish, availability and diagnostics.\n";
} catch (Throwable $error) {
    if (isset($mqtt)) {
        $mqtt->close();
    }
    posix_kill($child, SIGTERM);
    pcntl_waitpid($child, $status);
    fclose($server);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
