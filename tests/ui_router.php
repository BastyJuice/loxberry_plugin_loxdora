<?php
// Read-only native LB4 preview. Use only with local PHP's development server.
if (getenv('PANDORA_TEST_STUB') !== '1') { http_response_code(403); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Lokale Vorschau: Aktionen sind deaktiviert. Es wird keine Verbindung zu Pandora oder MQTT aufgebaut.');
}
$previewRoot = getenv('PANDORA_PREVIEW_ROOT');
$demo = $_GET['demo'] ?? 'multiple';
if ($previewRoot && is_string($demo) && in_array($demo, ['multiple', 'single', 'empty', 'icons'], true)) {
    putenv('PANDORA_TEST_CONFIG=' . $previewRoot . '/' . $demo . '/config');
    putenv('PANDORA_TEST_DATA=' . $previewRoot . '/' . $demo . '/data');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/system/') === 0) {
    $root = realpath(__DIR__ . '/official-lb4/www');
    $file = realpath($root . $path);
    if ($file === false || strpos($file, $root . '/') !== 0 || !is_file($file)) { http_response_code(404); exit; }
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($file);
} elseif ($path === '/dashboard.css') {
    header('Content-Type: text/css; charset=utf-8');
    readfile(__DIR__ . '/../webfrontend/htmlauth/dashboard.css');
} elseif ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/../webfrontend/htmlauth/index.php';
} else { http_response_code(404); }
