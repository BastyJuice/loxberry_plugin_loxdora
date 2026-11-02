<?php
declare(strict_types=1);

namespace PandoraLox;

/** Uses the MQTT settings and PHP client supplied by LoxBerry. */
final class Mqtt
{
    private $prefix;
    private $client;
    private $connected = false;

    public function __construct(string $prefix = 'pandora')
    {
        if (!preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $prefix)) {
            throw new \InvalidArgumentException('Ungültiger MQTT-Präfix.');
        }
        $this->prefix = $prefix;
    }

    /** Safe diagnostics: deliberately never returns a username or password. */
    public static function connectionDetails(): array
    {
        $settings = self::settings();
        $tls = !empty($settings['tls']);
        return array(
            'host' => (string) ($settings['brokerhost'] ?? ''),
            'port' => (int) ($tls ? ($settings['tls_brokerport'] ?? $settings['brokerport'] ?? 8883) : ($settings['brokerport'] ?? 1883)),
            'tls' => $tls,
        );
    }

    public function details(): array
    {
        return self::connectionDetails() + array('connected' => $this->connected);
    }

    private static function settings(): array
    {
        if (!function_exists('mqtt_connectiondetails')) {
            if (!stream_resolve_include_path('loxberry_io.php')) {
                throw new \RuntimeException('Die LoxBerry-MQTT-Funktionen sind nicht verfügbar.');
            }
            require_once 'loxberry_io.php';
        }
        // The native SDK caches general.json for a PHP process. A persistent
        // worker must refresh it when reconnecting after broker/widget changes.
        if (class_exists('LBSystem', false) && method_exists('LBSystem', 'read_generaljson')) {
            unset($GLOBALS['cfgwasread']);
            \LBSystem::read_generaljson();
        }
        $settings = \mqtt_connectiondetails();
        if (!is_array($settings) || empty($settings['brokerhost'])) {
            throw new \RuntimeException('Bitte MQTT im LoxBerry-MQTT-Widget einrichten.');
        }
        return $settings;
    }

    /** The callback receives only live, non-duplicate QoS 0 command messages. */
    public function connect(callable $callback): void
    {
        $this->close();
        $settings = self::settings();
        if (!class_exists('Bluerhinos\\phpMQTT', false)) {
            if (!stream_resolve_include_path('phpMQTT/phpMQTT.php')) {
                throw new \RuntimeException('Die mit LoxBerry gelieferte PHP-MQTT-Bibliothek fehlt.');
            }
            require_once 'phpMQTT/phpMQTT.php';
        }

        $tls = !empty($settings['tls']);
        $host = (string) $settings['brokerhost'];
        $port = (int) ($tls ? ($settings['tls_brokerport'] ?? $settings['brokerport'] ?? 8883) : ($settings['brokerport'] ?? 1883));
        if ($port < 1 || $port > 65535 || strpos($host, "\0") !== false) {
            throw new \RuntimeException('Die MQTT-Verbindungseinstellungen im LoxBerry sind ungültig.');
        }

        // The native callback omits MQTT's fixed header. Capture it at the
        // documented read boundary so retained commands cannot run after boot.
        // No core files are modified and no additional client is bundled.
        $this->client = new class($host, $port, 'pandora-' . bin2hex(random_bytes(6))) extends \Bluerhinos\phpMQTT {
            private $incomingHeader;

            public function read($int = 8192, $nb = false)
            {
                if (!is_resource($this->socket) || $int < 0 || $int > 65536) {
                    throw new \RuntimeException('MQTT-Verbindung oder Paket ungültig.');
                }
                if ($nb) {
                    $value = @fread($this->socket, $int);
                    if ($value === false || ($value === '' && feof($this->socket))) {
                        throw new \RuntimeException('MQTT-Verbindung geschlossen.');
                    }
                    if ($int === 1) {
                        $this->incomingHeader = $value === '' ? null : ord($value);
                    }
                    return $value;
                }
                $value = '';
                $deadline = microtime(true) + 5;
                while (strlen($value) < $int) {
                    if (feof($this->socket) || microtime(true) >= $deadline) {
                        throw new \RuntimeException('MQTT-Paket unvollständig.');
                    }
                    $read = array($this->socket);
                    $write = $except = null;
                    if (@stream_select($read, $write, $except, 0, 100000) === false) {
                        throw new \RuntimeException('MQTT-Verbindung unterbrochen.');
                    }
                    if (!$read) {
                        continue;
                    }
                    $chunk = @fread($this->socket, $int - strlen($value));
                    if ($chunk === false) {
                        throw new \RuntimeException('MQTT-Paket konnte nicht gelesen werden.');
                    }
                    $value .= $chunk;
                }
                return $value;
            }

            public function message($message)
            {
                // With a QoS 0 subscription a compliant broker delivers QoS 0.
                // The whole packet has already been read before rejecting it.
                if ($this->incomingHeader === null || ($this->incomingHeader & 15) !== 0 || strlen($message) < 2) {
                    return true;
                }
                $topicLength = unpack('nlength', substr($message, 0, 2))['length'];
                if ($topicLength < 1 || strlen($message) < $topicLength + 2) {
                    return true;
                }
                parent::message($message);
                return true;
            }

            public function connect_auto($clean = true, $will = null, $username = null, $password = null): bool
            {
                // The worker owns retries. Always establish a fresh clean
                // session rather than replay commands from an old session.
                throw new \RuntimeException('MQTT muss neu verbunden werden.');
            }

            protected function _fwrite($buffer)
            {
                $length = strlen($buffer);
                $offset = 0;
                $deadline = microtime(true) + 5;
                while ($offset < $length) {
                    if (!is_resource($this->socket) || feof($this->socket) || microtime(true) >= $deadline) {
                        throw new \RuntimeException('MQTT-Paket konnte nicht gesendet werden.');
                    }
                    $read = $except = null;
                    $write = array($this->socket);
                    if (@stream_select($read, $write, $except, 0, 100000) === false) {
                        throw new \RuntimeException('MQTT-Verbindung unterbrochen.');
                    }
                    if (!$write) {
                        continue;
                    }
                    $written = @fwrite($this->socket, substr($buffer, $offset));
                    if ($written === false) {
                        throw new \RuntimeException('MQTT-Paket konnte nicht gesendet werden.');
                    }
                    $offset += $written;
                }
                return $offset;
            }

            public function abort(): void
            {
                if (is_resource($this->socket)) {
                    fclose($this->socket);
                }
            }
        };

        try {
            if ($tls) {
                if (!method_exists($this->client, 'set_ssl_options')) {
                    throw new \RuntimeException('Die LoxBerry-MQTT-Bibliothek unterstützt diese TLS-Einstellung nicht.');
                }
                $verify = !empty($settings['tls_verify']);
                $options = array('verify_peer' => $verify, 'verify_peer_name' => $verify);
                if ($verify && !empty($settings['tls_cafile']) && is_file($settings['tls_cafile'])) {
                    $options['cafile'] = $settings['tls_cafile'];
                }
                $this->client->set_ssl_options($options);
            }
            $this->client->keepalive = 120;
            $will = array('topic' => $this->prefix . '/bridge/availability', 'content' => 'offline', 'qos' => 0, 'retain' => true);
            if (!@$this->client->connect(true, $will, $settings['brokeruser'] ?? null, $settings['brokerpass'] ?? null)) {
                throw new \RuntimeException('MQTT-Anmeldung fehlgeschlagen.');
            }
            $this->connected = true;
            $this->client->subscribe(array($this->prefix . '/+/set/+' => array('qos' => 0, 'function' => $callback)), 0);
            $this->publish($this->prefix . '/bridge/availability', 'online');
        } catch (\Throwable $error) {
            $this->abort();
            throw new \RuntimeException('MQTT-Verbindung fehlgeschlagen. Bitte Broker und Zugangsdaten im LoxBerry-MQTT-Widget prüfen.', 0, $error);
        }
    }

    public function publish(string $topic, $payload, bool $retain = true): void
    {
        if (!$this->connected || !$this->client) {
            throw new \RuntimeException('MQTT ist nicht verbunden.');
        }
        if ($topic === '' || strlen($topic) > 65535 || strpbrk($topic, "+#\0") !== false) {
            throw new \InvalidArgumentException('Ungültiges MQTT-Topic.');
        }
        if (is_bool($payload)) {
            $payload = $payload ? '1' : '0';
        } elseif (is_array($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } elseif (!is_scalar($payload) && $payload !== null) {
            throw new \InvalidArgumentException('Ungültige MQTT-Nutzdaten.');
        }
        try {
            $this->client->publish($topic, (string) $payload, 0, $retain);
        } catch (\Throwable $error) {
            $this->abort();
            throw new \RuntimeException('MQTT-Nachricht konnte nicht gesendet werden.', 0, $error);
        }
    }

    public function tick(): void
    {
        if (!$this->connected || !$this->client) {
            throw new \RuntimeException('MQTT ist nicht verbunden.');
        }
        try {
            $this->client->proc(false);
        } catch (\Throwable $error) {
            $this->abort();
            throw new \RuntimeException('MQTT-Verbindung unterbrochen.', 0, $error);
        }
    }

    private function abort(): void
    {
        if ($this->client) {
            $this->client->abort();
        }
        $this->connected = false;
        $this->client = null;
    }

    public function close(): void
    {
        if ($this->connected && $this->client) {
            try {
                $this->publish($this->prefix . '/bridge/availability', 'offline');
                if ($this->client) {
                    @$this->client->close();
                }
            } catch (\Throwable $ignored) {
                // Dropping the socket lets the broker publish the last will.
            }
        }
        $this->abort();
    }
}
