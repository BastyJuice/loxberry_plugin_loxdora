<?php
declare(strict_types=1);
namespace PandoraLox;

final class Service
{
    private $store;
    private $client;

    public function __construct(Store $store, ?PandoraClient $client = null)
    {
        $this->store = $store;
        $this->client = $client ?? new PandoraClient();
    }

    private function authenticated(array $vehicle, callable $action)
    {
        $session = $this->store->session($vehicle['id']);
        try {
            if (!$session) { $session = $this->client->login($vehicle['login'], $vehicle['password']); }
            try { $result = $action($session); }
            catch (PandoraException $error) {
                if ($error->getStatus() !== 401) { throw $error; }
                $session = $this->client->login($vehicle['login'], $vehicle['password']);
                $result = $action($session);
            }
            $this->assertUnchanged($vehicle);
            $this->store->writeSession($vehicle['id'], $session);
            return $result;
        } catch (PandoraException $error) {
            if ($error->getStatus() === 401) { $this->store->clearSession($vehicle['id']); }
            throw $error;
        }
    }

    private function assertUnchanged(array $before): void
    {
        $current = $this->store->credentials($before['id']);
        foreach (['login', 'password', 'device_id', 'enabled', 'allow_control'] as $field) {
            if ($current[$field] !== $before[$field]) {
                throw new \RuntimeException('Der Fahrzeugzugang wurde geändert. Bitte erneut abrufen.');
            }
        }
    }

    public function refresh(string $id): array
    {
        return $this->store->locked($id, function () use ($id): array {
            $vehicle = $this->store->credentials($id);
            if (!$vehicle['enabled']) { throw new \InvalidArgumentException('Das Fahrzeug ist deaktiviert.'); }
            try {
                $state = $this->authenticated($vehicle, function (array &$session) use ($vehicle): array {
                    $vehicles = $this->client->vehicles($session);
                    $deviceId = $vehicle['device_id'];
                    $discovered = array_map(static function (array $item): array {
                        return ['id' => (string) $item['id'], 'name' => $item['name']];
                    }, array_values($vehicles));
                    if ($deviceId === '' && count($vehicles) === 1) { $deviceId = (string) reset($vehicles)['id']; }
                    if ($deviceId === '') {
                        return ['updated_at' => time(), 'vehicle' => null, 'events' => [], 'discovered' => $discovered,
                            'error' => $vehicles ? 'Mehrere Fahrzeuge gefunden. Bitte eine Pandora-Fahrzeug-ID auswählen.' : 'In diesem Konto wurde kein Fahrzeug gefunden.'];
                    }
                    if (!isset($vehicles[$deviceId])) {
                        throw new PandoraException(404, 'Das gewählte Fahrzeug ist in diesem Pandora-Konto nicht verfügbar.');
                    }
                    try { $history = $this->client->events($session, $deviceId); }
                    catch (PandoraException $error) {
                        if (!in_array($error->getStatus(), [403, 404], true)) { throw $error; }
                        $history = ['events' => [], 'historyAvailable' => false, 'note' => 'Fahrzeugmeldungen sind für diesen Zugang nicht freigegeben.'];
                    }
                    // events() refreshes account membership and permissions a second time.
                    // Project the final state so revoked rights cannot leave an older value visible.
                    $vehicles = $this->client->currentVehicles($session);
                    if (!isset($vehicles[$deviceId])) {
                        throw new PandoraException(404, 'Das gewählte Fahrzeug ist in diesem Pandora-Konto nicht verfügbar.');
                    }
                    return ['updated_at' => time(), 'vehicle' => $vehicles[$deviceId], 'events' => $history['events'],
                        'history_note' => $history['note'], 'history_available' => $history['historyAvailable'],
                        'discovered' => $discovered, 'error' => ''];
                });
                if ($vehicle['device_id'] === '' && isset($state['vehicle']['id'])) {
                    $this->store->selectDevice($id, (string) $state['vehicle']['id']);
                }
                $previous = $this->store->state($id);
                if (isset($previous['last_command'])) { $state['last_command'] = $previous['last_command']; }
                $this->store->writeState($id, $state);
                return $state;
            } catch (\Throwable $error) {
                try { $this->assertUnchanged($vehicle); }
                catch (\Throwable $changed) { return $this->store->state($id); }
                // Preserve the timestamp of the last successful read, but mark the cache unavailable.
                $state = $this->store->state($id);
                $state['error'] = self::safeError($error);
                $state['error_at'] = time();
                // Membership or permissions may have been revoked: remove sensitive cached state.
                if ($error instanceof PandoraException && in_array($error->getStatus(), [401, 403, 404], true)) {
                    $state['vehicle'] = null;
                    $state['events'] = [];
                    $state['discovered'] = [];
                }
                $this->store->writeState($id, $state);
                return $state;
            }
        });
    }

    public function enqueue(string $id, string $command): string
    {
        if (!isset(PandoraClient::commands()[$command])) { throw new \InvalidArgumentException('Unbekannter Fahrzeugbefehl.'); }
        $settings = $this->store->settings();
        $vehicle = $this->store->all()[$id] ?? null;
        if (!$settings['enabled'] || !$vehicle || !$vehicle['enabled'] || !$vehicle['allow_control']) {
            throw new \InvalidArgumentException('Die Steuerung ist für dieses Fahrzeug nicht freigegeben.');
        }
        return $this->store->enqueue($id, $command);
    }

    public function execute(array $request): array
    {
        $id = (string) ($request['vehicle_id'] ?? '');
        $command = (string) ($request['command'] ?? '');
        $result = ['request_id' => (string) ($request['id'] ?? ''), 'command' => $command,
            'status' => 'rejected', 'message' => '', 'timestamp' => gmdate('c')];
        try {
            if (!Store::validId($id) || !isset(PandoraClient::commands()[$command])) {
                throw new \InvalidArgumentException('Unbekannter Fahrzeugbefehl.');
            }
            $age = time() - (int) ($request['created_at'] ?? 0);
            if ($age < 0 || $age > 30) { throw new \InvalidArgumentException('Befehl abgelaufen. Bitte erneut auslösen.'); }
            return $this->store->locked($id, function () use ($id, $command, $result, $request): array {
                $vehicle = $this->store->credentials($id);
                if (empty($request['revision']) || ($request['revision'] ?? '') !== ($vehicle['revision'] ?? '')) {
                    throw new \InvalidArgumentException('Der Fahrzeugeintrag wurde geändert. Bitte den Befehl erneut auslösen.');
                }
                if (!$this->store->settings()['enabled'] || !$vehicle['enabled'] || !$vehicle['allow_control']) {
                    throw new \InvalidArgumentException('Die Steuerung ist für dieses Fahrzeug nicht freigegeben.');
                }
                $session = $this->store->session($id);
                if (!$session) { $session = $this->client->login($vehicle['login'], $vehicle['password']); }
                // Establish/renew authentication before the physical command. Never retry a send.
                try { $vehicles = $this->client->vehicles($session); }
                catch (PandoraException $error) {
                    if ($error->getStatus() !== 401) { throw $error; }
                    $session = $this->client->login($vehicle['login'], $vehicle['password']);
                    $vehicles = $this->client->vehicles($session);
                }
                if ($vehicle['device_id'] === '' || !isset($vehicles[$vehicle['device_id']])) {
                    throw new \InvalidArgumentException('Bitte zuerst ein Pandora-Fahrzeug zuordnen und den Status abrufen.');
                }
                $this->assertUnchanged($vehicle);
                $sent = $this->client->command($session, $vehicle['device_id'], $command);
                $this->assertUnchanged($vehicle);
                $this->store->writeSession($id, $session);
                return array_replace($result, $sent, ['timestamp' => gmdate('c')]);
            });
        } catch (\Throwable $error) {
            if ($error instanceof CommandUncertain) { $result['status'] = 'unknown'; }
            $result['message'] = self::safeError($error);
            return $result;
        }
    }

    public static function safeError(\Throwable $error): string
    {
        if ($error instanceof PandoraException || $error instanceof \InvalidArgumentException) { return $error->getMessage(); }
        return 'Plugin-Anfrage fehlgeschlagen. Bitte Verbindung und Diagnose prüfen.';
    }
}
