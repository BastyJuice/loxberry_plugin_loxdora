<?php
declare(strict_types=1);

use PandoraLox\VehicleProfile;
if (!isset($store, $vehicles, $settings, $runtime) || !function_exists('pcH')) { http_response_code(404); exit; }

/** Inline vectors use only fixed local artwork; no external assets or services. */
function ldIcon(string $name): string {
    $paths = [
        'shield' => '<path d="M12 3 4.5 6v5.5c0 4.6 3 7.9 7.5 10 4.5-2.1 7.5-5.4 7.5-10V6L12 3Z"/><path d="m8.5 12 2.3 2.3 4.8-5"/>',
        'car' => '<path d="m5 9 2-5h10l2 5M4 10h16v8H4zM6 18v2m12-2v2M7 13h1m8 0h1M5 9h14"/>',
        'signal' => '<path d="M4 20v-3m5 3v-7m5 7V9m5 11V4"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'refresh' => '<path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 7a7 7 0 0 1 11.5-1L20 9M4 15l2.4 3A7 7 0 0 0 18 17"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'battery' => '<rect x="3" y="7" width="17" height="10" rx="2"/><path d="M22 10v4M7 12h4m-2-2v4m6-2h2"/>',
        'temperature' => '<path d="M10 14.5V5a2 2 0 0 1 4 0v9.5a4 4 0 1 1-4 0ZM12 10v7m5-10h2m-2 4h2"/>',
        'fuel' => '<path d="M4 21V4h10v17M3 21h12M4 10h10m0 3h2a2 2 0 0 1 2 2v3a2 2 0 0 0 4 0V9l-4-4m2 2-2 3 4 2"/>',
        'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM9 21h6"/>',
        'door' => '<path d="M6 21V3h12v18M4 21h16M14 12h1"/>',
        'warning' => '<path d="m12 3 10 18H2L12 3ZM12 9v5m0 3v.1"/>',
        'pause' => '<path d="M8 5v14M16 5v14"/>',
        'link' => '<path d="m10 14 4-4m-6 5-1 1a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0m2 3 1-1a4 4 0 1 1 6 6l-4 4a4 4 0 0 1-6 0" transform="translate(1 1)"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
    ];
    return '<svg class="ld-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['car']) . '</svg>';
}

function ldCar(string $icon): string {
    return VehicleProfile::artwork($icon);
}

function ldRelative($value): string {
    $timestamp = is_numeric($value) ? (int) $value : (is_string($value) ? strtotime($value) : false);
    if (!$timestamp) { return 'Zeitpunkt unbekannt'; }
    $age = time() - $timestamp;
    if ($age < -60) { return pcDate($value); }
    if ($age < 60) { return 'gerade eben'; }
    if ($age < 3600) { return 'vor ' . (int) floor($age / 60) . ' Min.'; }
    if ($age < 86400) { return 'vor ' . (int) floor($age / 3600) . ' Std.'; }
    return pcDate($value);
}

$cards = [];
$counts = ['vehicles' => count($vehicles), 'protected' => 0, 'online' => 0, 'attention' => 0];
$activity = [];
$lastRead = null;
foreach ($vehicles as $entry) {
    $snapshot = $store->state((string) $entry['id']);
    $car = is_array($snapshot['vehicle'] ?? null) ? $snapshot['vehicle'] : [];
    $displayName = VehicleProfile::displayName($entry, $car);
    $vehicleIcon = VehicleProfile::icon($entry, $car);
    $telemetry = is_array($car['status'] ?? null) ? $car['status'] : [];
    $current = !empty($settings['enabled']) && !empty($entry['enabled']) && empty($snapshot['error']) && $car && !pcStale($car);
    $condition = 'unknown'; $label = 'Status noch unbekannt'; $icon = 'clock'; $tone = 'muted';
    if (empty($settings['enabled']) || empty($entry['enabled'])) {
        $condition = 'paused'; $label = 'Abfrage pausiert'; $icon = 'pause';
    } elseif (!empty($snapshot['error'])) {
        $condition = 'error'; $label = 'Verbindung prüfen'; $icon = 'warning'; $tone = 'warning';
    } elseif (!$car) {
        $label = 'Noch kein Fahrzeugstatus';
    } elseif (pcStale($car)) {
        $condition = 'stale'; $label = 'Fahrzeugdaten veraltet'; $icon = 'clock'; $tone = 'warning';
    } elseif (($telemetry['alarm'] ?? null) === true) {
        $condition = 'alarm'; $label = 'Alarm aktiv'; $icon = 'bell'; $tone = 'danger';
    } elseif (($car['online'] ?? null) === false) {
        $condition = 'offline'; $label = 'Fahrzeug offline'; $icon = 'signal'; $tone = 'warning';
    } elseif (($telemetry['armed'] ?? null) === true) {
        $condition = 'protected'; $label = 'Schutz aktiv'; $icon = 'shield'; $tone = 'success';
    } elseif (($telemetry['armed'] ?? null) === false) {
        $condition = 'disarmed'; $label = 'Schutz deaktiviert'; $icon = 'shield';
    }
    if ($current && ($car['online'] ?? null) === true) { ++$counts['online']; }
    if ($current && ($telemetry['armed'] ?? null) === true && ($telemetry['alarm'] ?? null) !== true) { ++$counts['protected']; }
    if (!$current || ($telemetry['alarm'] ?? null) === true || ($car['online'] ?? null) !== true || !is_bool($telemetry['armed'] ?? null)) { ++$counts['attention']; }
    if (isset($snapshot['updated_at']) && is_numeric($snapshot['updated_at'])) { $lastRead = max($lastRead ?? 0, (int) $snapshot['updated_at']); }
    $recent = is_array($snapshot['events'] ?? null) ? array_values(array_filter($snapshot['events'], 'is_array')) : [];
    foreach (array_slice($recent, 0, 5) as $event) {
        $event['_vehicle_name'] = $displayName; $event['_vehicle_id'] = $entry['id'];
        $activity[] = $event;
    }
    $cards[] = compact('entry', 'displayName', 'vehicleIcon', 'snapshot', 'car', 'telemetry', 'current', 'condition', 'label', 'icon', 'tone', 'recent');
}
usort($activity, static function (array $a, array $b): int {
    return (strtotime((string) ($b['occurredAt'] ?? '')) ?: 0) <=> (strtotime((string) ($a['occurredAt'] ?? '')) ?: 0);
});
$heartbeatFresh = isset($runtime['heartbeat']) && time() - (int) $runtime['heartbeat'] >= -60 && time() - (int) $runtime['heartbeat'] <= 90;
$mqttReady = $heartbeatFresh && ($runtime['mqtt_connected'] ?? false) === true;
$workerReady = $heartbeatFresh && !empty($settings['enabled']);
?>
<link rel="stylesheet" href="dashboard.css">
<div class="loxdora-dashboard">
    <header class="ld-heading">
        <div><p class="ld-eyebrow">LOXDORA <span>/</span> DEINE FAHRZEUGE</p><h2>Alles im Blick.</h2><p class="ld-subtitle"><?= count($vehicles) === 1 ? 'Dein Fahrzeug. Sein Status. Ein guter Überblick.' : 'Deine Fahrzeuge. Ihr Status. Ein guter Überblick.' ?></p></div>
        <div class="ld-heading-actions"><a class="ld-button ld-button-secondary" href="?tab=dashboard"><?= ldIcon('refresh') ?>Ansicht neu laden</a><a class="ld-button ld-button-primary" href="?tab=vehicles#vehicle-form"><?= ldIcon('plus') ?>Fahrzeug hinzufügen</a></div>
    </header>

    <section class="ld-welcome" aria-label="Verbindungsübersicht">
        <div class="ld-welcome-emblem"><?= ldIcon('shield') ?></div>
        <div class="ld-welcome-copy"><h3><?= !$vehicles ? 'Dein Überblick beginnt hier.' : ($counts['attention'] > 0 ? 'Ein Blick auf deine Fahrzeuge.' : ($counts['protected'] === count($vehicles) ? 'Ein gutes Gefühl. Alles geschützt.' : 'Verbunden mit deinem Alltag.')) ?></h3><p><?= !$vehicles ? 'Füge dein erstes Pandora-Fahrzeug hinzu und bringe es mit Loxone zusammen.' : ($counts['attention'] > 0 ? 'Bei ' . $counts['attention'] . ( $counts['attention'] === 1 ? ' Fahrzeug lohnt sich ein genauerer Blick.' : ' Fahrzeugen lohnt sich ein genauerer Blick.') : 'Der letzte bekannte Fahrzeugstatus, übersichtlich an einem Ort.') ?></p></div>
        <a class="ld-system-pill <?= $mqttReady ? 'is-ready' : '' ?>" href="?tab=diagnostics"><span class="ld-dot"></span>MQTT <?= $mqttReady ? 'verbunden' : 'nicht bestätigt' ?><?= ldIcon('arrow') ?></a>
    </section>

    <section class="ld-summary" aria-label="Fahrzeuge im Überblick">
        <?php foreach ([['vehicles', 'car', 'Fahrzeuge', 'In Loxdora angelegt', 'neutral'], ['protected', 'shield', 'Schutz aktiv', 'Mit aktuellem Fahrzeugstatus', 'success'], ['online', 'signal', 'Online', 'Mit aktuellem Fahrzeugstatus', 'blue'], ['attention', 'bell', 'Hinweise', 'Status oder Verbindung prüfen', 'warning']] as $metric): ?>
        <div class="ld-summary-card"><span class="ld-summary-icon ld-tone-<?= pcH($metric[4]) ?>"><?= ldIcon($metric[1]) ?></span><div><span class="ld-summary-label"><?= pcH($metric[2]) ?></span><strong data-summary="<?= pcH($metric[0]) ?>"><?= pcH($counts[$metric[0]]) ?></strong><span class="ld-summary-note"><?= pcH($metric[3]) ?></span></div></div>
        <?php endforeach; ?>
    </section>

    <div class="ld-section-heading"><div><p class="ld-eyebrow">DEINE GARAGE</p><h3><?= count($vehicles) === 1 ? 'Mein Fahrzeug' : 'Meine Fahrzeuge' ?><span class="ld-count"><?= pcH(count($vehicles)) ?></span></h3></div><span class="ld-last-read"><?= ldIcon('clock') ?>Letzter Abruf: <?= pcH($lastRead === null ? 'noch keiner' : ldRelative($lastRead)) ?></span></div>

    <?php if (!$cards): ?>
    <section class="ld-empty"><div class="ld-empty-art"><?= ldCar('car') ?></div><h3>Platz für dein erstes Fahrzeug.</h3><p>Verbinde dein Pandora-Konto und sieh Schutzstatus,<br>Fahrzeugwerte und Meldungen auf einen Blick.</p><a class="ld-button ld-button-primary" href="?tab=vehicles#vehicle-form"><?= ldIcon('plus') ?>Erstes Fahrzeug hinzufügen</a></section>
    <?php else: ?>
    <div class="ld-vehicle-grid <?= count($cards) === 1 ? 'ld-single-vehicle' : '' ?>">
        <?php foreach ($cards as $card): extract($card, EXTR_OVERWRITE); $entryId = (string) $entry['id']; ?>
        <article class="ld-vehicle-card" data-vehicle-id="<?= pcH($entryId) ?>" data-condition="<?= pcH($condition) ?>" data-vehicle-icon="<?= pcH($vehicleIcon) ?>">
            <header class="ld-vehicle-heading"><span class="ld-vehicle-mark"><?= ldIcon('car') ?></span><div class="ld-vehicle-name"><h4><?= pcH($displayName) ?></h4><p><?= pcH($car['model'] ?? 'Pandora-Fahrzeug') ?></p></div><span class="ld-online-dot <?= $current && ($car['online'] ?? null) === true ? 'is-online' : '' ?>" title="<?= pcH($current ? pcBinary($car['online'] ?? null, 'Fahrzeug online', 'Fahrzeug offline') : 'Kein bestätigter aktueller Verbindungsstatus') ?>"><span class="ld-dot"></span><span><?= $current ? pcH(pcBinary($car['online'] ?? null, 'Online', 'Offline')) : 'Letzter Stand' ?></span></span></header>
            <div class="ld-vehicle-body">
                <div class="ld-vehicle-stage"><span class="ld-status ld-tone-<?= pcH($tone) ?>"><?= ldIcon($icon) ?><?= pcH($label) ?></span><?= ldCar($vehicleIcon) ?><span class="ld-stage-caption"><?= ldIcon('door') ?><?= $current ? pcH(pcBinary($telemetry['doorsOpen'] ?? null, 'Türen geöffnet', 'Türen geschlossen')) : 'Letzter bekannter Stand' ?></span></div>
                <div class="ld-vehicle-information">
                    <div class="ld-vehicle-metrics">
                        <?php foreach ([['battery', 'Batterie', pcNumber($telemetry['batteryVoltage'] ?? null, '', 2), 'V'], ['temperature', 'Innenraum', pcNumber($telemetry['cabinTemperature'] ?? null, '', 1), '°C'], ['fuel', 'Kraftstoff', pcNumber($telemetry['fuelPercent'] ?? null, '', 0), '%']] as $reading): ?>
                        <div class="ld-reading"><?= ldIcon($reading[0]) ?><span><?= pcH($reading[1]) ?></span><strong><?= pcH($reading[2]) ?><?php if ($reading[2] !== '—'): ?><small><?= pcH($reading[3]) ?></small><?php endif; ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="ld-vehicle-update"><span class="ld-dot"></span><span>Fahrzeugdaten <?= pcH(ldRelative($car['updatedAt'] ?? null)) ?></span><?php if (!$current && $car): ?><span class="ld-cache-label">Gespeichert</span><?php endif; ?></div>
                    <?php if (!empty($snapshot['error'])): ?><p class="ld-card-notice" role="status"><?= ldIcon('warning') ?><span><?= pcH($snapshot['error']) ?></span></p><?php elseif (!$current): ?><p class="ld-card-notice"><?= ldIcon('clock') ?><span><?= $condition === 'paused' ? 'Die automatische Abfrage ist pausiert.' : ($car ? 'Die Anzeige zeigt ältere Fahrzeugdaten. Bitte den Status abrufen.' : 'Rufe den Status ab, um die ersten Fahrzeugdaten zu laden.') ?></span></p><?php endif; ?>
                    <div class="ld-latest-event"><span class="ld-event-icon"><?= ldIcon('bell') ?></span><div><span>Letzte Meldung</span><strong><?= pcH($recent[0]['title'] ?? $recent[0]['label'] ?? 'Noch keine Meldung vorhanden') ?></strong><?php if ($recent): ?><time><?= pcH(ldRelative($recent[0]['occurredAt'] ?? null)) ?></time><?php endif; ?></div></div>
                </div>
            </div>
            <footer class="ld-vehicle-footer"><a class="ld-detail-link" href="?tab=vehicles&amp;vehicle=<?= pcH(rawurlencode($entryId)) ?>#vehicle-status" aria-label="Details und Steuerung für <?= pcH($displayName) ?>">Details &amp; Steuerung<?= ldIcon('arrow') ?></a><form method="post" action="index.php"><?php pcCsrf(); pcHidden('vehicle', $entryId); pcHidden('return_tab', 'dashboard'); ?><button class="ld-refresh-button" name="action" value="refresh" aria-label="Status für <?= pcH($displayName) ?> abrufen" <?= empty($entry['enabled']) || empty($settings['enabled']) ? 'disabled' : '' ?>><?= ldIcon('refresh') ?>Status abrufen</button></form></footer>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="ld-bottom-grid">
        <section class="ld-activity-panel"><div class="ld-panel-heading"><div><p class="ld-eyebrow">WAS GIBT ES NEUES?</p><h3>Letzte Aktivität</h3></div><span class="ld-panel-glyph"><?= ldIcon('clock') ?></span></div>
            <?php if (!$activity): ?><div class="ld-no-activity"><?= ldIcon('bell') ?><p>Hier erscheinen die letzten Fahrzeugmeldungen.</p></div><?php else: ?><ol class="ld-activity-list">
            <?php foreach (array_slice($activity, 0, 5) as $event): ?><li><span class="ld-timeline-icon <?= ($event['severity'] ?? '') === 'alarm' ? 'ld-tone-danger' : 'ld-tone-success' ?>"><?= ldIcon(($event['severity'] ?? '') === 'alarm' ? 'warning' : 'bell') ?></span><div><a href="?tab=vehicles&amp;vehicle=<?= pcH(rawurlencode((string) $event['_vehicle_id'])) ?>#vehicle-status"><?= pcH($event['title'] ?? $event['label'] ?? 'Pandora-Meldung') ?></a><p><?= pcH($event['_vehicle_name']) ?></p></div><time><?= pcH(ldRelative($event['occurredAt'] ?? null)) ?></time></li><?php endforeach; ?>
            </ol><?php endif; ?>
        </section>
        <section class="ld-connections-panel"><div class="ld-panel-heading"><div><p class="ld-eyebrow">ALLES VERBUNDEN</p><h3>Deine Verbindungen</h3></div><span class="ld-panel-glyph"><?= ldIcon('link') ?></span></div>
            <div class="ld-connection"><span class="ld-connection-icon"><?= ldIcon('signal') ?></span><div><strong>MQTT-Broker</strong><span>Verbindung über LoxBerry</span></div><span class="ld-connection-state <?= $mqttReady ? 'is-ready' : '' ?>"><span class="ld-dot"></span><?= $mqttReady ? 'Verbunden' : 'Prüfen' ?></span></div>
            <div class="ld-connection"><span class="ld-connection-icon"><?= ldIcon('refresh') ?></span><div><strong>Statusabfrage</strong><span>Intervall: <?= pcH($settings['poll_interval'] ?? 60) ?> Sekunden</span></div><span class="ld-connection-state <?= $workerReady ? 'is-ready' : '' ?>"><span class="ld-dot"></span><?= empty($settings['enabled']) ? 'Pausiert' : ($workerReady ? 'Aktiv' : 'Prüfen') ?></span></div>
            <p class="ld-connections-note">Die Verbindung zum Miniserver richtest du im LoxBerry MQTT-Gateway ein.</p><a class="ld-detail-link" href="?tab=mqtt">MQTT &amp; Loxone einrichten<?= ldIcon('arrow') ?></a>
        </section>
    </div>
    <footer class="ld-dashboard-footer"><span>LOXDORA <span class="ld-footer-divider">/</span> Pandora trifft Loxone.</span><span>„—“ bedeutet: kein bekannter Wert.</span></footer>
</div>
