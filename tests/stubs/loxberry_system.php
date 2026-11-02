<?php
// Development SDK boundary; never included in the plugin ZIP.
if (getenv('PANDORA_TEST_STUB') !== '1') { throw new RuntimeException('Test SDK disabled.'); }
$lbpbindir = dirname(__DIR__, 2) . '/bin';
$lbpconfigdir = getenv('PANDORA_TEST_CONFIG');
$lbpdatadir = getenv('PANDORA_TEST_DATA');
if (!$lbpconfigdir || !$lbpdatadir) { throw new RuntimeException('Isolated preview directories required.'); }
date_default_timezone_set('Europe/Berlin');
if (getenv('PANDORA_TEST_SESSION') === '1') {
    $sessions = $lbpdatadir . '/test-sessions';
    if (!is_dir($sessions)) { mkdir($sessions, 0700, true); }
    session_save_path($sessions);
    session_name('pandora_loxberry');
    session_start();
    $_SESSION['pandora_csrf'] = 'preview-csrf-test-only';
}
