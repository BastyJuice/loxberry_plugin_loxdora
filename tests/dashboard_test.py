#!/usr/bin/env python3
"""Dashboard behavior checks with synthetic local fixtures and network calls disabled."""
from datetime import datetime, timedelta, timezone
from html.parser import HTMLParser
from pathlib import Path
import json
import os
import shutil
import subprocess
import tempfile

base = Path(__file__).resolve().parents[1]
php = shutil.which('php')
assert php, 'System PHP required.'
passed = 0


def check(condition, label):
    global passed
    assert condition, label
    passed += 1


class Node:
    def __init__(self, tag='', attrs=None, parent=None):
        self.tag, self.attrs, self.parent, self.children = tag, dict(attrs or []), parent, []

    def descendants(self):
        yield self
        for child in self.children:
            if isinstance(child, Node):
                yield from child.descendants()

    def text(self):
        return ''.join(child.text() if isinstance(child, Node) else child for child in self.children)


class Document(HTMLParser):
    voids = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'}

    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.root = self.current = Node()
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        child = Node(tag, attrs, self.current)
        self.current.children.append(child)
        if tag not in self.voids:
            self.current = child

    def handle_startendtag(self, tag, attrs):
        self.current.children.append(Node(tag, attrs, self.current))

    def handle_endtag(self, tag):
        node = self.current
        while node.parent is not None:
            if node.tag == tag:
                self.current = node.parent
                break
            node = node.parent

    def handle_data(self, data):
        self.current.children.append(data)

    def with_attr(self, name, value=None):
        return [node for node in self.root.descendants()
                if name in node.attrs and (value is None or node.attrs[name] == value)]

    def cards(self):
        return [node for node in self.root.descendants()
                if 'ld-vehicle-card' in node.attrs.get('class', '').split()]

    def summary(self, key):
        nodes = self.with_attr('data-summary', key)
        assert len(nodes) == 1, 'Dashboard has one summary for ' + key
        return int(nodes[0].text().strip())


def iso(offset=0):
    return (datetime.now(timezone.utc) + timedelta(seconds=offset)).isoformat()


def entry(name, *, enabled=True, cache=True, armed=True, alarm=False, online=True, offset=0, error='',
          vehicle_icon='auto', upstream_name=None, vehicle_metadata=None):
    state = None
    if cache:
        state = {'updated_at': int(datetime.now(timezone.utc).timestamp()), 'error': error,
                 'vehicle': {'id': '100001', 'name': name if upstream_name is None else upstream_name, 'model': 'Pandora-Testsystem', 'online': online,
                             'updatedAt': iso(offset), 'position': None,
                             'status': {'armed': armed, 'alarm': alarm, 'batteryVoltage': 12.7,
                                        'cabinTemperature': 19.5, 'fuelPercent': 60, 'doorsOpen': False}, 'commands': []},
                 'events': []}
    if state is not None and vehicle_metadata:
        state['vehicle'].update(vehicle_metadata)
    return {'name': name, 'enabled': enabled, 'vehicle_icon': vehicle_icon, 'state': state}


with tempfile.TemporaryDirectory(prefix='loxdora-dashboard-') as temporary:
    root = Path(temporary)
    create_script = r'''
require $argv[1];
$store = new PandoraLox\Store($argv[2] . '/config', $argv[2] . '/data');
$entries = json_decode(file_get_contents('php://stdin'), true);
foreach ($entries as $i => $entry) {
    $id = $store->saveVehicle(['name' => $entry['name'], 'login' => 'private-dashboard-login-' . $i,
        'password' => 'private-dashboard-password-' . $i, 'device_id' => (string) (100001 + $i),
        'enabled' => $entry['enabled'], 'vehicle_icon' => $entry['vehicle_icon'], 'allow_control' => false]);
    if (is_array($entry['state'])) {
        if (is_array($entry['state']['vehicle'] ?? null)) { $entry['state']['vehicle']['id'] = (string) (100001 + $i); }
        $store->writeState($id, $entry['state']);
    }
}
$store->writeRuntime(['heartbeat' => time(), 'mqtt_connected' => true, 'error' => '']);
'''
    render_script = r'''
$_GET = json_decode($argv[2], true);
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
require $argv[1];
'''

    def fixture(name, entries):
        target = root / name
        subprocess.run([php, '-r', create_script, str(base / 'bin/bootstrap.php'), str(target)],
                       input=json.dumps(entries), capture_output=True, text=True, check=True)
        return target

    def state_files(target):
        # PHP session metadata is allowed; account, queue and vehicle caches are read-only on GET.
        return {str(path.relative_to(target)): path.read_bytes() for path in target.rglob('*.json')}

    def render(target, query=None):
        before = state_files(target)
        env = dict(os.environ, PANDORA_TEST_STUB='1', PANDORA_TEST_SESSION='1',
                   PANDORA_TEST_CONFIG=str(target / 'config'), PANDORA_TEST_DATA=str(target / 'data'))
        process = subprocess.run([php, '-d', 'include_path=' + str(base / 'tests/stubs'),
                                  '-d', 'disable_functions=curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
                                  '-r', render_script, str(base / 'webfrontend/htmlauth/index.php'), json.dumps(query or {})],
                                 env=env, capture_output=True, text=True, timeout=5)
        check(process.returncode == 0, 'Dashboard PHP render failed: ' + process.stderr.strip())
        check(not process.stderr and 'Fatal error' not in process.stdout and 'Warning:' not in process.stdout,
              'Dashboard GET renders cleanly with network functions disabled')
        check(state_files(target) == before, 'Dashboard GET does not refresh, enqueue or mutate vehicle/account state')
        check('private-dashboard-password-' not in process.stdout and 'private-dashboard-login-' not in process.stdout,
              'Dashboard does not expose account login or password')
        return process.stdout, Document(process.stdout)

    empty = fixture('empty', [])
    html, document = render(empty)
    check(not document.cards(), 'An empty account has zero dashboard vehicle cards')
    check(all(document.summary(key) == 0 for key in ('vehicles', 'protected', 'online', 'attention')),
          'Empty dashboard summaries are zero')
    check(bool(document.with_attr('href', '?tab=vehicles#vehicle-form')), 'Empty dashboard links directly to the add-vehicle form')
    menus = document.with_attr('id', 'jsonmenu')
    check(len(menus) == 1, 'Native navigation metadata is present')
    menu = json.loads(menus[0].text())
    active = [item['Name'] for item in menu if item.get('active')]
    check(active == ['Dashboard'], 'Dashboard is the only active default navigation tab')

    one = fixture('one', [entry('Mein Camper')])
    html, document = render(one)
    check(len(document.cards()) == 1 and document.cards()[0].attrs.get('data-vehicle-id') == 'fahrzeug_1',
          'A single vehicle renders exactly one correctly identified card')
    check(document.summary('vehicles') == 1 and document.summary('protected') == 1 and document.summary('online') == 1
          and document.summary('attention') == 0, 'Single fresh protected vehicle summaries reflect its known state')
    check(document.cards()[0].attrs.get('data-condition') == 'protected', 'Fresh armed vehicle is shown as protected')
    fuel = [node for node in document.cards()[0].descendants()
            if 'ld-reading' in node.attrs.get('class', '').split() and 'Kraftstoff' in node.text()]
    check(len(fuel) == 1 and ''.join(fuel[0].text().split()) == 'Kraftstoff60%',
          'Integer fuel percentage preserves a trailing zero')
    card_links = [node.attrs.get('href', '') for node in document.cards()[0].descendants() if node.tag == 'a']
    check(any('tab=vehicles' in link and 'vehicle=fahrzeug_1' in link for link in card_links),
          'The vehicle card opens the existing vehicle details')
    _, explicit = render(one, {'tab': 'dashboard'})
    check(len(explicit.cards()) == 1, 'Explicit dashboard route renders the same single-vehicle view')
    _, unknown_tab = render(one, {'tab': 'does-not-exist'})
    check(len(unknown_tab.cards()) == 1, 'Unknown routes safely fall back to the start dashboard')

    unknown_protection = fixture('unknown-protection', [entry('Unbekannter Schutz', armed=None)])
    _, document = render(unknown_protection)
    check(document.summary('protected') == 0 and document.cards()[0].attrs.get('data-condition') != 'protected',
          'A fresh online vehicle with unknown armed state is never counted as protected')

    subprocess.run([php, '-r', 'require $argv[1]; $s=new PandoraLox\\Store($argv[2],$argv[3]); '
                    '$s->saveSettings(["enabled"=>false,"poll_interval"=>60,"topic_prefix"=>"pandora"]);',
                    str(base / 'bin/bootstrap.php'), str(one / 'config'), str(one / 'data')],
                   capture_output=True, text=True, check=True)
    _, document = render(one)
    check(document.summary('protected') == 0 and document.summary('online') == 0
          and document.cards()[0].attrs.get('data-condition') == 'paused',
          'Global pause cannot present cached armed/online state as current protection')

    hostile_name = 'Camper <img src=x onerror=alert("dashboard-name")>'
    hostile_event = 'Meldung <script>alert("dashboard-event")</script>'
    mixed_entries = [entry(hostile_name), entry('Status unbekannt', cache=False), entry('Alte Daten', offset=-600),
                     entry('Pausiertes Fahrzeug', enabled=False), entry('Abruffehler', error='Synthetischer Anmeldefehler'),
                     entry('Aktueller Alarm', alarm=True), entry('Unscharfes Fahrzeug', armed=False)]
    mixed_entries[4]['state']['vehicle']['status']['fuelPercent'] = 100
    mixed_entries[6]['state']['vehicle']['status']['fuelPercent'] = 0
    mixed_entries[0]['state']['events'] = [{'id': 'synthetic-event', 'title': hostile_event, 'occurredAt': iso(),
                                           'description': 'Frei erfundene Testmeldung.', 'severity': 'info'}]
    mixed = fixture('mixed', mixed_entries)
    html, document = render(mixed)
    cards = document.cards()
    check(len(cards) == len(mixed_entries), 'Multiple vehicles render exactly one card each')
    check([card.attrs.get('data-vehicle-id') for card in cards] == ['fahrzeug_' + str(i) for i in range(1, 8)],
          'Each configured vehicle appears once in configuration order')
    conditions = [card.attrs.get('data-condition') for card in cards]
    check(conditions == ['protected', 'unknown', 'stale', 'paused', 'error', 'alarm', 'disarmed'],
          'Fresh, unknown, stale, paused, error, alarm and disarmed states remain distinguishable')
    check(document.summary('vehicles') == 7 and document.summary('protected') == 1 and document.summary('online') == 3
          and document.summary('attention') == 5,
          'Summaries exclude stale/paused/error/unknown protection and online snapshots')
    for index, percent in ((4, 100), (6, 0)):
        fuel = [node for node in cards[index].descendants()
                if 'ld-reading' in node.attrs.get('class', '').split() and 'Kraftstoff' in node.text()]
        check(len(fuel) == 1 and ''.join(fuel[0].text().split()) == f'Kraftstoff{percent}%',
              f'Fuel boundary {percent}% stays numeric and is not confused with unknown')
    check('Synthetischer Anmeldefehler' in cards[4].text(), 'An account read error is visible on the affected card')
    check(any(word in cards[2].text().casefold() for word in ('veraltet', 'älter', 'alte daten')),
          'Stale cache carries an explicit visible warning')
    check(hostile_name not in html and '&lt;img' in html, 'Vehicle names are escaped before reaching dashboard markup')
    check(hostile_event not in html and '&lt;script' in html, 'Recent event text is escaped before reaching dashboard markup')
    check(hostile_name in cards[0].text() and hostile_event in document.root.text(),
          'Escaped names and recent events remain readable as text')
    check(not document.with_attr('name', 'password') and not document.with_attr('name', 'login'),
          'Start dashboard has no credential fields')

    # Rendering state from a GET with action-shaped query parameters must still be read-only.
    html, document = render(mixed, {'tab': 'dashboard', 'vehicle': 'fahrzeug_1', 'action': 'refresh', 'command': 'unlock'})
    check(len(document.cards()) == 7 and not list((mixed / 'data/queue').glob('*.json')),
          'Action-looking GET parameters neither run commands nor remove dashboard cards')

    icons = ['motorcycle', 'car', 'sports_car', 'van', 'motorhome']
    icon_entries = [entry('', vehicle_icon=kind, upstream_name='Pandora Fahrzeug ' + str(i + 1))
                    for i, kind in enumerate(icons)]
    icon_entries.append(entry('Mein Alias', vehicle_icon='auto', upstream_name='Pandora Original',
                              vehicle_metadata={'vehicleType': 'van'}))
    icon_entries.append(entry('Wohnmobil Motorrad Sportwagen', vehicle_icon='auto', upstream_name='Camper',
                              vehicle_metadata={'carType': 0, 'vehicleType': None, 'model': 'Camper Pro'}))
    icon_entries.append(entry('', cache=False, vehicle_icon='auto'))
    hostile_upstream = 'Pandora <img src=x onerror=alert("upstream-name")>'
    icon_entries.append(entry('', upstream_name=hostile_upstream))
    icon_entries[0]['state']['events'] = [{'id': 'icon-event', 'title': 'Automatische Namensanzeige', 'occurredAt': iso(),
                                          'description': '', 'severity': 'info'}]
    profiles = fixture('profiles', icon_entries)
    html, document = render(profiles)
    cards = document.cards()
    check([card.attrs.get('data-vehicle-icon') for card in cards[:5]] == icons,
          'All five explicit vehicle symbols reach the matching dashboard card')
    for index, card in enumerate(cards[:5]):
        check('Pandora Fahrzeug ' + str(index + 1) in card.text(),
              'An empty custom name uses the corresponding name from Pandora')
    check(cards[5].attrs.get('data-vehicle-icon') == 'van' and 'Mein Alias' in cards[5].text()
          and 'Pandora Original' not in cards[5].text(),
          'A custom alias wins while automatic artwork uses normalized vehicle metadata')
    check(cards[6].attrs.get('data-vehicle-icon') == 'car',
          'Automatic artwork never guesses from a display name, alarm model or undocumented car type number')
    check('Fahrzeug 8' in cards[7].text() and cards[7].attrs.get('data-vehicle-icon') == 'car',
          'A vehicle without a name or cached data keeps a readable fallback and generic symbol')
    activity = [node for node in document.root.descendants() if 'ld-activity-list' in node.attrs.get('class', '').split()]
    check(len(activity) == 1 and 'Pandora Fahrzeug 1' in activity[0].text(),
          'Recent activity uses the same automatic display name as its vehicle card')
    check(any('Status für Pandora Fahrzeug 1 abrufen' == node.attrs.get('aria-label')
              for node in cards[0].descendants()),
          'Screen-reader action labels use the resolved Pandora vehicle name')
    check(hostile_upstream not in html and '&lt;img' in html and hostile_upstream in cards[8].text(),
          'An upstream vehicle name remains readable and HTML escaped')
    art = []
    for card in cards[:5]:
        svg = [node for node in card.descendants() if node.tag == 'svg' and 'ld-car-art' in node.attrs.get('class', '').split()]
        check(len(svg) == 1 and svg[0].attrs.get('aria-hidden') == 'true',
              'Each chosen vehicle illustration is a single decorative SVG')
        geometry = {'d', 'x', 'y', 'width', 'height', 'r', 'rx', 'cx', 'cy', 'points', 'transform'}
        art.append(tuple(sorted((node.tag, tuple(sorted((key, value) for key, value in node.attrs.items() if key in geometry)))
                                for node in svg[0].descendants() if node.tag in ('path', 'rect', 'circle', 'ellipse', 'polygon'))))
    check(len(set(art)) == 5, 'The five vehicle choices have distinct artwork geometry')
    artwork_ids = [node.attrs['id'] for card in cards for node in card.descendants() if 'id' in node.attrs]
    check(len(artwork_ids) == len(set(artwork_ids)), 'Multiple vehicle illustrations never duplicate SVG definition IDs')


print(f'PASS: {passed} dashboard checks with synthetic data only; network functions disabled.')
