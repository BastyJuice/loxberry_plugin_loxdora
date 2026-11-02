<?php
declare(strict_types=1);

namespace PandoraLox;

/** Fixed local vectors; neither the type value nor vehicle data enter SVG markup. */
function vehicleArtwork(string $type): string
{
    static $counter = 0;
    $id = 'ld-vehicle-art-' . ++$counter;
    $wheel = static function (int $x, int $y = 153, int $radius = 22) use ($id): string {
        $scale = number_format($radius / 22, 6, '.', '');
        return '<g transform="translate(' . $x . ' ' . $y . ') scale(' . $scale . ')">'
            . '<circle r="22" fill="#192b38"/><circle r="20" stroke="#3c4d59" stroke-width="1.5"/>'
            . '<circle r="14" fill="url(#' . $id . '-metal)"/><circle r="11" fill="#5a7080"/>'
            . '<path d="m0-11 3 8 8 3-8 3-3 8-3-8-8-3 8-3Z" fill="#e2e9ed"/>'
            . '<circle r="4" fill="#9badb9"/><circle r="2" fill="#354b5b"/>'
            . '<path d="M-15-9a18 18 0 0 1 25-6" stroke="#7d8d98" stroke-width="1.2" stroke-linecap="round" opacity=".6"/>'
            . '</g>';
    };

    switch ($type) {
        case 'motorcycle':
            $body = <<<'SVG'
<g fill="none" stroke-linecap="round" stroke-linejoin="round">
    <g stroke="#1b2e3b" stroke-width="9"><circle cx="104" cy="146" r="28"/><circle cx="277" cy="146" r="28"/></g>
    <g stroke="#c3d1da" stroke-width="2.5"><circle cx="104" cy="146" r="22"/><circle cx="277" cy="146" r="22"/></g>
    <g stroke="#7d919f" stroke-width="1.5">
        <path d="m104 124 0 44m-22-22h44m-38-16 32 32m-32 0 32-32M277 124v44m-22-22h44m-38-16 32 32m-32 0 32-32"/>
        <circle cx="277" cy="146" r="12"/>
    </g>
    <path d="m106 146 78-8-38-31-40 39 49-1" stroke="#526b7b" stroke-width="7"/>
    <path d="m146 107 27 34 43-32-70-2 35 40" stroke="#aebdc6" stroke-width="5"/>
    <path d="m119 106 31-4 38 6 26-4 25-17" stroke="#263e4c" stroke-width="6"/>
    <path d="m94 114 8-13 25-7 20 3-7 13-21 1" fill="@BODY@" stroke="#8fa3af" stroke-width="1.3"/>
    <path d="m97 101 26-8 21 3 3 5-29 2Z" fill="#263b48"/>
    <path d="M146 97q13-18 31-18 18 0 38 16l-9 15-31 2-26-8Z" fill="@BODY@" stroke="#8fa3af" stroke-width="1.3"/>
    <path d="m154 92 16-8 19 3" stroke="#fff" stroke-width="3"/>
    <path d="m211 101 13 7-15 21-9-19Z" fill="#537c6b"/>
    <path d="m242 81 30 65m-21-66 28 64" stroke="#b3c4ce" stroke-width="5"/>
    <path d="m247 91 24 50" stroke="#526c7b" stroke-width="2"/>
    <path d="m237 86 8-17-12-5m12 5 17-2" stroke="#2f4757" stroke-width="4"/>
    <path d="m252 66 10-14 11-3" stroke="#6b8392" stroke-width="2.5"/>
    <path d="m268 46 13-3q4 0 5 3l1 3q0 3-4 4l-12 1Z" fill="#263f4d"/>
    <path d="m244 83 15 4 4 10-15 3-9-9Z" fill="#dce7ed" stroke="#8fa3af" stroke-width="1.3"/>
    <path d="m253 88 7 2 2 6-9 1Z" fill="#fff5cf"/>
    <path d="M253 124q19-16 43-1" stroke="@BODY@" stroke-width="6"/>
    <path d="M87 123q15-11 33-5" stroke="#a9bbc6" stroke-width="3.5"/>
    <path d="m179 112 26 4 10 19-15 11-27-9Z" fill="#5a7281" stroke="#304c5d" stroke-width="1.3"/>
    <path d="m180 118 21 4m-19 1 22 4m-20 1 22 4" stroke="#c0cdd5" stroke-width="2"/>
    <circle cx="188" cy="137" r="11" fill="@METAL@" stroke="#8fa3af" stroke-width="1.3"/>
    <circle cx="188" cy="137" r="4" fill="#5b7281"/>
    <path d="m204 135 18 17 32 1" stroke="#536e7d" stroke-width="5"/>
    <path d="m220 146 36-1 8 9-42 3Z" fill="@METAL@" stroke="#8fa3af" stroke-width="1.3"/>
    <path d="m257 148 7 6-7 1" fill="#314b5a"/>
    <path d="m168 144-12 5m36-1 7 15" stroke="#314b5a" stroke-width="3"/>
    <circle cx="104" cy="146" r="4" fill="#7d96a6"/><circle cx="277" cy="146" r="4" fill="#7d96a6"/>
    <path d="m93 106-6 1-1 5 6-1" fill="#c9685e"/>
</g>
SVG;
            $shadow = '<ellipse cx="188" cy="176" rx="122" ry="7" fill="#9bb5a8" opacity=".2"/>';
            break;

        case 'sports_car':
            $body = <<<'SVG'
<path d="m39 132 13-15 50-10 39-20q12-6 30-6h38q18 0 34 10l35 21 46 9q14 3 20 16l-4 16h-25a28 28 0 0 0-56 0H118a28 28 0 0 0-56 0H37Z" fill="@BODY@" stroke="#8fa3af" stroke-width="1.3"/>
<path d="m119 109 31-17q8-5 22-5h35q16 0 27 8l24 17Z" fill="@GLASS@"/>
<path d="m179 87-5 24m35-23 17 24" stroke="#d5dfe5" stroke-width="3.5"/>
<path d="m126 107 28-14q6-3 15-3" fill="none" stroke="#bbd3de" stroke-width="1.3" opacity=".75"/>
<path d="m44 133 72-14 114 2 80-1 25 13" fill="none" stroke="#b1c1cc" stroke-width="1.4"/>
<path d="m114 127 109-4 26 7-97 10Z" fill="#d3dee5"/>
<path d="m121 118 3 29m105-29-5 29" fill="none" stroke="#9cafff" stroke-opacity=".15" stroke-width="1.3"/>
<path d="m224 118-2 29" stroke="#9bafbb" stroke-width="1.2"/>
<path d="m203 122 12-1" stroke="#6d8798" stroke-width="2.2" stroke-linecap="round"/>
<path d="m251 115 9 2 5 6-13-1Z" fill="#496677"/>
<path d="m52 121 9-12 35-5 1 4-39 8Z" fill="#7e939f"/>
<path d="m40 135 18-3 6 5-25 3Z" fill="#cc776a"/>
<path d="m316 126 17 4 4 7-26-4Z" fill="#fff5d6" stroke="#bdc7cc" stroke-width=".8"/>
<path d="m325 141 16-2-4 9h-14Z" fill="#425c6d"/>
<path d="m39 150 23 1m56 0h140m57 0 25-3" stroke="#4c6677" stroke-width="5" stroke-linecap="round"/>
<path d="m45 146 13-2m68 3 122 1" stroke="#eaf0f3" stroke-width="1.3"/>
SVG;
            $body .= $wheel(90, 153, 24) . $wheel(287, 153, 24);
            $shadow = '<ellipse cx="190" cy="178" rx="151" ry="7" fill="#9bb5a8" opacity=".2"/>';
            break;

        case 'van':
            $body = <<<'SVG'
<path d="M43 145V59q0-13 13-13h167q12 0 19 11l30 44 40 9q16 4 18 19v18h-18a28 28 0 0 0-56 0H117a28 28 0 0 0-56 0Z" fill="@BODY@" stroke="#8fa3af" stroke-width="1.3"/>
<path d="M54 51h167q9 0 15 9H44q0-9 10-9Z" fill="#f8fafb"/>
<path d="M58 67h139q7 0 7 7v36q0 7-7 7H58Z" fill="#e7edf0" stroke="#b4c3cc" stroke-width="1.2"/>
<path d="M65 73h130v37H65" fill="none" stroke="#fff" stroke-width="1.2" opacity=".6"/>
<path d="M212 63v81M51 64v80" stroke="#9eafbb" stroke-width="1.2"/>
<path d="M223 69h12l27 36h-39Z" fill="@GLASS@"/>
<path d="m228 73 7 1 19 26h-26Z" fill="#93afba" opacity=".28"/>
<path d="m265 100 7 3 10 12-12-1Z" fill="#2b4859"/>
<path d="m270 116 10 4" stroke="#6e8796" stroke-width="2.5" stroke-linecap="round"/>
<path d="M45 126h165m9 0h47l11 5h51" stroke="#708693" stroke-width="4.5"/>
<path d="M47 134h164m8 0h42m50 2h17" stroke="#cad5dc" stroke-width="1.5"/>
<path d="M223 110v34m39-27 5 28" stroke="#9eafbb" stroke-width="1.2"/>
<path d="M226 115h12m-43-24h6" stroke="#688292" stroke-width="2.5" stroke-linecap="round"/>
<path d="m305 118 17 5 3 9h-18Z" fill="#fff3cf" stroke="#bac6ce" stroke-width=".8"/>
<path d="M44 112h5v17h-5" fill="#ca786c"/>
<path d="M44 146h17m57 0h137m58 0h16" stroke="#5d7585" stroke-width="5"/>
<path d="m63 52 134 0" stroke="#d9e2e7" stroke-width="2"/>
SVG;
            $body .= $wheel(89) . $wheel(284);
            $shadow = '<ellipse cx="187" cy="176" rx="149" ry="8" fill="#9bb5a8" opacity=".2"/>';
            break;

        case 'motorhome':
            $body = <<<'SVG'
<path d="M39 146V50q0-11 12-11h185q12 0 20 7l27 7q9 3 9 10v7l-43 7 31 26 33 8q16 4 18 19v17h-18a28 28 0 0 0-56 0H117a28 28 0 0 0-56 0Z" fill="@BODY@" stroke="#8fa3af" stroke-width="1.3"/>
<path d="M52 44h184q8 0 14 4l28 10q5 2 6 6H39V51q0-7 13-7Z" fill="#fbfcfd"/>
<path d="m133 39 3-5h39l5 5" fill="#d2dde4" stroke="#a5b8c4" stroke-width="1.2"/>
<path d="M59 62h68q5 0 5 5v27q0 5-5 5H59q-5 0-5-5V67q0-5 5-5Z" fill="#a1b3bf"/>
<rect x="58" y="66" width="70" height="29" rx="3" fill="@GLASS@"/>
<path d="M94 66v29" stroke="#c4d0d8" stroke-width="3"/>
<path d="m63 71 25 0" stroke="#b9d0db" stroke-width="1.2" opacity=".65"/>
<rect x="147" y="62" width="47" height="37" rx="5" fill="#a1b3bf"/>
<rect x="151" y="66" width="39" height="29" rx="3" fill="@GLASS@"/>
<path d="m155 71 29 0" stroke="#b9d0db" stroke-width="1.2" opacity=".65"/>
<path d="M207 64h31v81h-31Z" fill="#e7eef1" stroke="#99aebc" stroke-width="1.2"/>
<rect x="214" y="72" width="17" height="28" rx="3" fill="@GLASS@"/>
<path d="M230 112h3m-22 25h23" stroke="#738d9c" stroke-width="2" stroke-linecap="round"/>
<path d="m246 81 13 6 17 20h-30Z" fill="@GLASS@"/>
<path d="m252 86 6 4 11 13h-17Z" fill="#a9c0ca" opacity=".3"/>
<path d="m276 102 8 3 6 10-12-2Z" fill="#2d4b5a"/>
<path d="M40 113h160l-14 11H40Zm201 2h25l18 10h45v6h-48l-17-9h-23Z" fill="#537f6b"/>
<path d="M40 129h148l13-10m40 9h23l17 9h48" fill="none" stroke="#aec9bc" stroke-width="2.5"/>
<path d="M246 111v34m24-25 3 25" stroke="#9eafbb" stroke-width="1.2"/>
<path d="M249 116h11" stroke="#688292" stroke-width="2.5" stroke-linecap="round"/>
<rect x="68" y="133" width="28" height="9" rx="1.5" fill="#dce5eb" stroke="#a9bbc7" stroke-width="1"/>
<path d="m305 120 17 4 4 8h-19Z" fill="#fff3cf" stroke="#bac6ce" stroke-width=".8"/>
<path d="M40 109h5v20h-5" fill="#ca786c"/>
<path d="M40 146h20m58 0h138m57 0h17" stroke="#5d7585" stroke-width="5"/>
<path d="M52 54h142" stroke="#d8e3e8" stroke-width="1.5"/>
SVG;
            $body .= $wheel(89) . $wheel(285);
            $shadow = '<ellipse cx="187" cy="176" rx="149" ry="8" fill="#9bb5a8" opacity=".2"/>';
            break;

        case 'car':
        default:
            $body = <<<'SVG'
<path d="m40 126 12-19 44-9 35-33q9-8 23-8h61q16 0 29 10l39 31 40 12q12 4 14 17v21h-25a28 28 0 0 0-56 0H116a28 28 0 0 0-56 0H38Z" fill="@BODY@" stroke="#8fa3af" stroke-width="1.3"/>
<path d="m110 98 28-27q5-5 17-5h58q12 0 21 7l31 26Z" fill="@GLASS@"/>
<path d="m175 65-7 34m45-33 16 34" stroke="#d2dfe6" stroke-width="3.5"/>
<path d="m119 94 24-22 24-1" fill="none" stroke="#bbd3de" stroke-width="1.3" opacity=".7"/>
<path d="m49 116 56-10h162l55 12" fill="none" stroke="#b1c1cc" stroke-width="1.4"/>
<path d="m118 110-2 35m109-36 8 36" fill="none" stroke="#9fafbb" stroke-width="1.2"/>
<path d="m142 114 14 0m47 0h14" stroke="#718a9a" stroke-width="2.2" stroke-linecap="round"/>
<path d="m263 100 11 4 6 7-16-2Z" fill="#547080"/>
<path d="m48 123 15-1 7 7-30 1Z" fill="#cc776a"/>
<path d="m308 117 18 5 5 10-26-3Z" fill="#fff4d4" stroke="#bdc9cf" stroke-width=".8"/>
<path d="m42 141 15-2m66 1 128-1m64 0h18" stroke="#d7e1e7" stroke-width="1.4"/>
<path d="M40 148h20m57 0h138m58 0h22" stroke="#5a7383" stroke-width="5"/>
<path d="m324 139 11-1v6h-12Z" fill="#638092"/>
SVG;
            $body .= $wheel(88) . $wheel(284);
            $shadow = '<ellipse cx="188" cy="176" rx="151" ry="8" fill="#9bb5a8" opacity=".2"/>';
            break;
    }

    $defs = '<defs>'
        . '<linearGradient id="' . $id . '-body" x1="0%" y1="0%" x2="0%" y2="100%"><stop stop-color="#fff"/><stop offset=".48" stop-color="#e7eef2"/><stop offset="1" stop-color="#aabcc9"/></linearGradient>'
        . '<linearGradient id="' . $id . '-glass" x1="0%" y1="0%" x2="100%" y2="100%"><stop stop-color="#244552"/><stop offset="1" stop-color="#7399a6"/></linearGradient>'
        . '<linearGradient id="' . $id . '-metal" x1="0%" y1="0%" x2="100%" y2="100%"><stop stop-color="#d9e3e9"/><stop offset=".5" stop-color="#a8bccb"/><stop offset="1" stop-color="#728b9d"/></linearGradient>'
        . '</defs>';
    $body = strtr($body, ['@BODY@' => 'url(#' . $id . '-body)', '@GLASS@' => 'url(#' . $id . '-glass)', '@METAL@' => 'url(#' . $id . '-metal)']);

    return '<svg xmlns="http://www.w3.org/2000/svg" class="ld-car-art" viewBox="0 0 380 195" fill="none" aria-hidden="true" focusable="false">'
        . $defs . $shadow . $body . '</svg>';
}
