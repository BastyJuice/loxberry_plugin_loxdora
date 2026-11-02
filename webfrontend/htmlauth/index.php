<?php
declare(strict_types=1);

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once $lbpbindir . '/bootstrap.php';

use PandoraLox\Store;
use PandoraLox\Service;
use PandoraLox\PandoraClient;
use PandoraLox\VehicleProfile;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('pandora_loxberry');
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
    session_start();
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if (empty($_SESSION['pandora_csrf'])) { $_SESSION['pandora_csrf'] = bin2hex(random_bytes(32)); }

function pcH($value): string { return htmlspecialchars(is_scalar($value) || $value === null ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pcPost(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    if (!is_string($value)) { throw new InvalidArgumentException('Ungültiger Formularwert.'); }
    return $value;
}
function pcQuery(string $key): string { $value = $_GET[$key] ?? ''; return is_string($value) ? $value : ''; }
function pcCsrf(): void { echo '<input type="hidden" name="csrf" value="' . pcH($_SESSION['pandora_csrf']) . '">'; }
function pcHidden(string $name, string $value): void { echo '<input type="hidden" name="' . pcH($name) . '" value="' . pcH($value) . '">'; }
function pcDate($value): string {
    if ($value === null || $value === '' || $value === 0) { return '—'; }
    try {
        $date = is_numeric($value) ? new DateTimeImmutable('@' . (int) $value) : new DateTimeImmutable((string) $value);
        return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i:s');
    } catch (Exception $ignored) { return '—'; }
}
function pcBinary($value, string $yes = 'Ja', string $no = 'Nein'): string { return $value === true ? $yes : ($value === false ? $no : '—'); }
function pcNumber($value, string $unit = '', int $decimals = 2): string {
    if (!is_numeric($value) || !is_finite((float) $value)) { return '—'; }
    $formatted = number_format((float) $value, $decimals, ',', '.');
    return ($decimals > 0 ? rtrim(rtrim($formatted, '0'), ',') : $formatted) . $unit;
}
function pcStale(?array $vehicle): bool {
    $recorded = $vehicle !== null && !empty($vehicle['updatedAt']) ? strtotime($vehicle['updatedAt']) : false;
    return $recorded === false || time() - $recorded > 300 || $recorded > time() + 60;
}
function pcRedirect(string $tab, string $id = ''): void {
    header('Location: index.php?' . http_build_query(['tab' => $tab, 'vehicle' => $id], '', '&', PHP_QUERY_RFC3986), true, 303);
    exit;
}

$store = new Store($lbpconfigdir, $lbpdatadir);
$service = new Service($store);
$tab = pcQuery('tab');
if (!in_array($tab, ['dashboard', 'vehicles', 'mqtt', 'diagnostics'], true)) { $tab = 'dashboard'; }
$selectedId = pcQuery('vehicle');
$message = (string) ($_SESSION['pandora_flash'] ?? '');
unset($_SESSION['pandora_flash']);
$error = '';
$draft = null;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals((string) $_SESSION['pandora_csrf'], pcPost('csrf'))) {
            throw new InvalidArgumentException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
        }
        $action = pcPost('action');
        $selectedId = pcPost('vehicle');
        if ($action === 'save_vehicle') {
            $tab = 'vehicles';
            $existingIcon = $store->all()[$selectedId]['vehicle_icon'] ?? 'auto';
            $draft = ['id' => $selectedId, 'name' => pcPost('name'), 'vehicle_icon' => pcPost('vehicle_icon', $existingIcon), 'login' => pcPost('login'), 'device_id' => pcPost('device_id'),
            'enabled' => pcPost('enabled') === '1', 'allow_control' => pcPost('allow_control') === '1'];
            $selectedId = $store->saveVehicle($draft + ['password' => pcPost('password')]);
            $_SESSION['pandora_flash'] = 'Fahrzeug gespeichert. Mit „Status abrufen“ die Verbindung prüfen und die Fahrzeugdaten laden.';
            pcRedirect('vehicles', $selectedId);
        } elseif ($action === 'delete_vehicle') {
            $store->deleteVehicle($selectedId);
            $_SESSION['pandora_flash'] = 'Fahrzeug und seine gespeicherten Zugangsdaten entfernt.';
            pcRedirect('vehicles');
        } elseif ($action === 'refresh') {
            $service->refresh($selectedId);
            $_SESSION['pandora_flash'] = 'Abruf abgeschlossen. Fahrzeugauswahl und Status werden unten angezeigt.';
            pcRedirect(pcPost('return_tab') === 'dashboard' ? 'dashboard' : 'vehicles', $selectedId);
        } elseif ($action === 'command') {
            $command = pcPost('command');
            if (!array_key_exists($command, PandoraClient::commands())) { throw new InvalidArgumentException('Unbekannter Fahrzeugbefehl.'); }
            $requestId = $service->enqueue($selectedId, $command);
            $_SESSION['pandora_flash'] = 'Befehl zur Verarbeitung vorgemerkt (' . $requestId . '). Die Ausführung ist damit noch nicht bestätigt. Status nach einigen Sekunden neu laden.';
            pcRedirect('vehicles', $selectedId);
        } elseif ($action === 'save_settings') {
            $tab = 'mqtt';
            $store->saveSettings(['poll_interval' => (int) pcPost('poll_interval'), 'topic_prefix' => pcPost('topic_prefix'), 'enabled' => pcPost('enabled') === '1']);
            $_SESSION['pandora_flash'] = 'MQTT- und Abfrageeinstellungen gespeichert. Der Hintergrunddienst übernimmt die Änderung automatisch.';
            pcRedirect('mqtt');
        } else { throw new InvalidArgumentException('Unbekannte Aktion.'); }
    }
} catch (Throwable $exception) {
    // Only intentional, public validation/API errors may reach the browser.
    // Unexpected transport/storage messages can contain local paths or secrets.
    $error = $exception instanceof InvalidArgumentException || $exception instanceof \PandoraLox\PandoraException
        ? $exception->getMessage() : 'Die Aktion konnte nicht abgeschlossen werden. Bitte die Diagnose und die LoxBerry-Protokolle prüfen.';
}

$vehicles = $store->all();
$settings = $store->settings();
$runtime = $store->runtime();
$selected = $vehicles[$selectedId] ?? null;
if ($selected === null) { $selectedId = ''; }
$state = $selected !== null ? $store->state($selectedId) : [];
$vehicle = is_array($state['vehicle'] ?? null) ? $state['vehicle'] : null;
$status = is_array($vehicle['status'] ?? null) ? $vehicle['status'] : [];
$events = is_array($state['events'] ?? null) ? $state['events'] : [];
$discovered = is_array($state['discovered'] ?? null) ? $state['discovered'] : [];
if ($draft === null) { $draft = $selected ?? ['id' => '', 'name' => '', 'login' => '', 'device_id' => '', 'enabled' => true, 'allow_control' => false]; }
$draft = array_replace(['vehicle_icon' => 'auto'], $draft);
$selectedName = $selected !== null ? VehicleProfile::displayName($selected, $vehicle ?? []) : '';
$prefix = (string) ($settings['topic_prefix'] ?? 'pandora');
$exampleId = $selectedId !== '' ? $selectedId : (string) (array_key_first($vehicles) ?? 'fahrzeug_1');
$baseTopic = $prefix . '/' . $exampleId;
$navbar = [
    5 => ['Name' => 'Dashboard', 'URL' => 'index.php?tab=dashboard', 'active' => $tab === 'dashboard'],
    10 => ['Name' => 'Meine Fahrzeuge', 'URL' => 'index.php?tab=vehicles', 'active' => $tab === 'vehicles'],
    20 => ['Name' => 'MQTT & Loxone', 'URL' => 'index.php?tab=mqtt', 'active' => $tab === 'mqtt'],
    30 => ['Name' => 'Diagnose', 'URL' => 'index.php?tab=diagnostics', 'active' => $tab === 'diagnostics'],
];
LBWeb::lbheader('Loxdora', 'https://wiki.loxberry.de/entwickler/plugin_fur_den_loxberry_entwickeln_ab_version_1x/start', '', true);
?>
<style>
.pandora-connect { max-width: 1180px; margin: auto; }
.pandora-connect .lb-card { margin-bottom: 1rem; }
.pandora-connect .lb-input { max-width: 580px; }
.pandora-connect .pc-scroll { max-width: 100%; overflow-x: auto; }
.pandora-connect .lb-table th, .pandora-connect .lb-table td { vertical-align: top; overflow-wrap: anywhere; }
.pandora-connect .pc-fleet-table { min-width: 720px; }
.pandora-connect .pc-fleet-table th, .pandora-connect .pc-fleet-table td { overflow-wrap: normal; word-break: normal; }
.pandora-connect .pc-fleet-table th:first-child { min-width: 130px; }
.pandora-connect .pc-inline { display: inline-block; margin: .2rem .2rem .2rem 0; }
.pandora-connect .pc-state-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(170px,1fr)); gap: .75rem; margin: 1rem 0; }
.pandora-connect .pc-state-grid > div { padding: .75rem; border: 1px solid var(--lb-border-color,#d9ded9); border-radius: var(--lb-border-radius,6px); }
.pandora-connect .pc-state-grid dt { font-size: .9rem; margin-bottom: .3rem; }
.pandora-connect .pc-state-grid dd { font-weight: 600; margin: 0; }
.pandora-connect .pc-muted { opacity: .75; font-size: .92rem; }
.pandora-connect .pc-check { display: block; margin: .7rem 0; }
.pandora-connect code { overflow-wrap: anywhere; }
.pandora-connect pre { white-space: pre-wrap; overflow-wrap: anywhere; }
.pandora-connect .pc-event-alarm { font-weight: 700; }
.pandora-connect .pc-no-wrap { white-space: nowrap; }
.pandora-connect .pc-icon-fieldset { border: 0; padding: 0; margin: 1.4rem 0; min-width: 0; }
.pandora-connect .pc-icon-fieldset legend { font-weight: 600; margin-bottom: .45rem; }
.pandora-connect .pc-icon-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(140px,1fr)); gap: .75rem; margin: .75rem 0; }
.pandora-connect .pc-icon-choice { position: relative; display: block; cursor: pointer; min-width: 0; }
.pandora-connect .pc-icon-choice input { position: absolute; top: 12px; left: 12px; z-index: 1; width: 16px; height: 16px; margin: 0; accent-color: #38774b; }
.pandora-connect .pc-icon-tile { display: flex; flex-direction: column; align-items: center; min-height: 135px; padding: 25px 8px 12px; border: 2px solid var(--lb-border-color,#dfe7e1); border-radius: 12px; background: var(--lb-card-bg,#fff); transition: border-color .15s,background .15s; }
.pandora-connect .pc-icon-tile svg { display: block; width: 100%; max-width: 160px; height: 75px; margin: 0 0 4px; }
.pandora-connect .pc-icon-tile strong { font-size: .9rem; font-weight: 600; }
.pandora-connect .pc-icon-tile small { color: #64736b; font-size: .75rem; margin-top: 2px; }
.pandora-connect .pc-icon-choice input:checked + .pc-icon-tile { border-color: #4f8c53; background: #f0f7ef; }
.pandora-connect .pc-icon-choice input:focus-visible + .pc-icon-tile { outline: 3px solid #8bbf67; outline-offset: 3px; }
@media(prefers-reduced-motion:reduce) { .pandora-connect .pc-icon-tile { transition: none; } }
</style>
<div class="lb-content pandora-connect">
    <?php if ($message !== ''): ?><p class="lb-callout" role="status"><?= pcH($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="lb-callout lb-callout-warning" role="alert"><?= pcH($error) ?></p><?php endif; ?>

    <?php if ($tab === 'dashboard'): ?>
        <?php require __DIR__ . '/dashboard.php'; ?>
    <?php elseif ($tab === 'vehicles'): ?>
    <p>Pandora-Fahrzeuge mit Loxone verbinden. Für jedes Fahrzeug lassen sich eigene Pandora-Zugangsdaten hinterlegen; mehrere Einträge können dasselbe Konto nutzen.</p>
    <section class="lb-card">
        <h2 class="lb-section-title">Meine Fahrzeuge</h2>
        <?php if (!$vehicles): ?>
        <p>Noch kein Fahrzeug angelegt. Trage unten einen Namen und die Zugangsdaten des Pandora-Kontos ein. Anschließend kannst du die verfügbaren Fahrzeuge abrufen.</p>
        <?php else: ?>
        <div class="pc-scroll"><table class="lb-table pc-fleet-table"><thead><tr><th>Fahrzeug</th><th>Verbindung / letzter Abruf</th><th>Schutz / letzte Meldung</th><th>Aktionen</th></tr></thead><tbody>
        <?php foreach ($vehicles as $entry):
            $entryId = (string) $entry['id'];
            $snapshot = $store->state($entryId);
            $snapshotVehicle = is_array($snapshot['vehicle'] ?? null) ? $snapshot['vehicle'] : [];
            $entryName = VehicleProfile::displayName($entry, $snapshotVehicle);
            $snapshotEvents = is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [];
            $latest = $snapshotEvents ? reset($snapshotEvents) : null;
        ?>
        <tr><th scope="row"><?= pcH($entryName) ?><br><code class="pc-muted"><?= pcH($entryId) ?></code></th>
            <td><?= empty($entry['enabled']) ? 'Abfrage pausiert' : pcH(pcBinary($snapshotVehicle['online'] ?? null, 'Online', 'Offline')) ?><br><span class="pc-muted"><?= pcH(pcDate($snapshot['updated_at'] ?? null)) ?></span>
                <?php if ($snapshotVehicle && pcStale($snapshotVehicle)): ?><br><span>Fahrzeugdaten veraltet / Zeitpunkt unbekannt</span><?php endif; ?>
                <?php if (!empty($snapshot['error'])): ?><br><span><?= pcH($snapshot['error']) ?></span><?php endif; ?></td>
            <td><?= pcH(pcBinary($snapshotVehicle['status']['armed'] ?? null, 'Scharf', 'Unscharf')) ?>
                <?php if (is_array($latest)): ?><br><span class="pc-muted"><?= pcH($latest['title'] ?? $latest['label'] ?? 'Pandora-Meldung') ?></span><?php endif; ?></td>
            <td><a class="lb-btn" href="?tab=vehicles&amp;vehicle=<?= pcH(rawurlencode($entryId)) ?>">Details / Zugang</a>
                <form class="pc-inline" method="post" action="index.php"><?php pcCsrf(); pcHidden('vehicle', $entryId); ?><button class="lb-btn lb-btn-primary" name="action" value="refresh">Status abrufen</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <p><a class="lb-btn" href="?tab=vehicles#vehicle-form">Fahrzeug hinzufügen</a></p>
        <?php endif; ?>
    </section>

    <?php if ($selected !== null): ?>
    <section class="lb-card" id="vehicle-status"><h2 class="lb-section-title"><?= pcH($selectedName) ?> · Status</h2>
        <?php if (!empty($state['error'])): ?><p class="lb-callout lb-callout-warning" role="status"><?= pcH($state['error']) ?></p><?php endif; ?>
        <?php if ($vehicle === null): ?><p>Noch keine Fahrzeugdaten vorhanden. „Status abrufen“ prüft die Anmeldung und sucht die Fahrzeuge dieses Pandora-Kontos.</p>
        <?php else: ?>
        <?php if (pcStale($vehicle)): ?><p class="lb-callout lb-callout-warning">Die Fahrzeugdaten sind älter als fünf Minuten oder haben keinen verlässlichen Zeitstempel. Die angezeigten Werte beschreiben den letzten bekannten Zustand.</p><?php endif; ?>
        <p><?= pcH($vehicle['name'] ?? '') ?><?= !empty($vehicle['model']) ? ' · ' . pcH($vehicle['model']) : '' ?> · <?= pcH(pcBinary($vehicle['online'] ?? null, 'Online', 'Offline')) ?><br>
            <span class="pc-muted">Fahrzeugdaten: <?= pcH(pcDate($vehicle['updatedAt'] ?? null)) ?> · Letzter Abruf: <?= pcH(pcDate($state['updated_at'] ?? null)) ?>. „—“ bedeutet: kein bekannter Wert.</span></p>
        <dl class="pc-state-grid">
        <?php foreach ([
            'armed' => ['Alarmanlage', pcBinary($status['armed'] ?? null, 'Scharf', 'Unscharf')],
            'alarm' => ['Alarm', pcBinary($status['alarm'] ?? null, 'Aktiv', 'Kein Alarm')],
            'ignition' => ['Zündung', pcBinary($status['ignition'] ?? null, 'An', 'Aus')],
            'engineRunning' => ['Motor', pcBinary($status['engineRunning'] ?? null, 'Läuft', 'Aus')],
            'moving' => ['Bewegung', pcBinary($status['moving'] ?? null, 'In Bewegung', 'Steht')],
            'doorsOpen' => ['Türen', pcBinary($status['doorsOpen'] ?? null, 'Geöffnet', 'Geschlossen')],
            'trunkOpen' => ['Kofferraum', pcBinary($status['trunkOpen'] ?? null, 'Geöffnet', 'Geschlossen')],
            'hoodOpen' => ['Motorhaube', pcBinary($status['hoodOpen'] ?? null, 'Geöffnet', 'Geschlossen')],
            'heaterOn' => ['Standheizung', pcBinary($status['heaterOn'] ?? null, 'An', 'Aus')],
            'batteryVoltage' => ['Batteriespannung', pcNumber($status['batteryVoltage'] ?? null, ' V')],
            'fuelPercent' => ['Kraftstoff', pcNumber($status['fuelPercent'] ?? null, ' %')],
            'cabinTemperature' => ['Innenraum', pcNumber($status['cabinTemperature'] ?? null, ' °C')],
            'engineTemperature' => ['Motortemperatur', pcNumber($status['engineTemperature'] ?? null, ' °C')],
            'outsideTemperature' => ['Außentemperatur', pcNumber($status['outsideTemperature'] ?? null, ' °C')],
            'speed' => ['Geschwindigkeit', pcNumber($status['speed'] ?? null, ' km/h')],
            'canMileageKm' => ['CAN-Kilometerstand', pcNumber($status['canMileageKm'] ?? null, ' km')],
            'gsmLevel' => ['Mobilfunksignal', pcNumber($status['gsmLevel'] ?? null)],
        ] as $item): ?><div><dt><?= pcH($item[0]) ?></dt><dd><?= pcH($item[1]) ?></dd></div><?php endforeach; ?>
        </dl>
        <?php $position = is_array($vehicle['position'] ?? null) ? $vehicle['position'] : null; if ($position !== null): ?>
        <p>Position: <?= pcH(pcNumber($position['latitude'] ?? null, '', 6)) ?>, <?= pcH(pcNumber($position['longitude'] ?? null, '', 6)) ?> · Aufzeichnung: <?= pcH(pcDate($position['updatedAt'] ?? null)) ?></p>
        <?php endif; endif; ?>
        <form class="pc-inline" method="post" action="index.php"><?php pcCsrf(); pcHidden('vehicle', $selectedId); ?><button class="lb-btn lb-btn-primary" name="action" value="refresh">Status abrufen</button></form>
        <a class="lb-btn" href="?tab=vehicles&amp;vehicle=<?= pcH(rawurlencode($selectedId)) ?>">Anzeige neu laden</a>
    </section>

    <section class="lb-card"><h2 class="lb-section-title">Fahrzeug steuern</h2>
        <?php if (empty($selected['allow_control'])): ?><p>Die Steuerung ist für dieses Fahrzeug ausgeschaltet. Du kannst sie unten in den Zugangseinstellungen freigeben.</p>
        <?php else: ?>
        <p>Der Hintergrunddienst prüft vor dem Senden die Pandora-Berechtigungen und den aktuellen Fahrzeugstatus. Eine vorgemerkte oder gesendete Aktion ist noch keine Ausführungsbestätigung.</p>
        <?php
        $availableCommands = is_array($vehicle['commands'] ?? null) ? $vehicle['commands'] : [];
        $recorded = !empty($vehicle['updatedAt']) ? strtotime($vehicle['updatedAt']) : false;
        $controlReady = !empty($settings['enabled']) && !empty($selected['enabled']) && empty($state['error'])
            && $recorded !== false && time() - $recorded >= -5 && time() - $recorded <= 60;
        ?>
        <?php foreach (PandoraClient::commands() as $key => $metadata): $available = $controlReady && in_array($key, $availableCommands, true); ?>
        <form class="pc-inline" method="post" action="index.php" data-confirm="Diesen Befehl für <?= pcH($selectedName) ?> vormerken: <?= pcH($metadata['label']) ?>?">
            <?php pcCsrf(); pcHidden('vehicle', $selectedId); pcHidden('action', 'command'); pcHidden('command', $key); ?>
            <button class="lb-btn" type="submit" <?= $available ? '' : 'disabled' ?>><?= pcH($metadata['label']) ?></button>
        </form>
        <?php endforeach; ?>
        <?php if (!$availableCommands || !$controlReady): ?><p class="pc-muted">Derzeit sind keine Steuerungsbefehle verfügbar. Die Hintergrundverarbeitung muss aktiv sein. Bitte Status abrufen; Pandora muss das Fahrzeug mit aktuellen Daten, passender Ausstattung und Steuerungsrecht melden.</p><?php endif; ?>
        <?php endif; ?>
        <?php $lastCommand = is_array($state['last_command'] ?? null) ? $state['last_command'] : []; if ($lastCommand): ?>
        <p><strong>Letzter Befehl:</strong> <?= pcH($lastCommand['command'] ?? '') ?> · <?= pcH($lastCommand['status'] ?? '') ?><br><?= pcH($lastCommand['message'] ?? '') ?></p>
        <?php endif; ?>
    </section>

    <section class="lb-card"><h2 class="lb-section-title">Letzte Fahrzeugmeldungen</h2>
        <?php if (!$events): ?><p>Noch keine Meldungen verfügbar. „Status abrufen“ lädt die von Pandora verfügbaren Einträge.</p>
        <?php else: ?><div class="pc-scroll"><table class="lb-table"><thead><tr><th>Zeitpunkt</th><th>Meldung</th><th>Details</th></tr></thead><tbody>
        <?php foreach (array_slice($events, 0, 50) as $event): if (!is_array($event)) { continue; } ?>
        <tr><td class="pc-no-wrap"><?= pcH(pcDate($event['occurredAt'] ?? null)) ?></td><td class="<?= ($event['severity'] ?? '') === 'alarm' ? 'pc-event-alarm' : '' ?>"><?= pcH($event['title'] ?? $event['label'] ?? 'Pandora-Meldung') ?></td><td><?= pcH($event['description'] ?? '') ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
        <p class="pc-muted"><?= pcH($state['history_note'] ?? 'Höchstens 50 verfügbare Meldungen der letzten sieben Tage. Ohne bekannten Aufzeichnungszeitpunkt bleibt das Datum unbekannt.') ?></p>
    </section>
    <?php endif; ?>

    <form method="post" action="index.php" autocomplete="off" class="lb-card" id="vehicle-form">
        <?php pcCsrf(); pcHidden('action', 'save_vehicle'); pcHidden('vehicle', (string) $draft['id']); ?>
        <h2 class="lb-section-title"><?= $draft['id'] === '' ? 'Fahrzeug hinzufügen' : 'Fahrzeug-Zugang bearbeiten' ?></h2>
        <?php if ($draft['id'] !== ''): ?><p>Fester MQTT-Fahrzeugname: <code><?= pcH($draft['id']) ?></code></p><?php endif; ?>
        <?php foreach (['name' => ['Eigener Anzeigename (optional)', 'Name aus Pandora verwenden'], 'login' => ['Pandora-Benutzername', 'Benutzername des Pandora-Kontos']] as $key => $field): ?>
        <div class="lb-form-row"><label class="lb-form-label" for="vehicle-<?= pcH($key) ?>"><?= pcH($field[0]) ?></label><div class="lb-form-field"><input class="lb-input" id="vehicle-<?= pcH($key) ?>" name="<?= pcH($key) ?>" value="<?= pcH($draft[$key]) ?>" placeholder="<?= pcH($field[1]) ?>" maxlength="<?= $key === 'name' ? 120 : 160 ?>" <?= $key === 'login' ? 'required' : '' ?>>
            <?php if ($key === 'name'): ?><p class="pc-muted">Leer lassen, um den Fahrzeugnamen aus Pandora automatisch zu verwenden. Bis zum ersten Abruf erscheint ein vorläufiger Fahrzeugname.</p>
                <?php if (is_string($vehicle['name'] ?? null) && trim($vehicle['name']) !== ''): ?><p class="pc-muted">Aktueller Name aus Pandora: <strong><?= pcH($vehicle['name']) ?></strong></p><?php endif; ?>
            <?php endif; ?>
        </div></div>
        <?php endforeach; ?>
        <div class="lb-form-row"><label class="lb-form-label" for="vehicle-password">Pandora-Passwort</label><div class="lb-form-field"><input class="lb-input" type="password" id="vehicle-password" name="password" value="" autocomplete="new-password" maxlength="512" <?= $draft['id'] === '' ? 'required' : '' ?>><p class="pc-muted"><?= $draft['id'] === '' ? 'Das Passwort wird für den automatischen Datenabruf verschlüsselt auf dem LoxBerry gespeichert.' : 'Leer lassen, um das gespeicherte Passwort beizubehalten. Bei geändertem Benutzernamen auch das Passwort neu eingeben.' ?></p></div></div>
        <div class="lb-form-row"><label class="lb-form-label" for="vehicle-device">Pandora-Fahrzeug-ID</label><div class="lb-form-field">
            <?php if ($discovered): ?>
            <select class="lb-input" id="vehicle-device" name="device_id"><option value="">Bitte ein Fahrzeug auswählen</option>
                <?php foreach ($discovered as $deviceKey => $candidate): if (!is_array($candidate)) { continue; } $candidateId = (string) ($candidate['id'] ?? $deviceKey); ?>
                <option value="<?= pcH($candidateId) ?>" <?= (string) $draft['device_id'] === $candidateId ? 'selected' : '' ?>><?= pcH(($candidate['name'] ?? 'Pandora-Fahrzeug') . ' · ' . $candidateId) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?><input class="lb-input" id="vehicle-device" name="device_id" value="<?= pcH($draft['device_id']) ?>" maxlength="128" placeholder="Wird nach dem ersten Abruf ermittelt"><?php endif; ?>
            <p class="pc-muted">Beim ersten Anlegen darf das Feld leer bleiben. Nach dem Speichern „Status abrufen“ wählen. Enthält das Konto genau ein Fahrzeug, wird es automatisch zugeordnet; bei mehreren Fahrzeugen hier das gewünschte auswählen und erneut speichern.</p>
        </div></div>
        <fieldset class="pc-icon-fieldset"><legend>Fahrzeugsymbol</legend><p class="pc-muted">Wähle die passende Darstellung für dein Dashboard. „Automatisch“ nutzt die von Pandora bereitgestellten Fahrzeugangaben. Wenn Pandora keinen eindeutigen Typ liefert, wird ein allgemeines Pkw-Symbol angezeigt. Du kannst das passende Symbol selbst wählen.</p>
            <div class="pc-icon-grid">
            <?php foreach (VehicleProfile::choices() as $iconKey => $iconLabel): $previewIcon = $iconKey === 'auto' ? VehicleProfile::icon(['vehicle_icon' => 'auto'], $vehicle ?? []) : $iconKey; ?>
            <label class="pc-icon-choice"><input type="radio" name="vehicle_icon" value="<?= pcH($iconKey) ?>" <?= $draft['vehicle_icon'] === $iconKey ? 'checked' : '' ?>><span class="pc-icon-tile"><?= VehicleProfile::artwork($previewIcon) ?><strong><?= pcH($iconLabel) ?></strong><?php if ($iconKey === 'auto'): ?><small>Wenn verfügbar</small><?php endif; ?></span></label>
            <?php endforeach; ?>
            </div>
        </fieldset>
        <label class="pc-check"><input type="checkbox" name="enabled" value="1" <?= !empty($draft['enabled']) ? 'checked' : '' ?>> Automatische Abfrage für dieses Fahrzeug aktiv</label>
        <label class="pc-check"><input type="checkbox" name="allow_control" value="1" <?= !empty($draft['allow_control']) ? 'checked' : '' ?>> Fahrzeugsteuerung über Plugin und MQTT freigeben</label>
        <p class="pc-muted">Die Freigabe erlaubt die verfügbaren Befehle zum Scharf-/Unscharfschalten, für Standheizung und Zusatzkanal. Pandora-Berechtigungen und Ausstattung bestimmen die verfügbaren Funktionen.</p>
        <button class="lb-btn lb-btn-primary" type="submit">Fahrzeug speichern</button>
        <?php if ($draft['id'] !== ''): ?><a class="lb-btn" href="?tab=vehicles#vehicle-form">Neues Fahrzeug</a><?php endif; ?>
    </form>
    <?php if ($selected !== null): ?><form method="post" action="index.php" class="lb-card" data-confirm="Fahrzeug <?= pcH($selectedName) ?> mit seinen gespeicherten Zugangsdaten entfernen?">
        <?php pcCsrf(); pcHidden('vehicle', $selectedId); ?><h2 class="lb-section-title"><?= pcH($selectedName) ?> entfernen</h2><p>Entfernt diesen Eintrag aus dem Plugin. Das Fahrzeug bleibt im Pandora-Konto bestehen.</p><button class="lb-btn" name="action" value="delete_vehicle">Fahrzeug entfernen</button>
    </form><?php endif; ?>

    <?php elseif ($tab === 'mqtt'): ?>
    <form method="post" action="index.php" class="lb-card">
        <?php pcCsrf(); pcHidden('action', 'save_settings'); ?><h2 class="lb-section-title">MQTT & Abfrage</h2>
        <p>Das Plugin nutzt die Broker-Zugangsdaten der LoxBerry-MQTT-Einstellungen. Es wird kein zusätzlicher MQTT-Broker installiert.</p>
        <div class="lb-form-row"><label class="lb-form-label" for="topic-prefix">MQTT-Themenpräfix</label><div class="lb-form-field"><input class="lb-input" id="topic-prefix" name="topic_prefix" value="<?= pcH($prefix) ?>" maxlength="80" required><p class="pc-muted">Standard: <code>pandora</code>. Bei Änderungen auch die MQTT-Gateway-Abonnements und Loxone-Ausgänge anpassen.</p></div></div>
        <div class="lb-form-row"><label class="lb-form-label" for="poll-interval">Abfrageintervall in Sekunden</label><div class="lb-form-field"><input class="lb-input" type="number" id="poll-interval" name="poll_interval" value="<?= pcH($settings['poll_interval'] ?? 60) ?>" min="30" max="3600" step="1" required></div></div>
        <label class="pc-check"><input type="checkbox" name="enabled" value="1" <?= !array_key_exists('enabled', $settings) || !empty($settings['enabled']) ? 'checked' : '' ?>> Hintergrundabfrage und MQTT-Verarbeitung aktiv</label>
        <button class="lb-btn lb-btn-primary" type="submit">Einstellungen speichern</button>
    </form>
    <section class="lb-card"><h2 class="lb-section-title">Status in Loxone verwenden</h2>
        <ol><li>Den MQTT-Broker und den Miniserver in LoxBerry konfigurieren.</li>
            <li>Im LoxBerry MQTT Gateway die gewünschten Status-Themen abonnieren, zum Beispiel <code><?= pcH($prefix) ?>/+/state/#</code>. Für den letzten Meldungstext zusätzlich <code><?= pcH($prefix) ?>/+/events/latest/text</code> abonnieren.</li>
            <li>Die vom MQTT Gateway erzeugten virtuellen Eingänge in Loxone Config übernehmen. Binäre Statuswerte werden als <code>1</code> oder <code>0</code> veröffentlicht. Unbekannte Werte bleiben leer.</li>
            <li>Alarmstatus, Türen, Spannung und Temperaturen mit den gewünschten Loxone-Bausteinen verbinden. Den Zeitstempel und die Verfügbarkeit verwenden, um veraltete Fahrzeugdaten zu erkennen.</li></ol>
        <div class="pc-scroll"><table class="lb-table"><thead><tr><th>Thema · Beispiel <?= pcH($exampleId) ?></th><th>Inhalt</th></tr></thead><tbody>
            <?php foreach ([
                'state/armed' => 'Alarmanlage scharf: 1 / 0', 'state/alarm' => 'Alarm aktiv: 1 / 0',
            'state/online' => 'Fahrzeug online: 1 / 0', 'state/available' => 'Fahrzeugabfrage verfügbar: 1 / 0', 'state/stale' => 'Fahrzeugdaten veraltet / Zeitpunkt unbekannt: 1 / 0', 'state/doors_open' => 'Türen geöffnet: 1 / 0',
                'state/battery_voltage' => 'Batteriespannung in V', 'state/cabin_temperature' => 'Innenraumtemperatur in °C',
                'state/updated_at' => 'Zeitpunkt der Fahrzeugdaten', 'events/latest/text' => 'Letzter Meldungstext',
                'events/latest' => 'Letzte Meldung als JSON', 'events' => 'Letzte Meldungen als JSON-Liste',
            ] as $suffix => $description): ?><tr><td><code><?= pcH($baseTopic . '/' . $suffix) ?></code></td><td><?= pcH($description) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p>Für optionale Werte gibt es ein Gültigkeitsthema, zum Beispiel <code><?= pcH($baseTopic) ?>/state/armed_valid</code>. Nur bei <code>1</code> ist ein bekannter, verfügbarer Wert vorhanden. <code>state/stale</code> meldet zusätzlich Daten älter als fünf Minuten oder einen fehlenden Fahrzeugzeitstempel. Der Dienst meldet <code>online</code> oder <code>offline</code> über <code><?= pcH($prefix) ?>/bridge/availability</code>. In Loxone immer auch diese Bridge-Verfügbarkeit abonnieren und als Bedingung auswerten; gespeicherte Telemetrie bleibt bei einem Ausfall zunächst am Broker bestehen.</p>
        <p class="pc-muted">Status und Meldungen sind retained: neue Abonnenten erhalten den zuletzt veröffentlichten Wert. Den Wert zusammen mit Verfügbarkeit, Gültigkeit und Datenalter auswerten.</p>
    </section>
    <section class="lb-card"><h2 class="lb-section-title">Aus Loxone steuern</h2>
        <p>Im Tab „Meine Fahrzeuge“ die Steuerung für das gewünschte Fahrzeug freigeben. Ein MQTT-Sender kann dann den Wert <code>1</code> an das passende Befehlsthema senden. Befehle ohne Retain senden; <code>0</code> löst keine Aktion aus.</p>
        <div class="pc-scroll"><table class="lb-table"><thead><tr><th>Befehlsthema</th><th>Funktion</th></tr></thead><tbody>
        <?php foreach (PandoraClient::commands() as $key => $metadata): ?><tr><td><code><?= pcH($baseTopic . '/set/' . $key) ?></code></td><td><?= pcH($metadata['label']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p>Für virtuelle Ausgänge in Loxone den im MQTT Gateway vorhandenen Publish-Zugang verwenden. Die Dokumentation des installierten Gateways beschreibt die passende HTTP-Adresse und Authentifizierung.</p>
        <p>Die Befehlsergebnisse erscheinen als JSON auf <code><?= pcH($baseTopic) ?>/command/result</code> mit <code>request_id</code>, <code>command</code>, <code>status</code>, <code>message</code> und <code>timestamp</code>. Zusätzlich bleiben <code><?= pcH($baseTopic) ?>/state/last_command_status</code> und <code><?= pcH($baseTopic) ?>/state/last_command_message</code> als retained Einzelwerte verfügbar.</p>
        <p>Erst eine bestätigte Fahrzeugrückmeldung belegt die Ausführung. Vor erneutem Senden bei unklarer Rückmeldung den Fahrzeugstatus prüfen.</p>
    </section>

    <?php elseif ($tab === 'diagnostics'): ?>
    <section class="lb-card"><h2 class="lb-section-title">Hintergrunddienst</h2>
        <?php $heartbeat = $runtime['heartbeat'] ?? null; ?>
        <div class="pc-scroll"><table class="lb-table"><tbody>
            <tr><th>Plugin</th><td>Loxdora</td></tr>
            <tr><th>PHP</th><td><?= pcH(PHP_VERSION) ?></td></tr>
            <tr><th>Hintergrundverarbeitung</th><td><?= !empty($settings['enabled']) ? 'Aktiviert' : 'Pausiert' ?></td></tr>
            <tr><th>Letztes Lebenszeichen</th><td><?= pcH(pcDate($heartbeat)) ?></td></tr>
            <tr><th>MQTT-Verbindung</th><td><?= pcH(pcBinary($runtime['mqtt_connected'] ?? null, 'Verbunden', 'Getrennt')) ?></td></tr>
            <tr><th>Abfrageintervall</th><td><?= pcH($settings['poll_interval'] ?? 60) ?> Sekunden</td></tr>
            <tr><th>Fahrzeuge</th><td><?= pcH(count($vehicles)) ?></td></tr>
            <?php if (!empty($runtime['error'])): ?><tr><th>Letzter Dienstfehler</th><td><?= pcH($runtime['error']) ?></td></tr><?php endif; ?>
        </tbody></table></div>
        <p>Der LoxBerry-Daemon startet den Plugin-Dienst beim Systemstart. Fehlt ein aktuelles Lebenszeichen, die Plugin-Protokolle in LoxBerry prüfen.</p>
        <a class="lb-btn" href="?tab=diagnostics">Diagnose neu laden</a>
    </section>
    <section class="lb-card"><h2 class="lb-section-title">Verbindungen prüfen</h2>
        <ul><li>Bei fehlenden Fahrzeugdaten unter „Meine Fahrzeuge“ zuerst den Status abrufen. Bei mehreren Fahrzeugen im Konto die Pandora-Fahrzeug-ID auswählen und speichern.</li>
            <li>Bei einem Anmeldefehler Benutzername und Passwort prüfen. Ein leeres Passwortfeld beim Bearbeiten behält das bisherige Passwort bei.</li>
            <li>Bei fehlenden Loxone-Werten MQTT-Broker, Gateway-Abonnement und die Miniserver-Verbindung in LoxBerry prüfen.</li>
            <li>Bei gesperrten Befehlen die Steuerungsfreigabe, Pandora-Berechtigungen, Ausstattung und Aktualität des Fahrzeugstatus prüfen.</li></ul>
        <p class="pc-muted">Diese Diagnose zeigt keine Passwörter, Pandora-Sitzungen oder MQTT-Zugangsdaten an.</p>
    </section>
    <?php endif; ?>
</div>
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-confirm'))) { event.preventDefault(); }
    });
});
</script>
<?php LBWeb::lbfooter(); ?>
