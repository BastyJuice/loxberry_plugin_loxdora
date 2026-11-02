#!/usr/bin/env python3
"""Native LB4 UI checks against isolated fake configuration; no upstream requests."""
from pathlib import Path
import json
import os
import re
import shutil
import subprocess
import tempfile

base = Path(__file__).resolve().parents[1]
php = shutil.which('php')
assert php, 'System PHP required.'
passed = 0

def check(condition, description):
    global passed
    assert condition, description
    passed += 1

with tempfile.TemporaryDirectory(prefix='pandora-ui-') as temporary:
    fixture = Path(temporary)
    subprocess.run([php, str(base / 'tests/ui_fixture.php'), str(fixture)], check=True, capture_output=True)
    environment = dict(os.environ, PANDORA_TEST_STUB='1', PANDORA_TEST_SESSION='1',
                       PANDORA_TEST_CONFIG=str(fixture / 'config'), PANDORA_TEST_DATA=str(fixture / 'data'))
    runner = '$_GET=json_decode($argv[2],true); $_POST=json_decode($argv[3],true); $_SERVER["REQUEST_METHOD"]=$_POST?"POST":"GET"; require $argv[1];'

    def render(query, posted=None):
        process = subprocess.run([php, '-d', 'include_path=' + str(base / 'tests/stubs'), '-r', runner,
                                  str(base / 'webfrontend/htmlauth/index.php'), json.dumps(query), json.dumps(posted or {})],
                                 env=environment, capture_output=True, text=True, check=True)
        check(not process.stderr and 'Fatal error' not in process.stdout and 'Warning:' not in process.stdout,
              'UI has no PHP errors or warnings')
        check('preview-password-only' not in process.stdout and 'preview-second-password-only' not in process.stdout,
              'Passwords do not appear in markup')
        return process.stdout

    for tab in ('vehicles', 'mqtt', 'diagnostics'):
        html = render({'tab': tab})
        check('Loxdora' in html and 'lb-card' in html and 'Frei erfundene' not in html,
              'Native wrapper and page content render')
        check('Meine Fahrzeuge' in html and 'MQTT & Loxone' in html and 'Diagnose' in html,
              'Native tab navigation contains all pages')
    selected = render({'tab': 'vehicles', 'vehicle': 'fahrzeug_1'})
    name_input = re.search(r'<input\b[^>]*\bid="vehicle-name"[^>]*>', selected)
    check(name_input is not None and 'required' not in name_input.group(0),
          'Custom display name is optional and can use the Pandora name')
    check('Eigener Anzeigename (optional)' in selected and 'Aktueller Name aus Pandora:' in selected,
          'Name field explains its automatic Pandora fallback')
    choices = re.findall(r'<input[^>]*type="radio"[^>]*name="vehicle_icon"[^>]*value="([^"]+)"', selected)
    check(choices == ['auto', 'motorcycle', 'car', 'sports_car', 'van', 'motorhome'],
          'Icon selector provides automatic plus all five requested vehicle symbols')
    check(all(label in selected for label in ('Automatisch', 'Motorrad', 'Pkw', 'Sportwagen', 'Kastenwagen', 'Wohnmobil')),
          'Each vehicle symbol has a readable text label')
    check('Schutz aktiviert' in selected and 'Alarm gemeldet' in selected and '12,71 V' in selected,
          'Vehicle details show normalized status and recent events')
    check('52,520008' in selected and '13,404954' in selected,
          'Coordinates preserve useful precision')
    check('Frei erfundene Beispielmeldungen' in selected,
          'History availability note is shown')
    check(len(re.findall(r'<input[^>]*type="password"[^>]*value=""', selected)) == 1,
          'Edit form never populates its password input')
    check('value="100003"' in selected and 'Transporter' in selected,
          'Discovery presents multiple vehicles for explicit selection')
    offline = render({'tab': 'vehicles', 'vehicle': 'fahrzeug_2'})
    check('älter als fünf Minuten' in offline and 'Die Steuerung ist für dieses Fahrzeug ausgeschaltet.' in offline,
          'Stale data and disabled control are explicit')
    check('Motortemperatur</dt><dd>—' in offline,
          'Missing telemetry is displayed as unknown')
    mqtt = render({'tab': 'mqtt'})
    check('state/armed_valid' in mqtt and 'state/stale' in mqtt and 'bridge/availability' in mqtt and 'command/result' in mqtt,
          'MQTT instructions include validity, freshness, availability and results')
    check('ohne Retain' in mqtt and '/set/unlock' in mqtt,
          'Command documentation identifies non-retained topics')

    registry = fixture / 'config/vehicles.json'
    before = registry.read_bytes()
    rejected = render({}, {'action': 'delete_vehicle', 'vehicle': 'fahrzeug_1', 'csrf': 'wrong'})
    check('Sitzung ist abgelaufen' in rejected and registry.read_bytes() == before,
          'Bad CSRF cannot delete a vehicle')
    check(not list((fixture / 'data/queue').glob('*.json')), 'No command is queued by reading pages')
    rejected = render({}, {'action': 'command', 'vehicle': 'fahrzeug_2', 'command': 'unlock', 'csrf': 'preview-csrf-test-only'})
    check('nicht freigegeben' in rejected and not list((fixture / 'data/queue').glob('*.json')),
          'Disabled vehicle control cannot enqueue a command')
    render({}, {'action': 'command', 'vehicle': 'fahrzeug_1', 'command': 'unlock', 'csrf': 'preview-csrf-test-only'})
    queue = list((fixture / 'data/queue').glob('*.json'))
    check(len(queue) == 1 and json.loads(queue[0].read_text())['command'] == 'unlock',
          'Explicit valid POST queues exactly one command without sending upstream')
    get_action = render({'tab': 'vehicles', 'action': 'delete_vehicle', 'vehicle': 'fahrzeug_1'})
    check(registry.read_bytes() == before, 'GET parameters cannot mutate configuration')

    malicious = '<img src=x onerror=alert(1)>'
    render({}, {'action': 'save_vehicle', 'vehicle': 'fahrzeug_1', 'csrf': 'preview-csrf-test-only',
                'name': malicious, 'login': 'beispiel-camper', 'password': '', 'device_id': '100001', 'enabled': '1', 'allow_control': '1'})
    escaped = render({'tab': 'vehicles', 'vehicle': 'fahrzeug_1'})
    check(malicious not in escaped and '&lt;img' in escaped, 'Vehicle labels are escaped in text, values and confirmation attributes')
    inspect = subprocess.run([php, '-r', 'require $argv[1]; $s=new PandoraLox\\Store($argv[2],$argv[3]); echo $s->credentials("fahrzeug_1")["password"];',
                              str(base / 'bin/bootstrap.php'), str(fixture / 'config'), str(fixture / 'data')], capture_output=True, text=True, check=True)
    check(inspect.stdout == 'preview-password-only', 'Blank edit password preserves the encrypted credential')
    check(b'preview-password-only' not in registry.read_bytes(), 'Registry contains no plaintext password')
    render({}, {'action': 'save_vehicle', 'vehicle': 'fahrzeug_1', 'csrf': 'preview-csrf-test-only',
                'name': '', 'vehicle_icon': 'motorcycle', 'login': 'beispiel-camper', 'password': '',
                'device_id': '100001', 'enabled': '1', 'allow_control': '1'})
    automatic = render({'tab': 'vehicles', 'vehicle': 'fahrzeug_1'})
    registered = json.loads(registry.read_text())['vehicles']['fahrzeug_1']
    check(registered['name'] == '' and registered['vehicle_icon'] == 'motorcycle',
          'Blank custom name and explicit symbol are saved together')
    check('<h2 class="lb-section-title">Camper · Status</h2>' in automatic and
          'data-confirm="Fahrzeug Camper ' in automatic and 'Camper entfernen</h2>' in automatic,
          'Details and removal use the upstream name when the custom name is empty')
    check(re.search(r'<input[^>]*id="vehicle-name"[^>]*value=""', automatic) is not None,
          'Automatic display name does not get copied into the custom-name input')
    check(re.search(r'name="vehicle_icon" value="motorcycle" checked', automatic) is not None,
          'Saved symbol remains selected when editing')
    render({}, {'action': 'save_vehicle', 'vehicle': 'fahrzeug_1', 'csrf': 'preview-csrf-test-only',
                'name': '', 'login': 'beispiel-camper', 'password': '', 'device_id': '100001', 'enabled': '1', 'allow_control': '1'})
    check(json.loads(registry.read_text())['vehicles']['fahrzeug_1']['vehicle_icon'] == 'motorcycle',
          'Older forms without a symbol field preserve the saved selection')
    render({}, {'action': 'save_vehicle', 'vehicle': 'fahrzeug_1', 'csrf': 'preview-csrf-test-only',
                'name': '', 'vehicle_icon': 'auto', 'login': 'beispiel-camper', 'password': '',
                'device_id': '100001', 'enabled': '1', 'allow_control': '1'})
    check(json.loads(registry.read_text())['vehicles']['fahrzeug_1']['vehicle_icon'] == 'auto',
          'An explicit automatic selection is persisted')
    render({}, {'action': 'save_vehicle', 'vehicle': '', 'csrf': 'preview-csrf-test-only',
                'name': '', 'vehicle_icon': 'van', 'login': 'synthetic-third-account', 'password': 'synthetic-third-password',
                'device_id': '', 'enabled': '1'})
    unpolled = render({'tab': 'vehicles', 'vehicle': 'fahrzeug_3'})
    check('Fahrzeug 3 · Status</h2>' in unpolled and 'synthetic-third-password' not in unpolled,
          'An unnamed new account shows a readable vehicle fallback before its first poll')


print(f'PASS: {passed} native UI/CSRF/secret-render checks; no Pandora or MQTT network access.')
