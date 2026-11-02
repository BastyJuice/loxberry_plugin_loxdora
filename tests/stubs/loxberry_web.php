<?php
// Development SDK boundary using unmodified official LoxBerry 4.0.0.15 templates/assets.
// No real LoxBerry authentication, installation database or system actions are simulated.
if (getenv('PANDORA_TEST_STUB')!=='1') { throw new RuntimeException('Test SDK disabled.'); }
final class LBWeb {
    private static array $variables=[];
    private static function template(string $name): string {
        $text=(string)file_get_contents(__DIR__.'/../official-lb4/'.$name);
        $text=preg_replace_callback('/<TMPL_(IF|UNLESS)\s+([A-Z0-9_.]+)\s*>(.*?)<\/TMPL_\1>/s',static function (array $match): string {
            $truth=!empty(self::$variables[$match[2]]);
            return ($match[1]==='IF'?$truth:!$truth)?$match[3]:'';
        },$text);
        return preg_replace_callback('/<TMPL_VAR\s+([A-Z0-9_.]+)\s*>/',static function (array $match): string { return (string)(self::$variables[$match[1]] ?? ''); },$text);
    }
    public static function lbheader($title,$help,$unused,$noJqm): void {
        $language=parse_ini_file(__DIR__.'/../official-lb4/language_de.ini',true,INI_SCANNER_RAW);
        foreach ($language as $section=>$items) { foreach ($items as $key=>$value) { self::$variables[$section.'.'.$key]=htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
        $theme=getenv('PANDORA_PREVIEW_THEME') ?: 'soft-rounded';
        if (!in_array($theme,['soft-rounded','clean-admin','glass','classic-lb'],true)) { $theme='soft-rounded'; }
        $navbar=array_values($GLOBALS['navbar'] ?? []);
        self::$variables+=['LANG'=>'de','LOAD_JQM'=>!$noJqm,'IS_CORE_PAGE'=>false,'PAGE'=>'main1',
            'TEMPLATETITLE'=>htmlspecialchars('LoxBerry '.$title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
            'THEME_CLASS'=>'theme-'.$theme,'THEME_FILE'=>'theme-'.$theme.'.css','HTMLHEAD'=>'','LBVERSION'=>'4.0.0.15',
            'HELPLINK'=>htmlspecialchars($help,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'HELPTEXT'=>self::$variables['COMMON.HELP_NOT_AVAILABLE'] ?? '',
            'JSONMENU'=>'<div id="jsonmenu" style="display:none">'.json_encode($navbar,JSON_HEX_TAG|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'</div>',
            'SIDEBAR_PLUGINS_HTML'=>'<div class="lb-sidebar-section">Meine Plugins</div><a class="lb-sidebar-link" href="/index.php?tab=dashboard"><span class="lb-sidebar-name">Loxdora</span><div class="lb-sidebar-status"></div></a>',
            'SIDEBAR_PLUGINS_TABBAR_HTML'=>'<a class="lb-tab-popup-item" href="/index.php?tab=dashboard"><span>Loxdora</span></a>',
            'NAVBAR_PLAIN'=>'','NAVBARJS'=>''];
        echo self::template('head.html'),self::template('pagestart.html');
        echo '<div class="lb-callout" role="status" style="margin:1rem"><strong>Lokale Vorschau mit Testdaten.</strong> Die Fahrzeuge und Statuswerte sind frei erfundene Beispiele. Aktionen sind in dieser Vorschau deaktiviert; es besteht keine Verbindung zu Pandora oder MQTT.</div>';
        if (getenv('PANDORA_PREVIEW_ROOT')) {
            echo '<nav aria-label="Vorschauvarianten" style="margin:1rem;font-size:12px;display:flex;gap:18px;flex-wrap:wrap"><a href="?tab=dashboard&amp;demo=multiple">Mehrere Fahrzeuge</a><a href="?tab=dashboard&amp;demo=single">Ein Fahrzeug</a><a href="?tab=dashboard&amp;demo=empty">Ohne Fahrzeug</a><a href="?tab=dashboard&amp;demo=icons">Alle Fahrzeugsymbole</a></nav>';
        }
    }
    public static function lbfooter(): void { echo self::template('pageend.html'),self::template('foot.html'); }
}
