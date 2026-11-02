<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bin/lib/VehicleArtwork.php';

function artworkCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$types = ['motorcycle', 'car', 'sports_car', 'van', 'motorhome'];
$ids = [];
$shapes = [];
foreach ($types as $type) {
    // Repeated calls must have independent gradient references on one page.
    for ($iteration = 0; $iteration < 2; ++$iteration) {
        $svg = \PandoraLox\vehicleArtwork($type);
        $document = new DOMDocument();
        artworkCheck($document->loadXML($svg, LIBXML_NONET), $type . ': invalid SVG');
        $root = $document->documentElement;
        artworkCheck($root !== null && $root->localName === 'svg', $type . ': missing SVG');
        foreach (['class' => 'ld-car-art', 'viewBox' => '0 0 380 195', 'aria-hidden' => 'true', 'focusable' => 'false'] as $key => $value) {
            artworkCheck($root->getAttribute($key) === $value, $type . ': missing dashboard attribute');
        }
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            artworkCheck(!isset($ids[$id]), 'Repeated SVG ID: ' . $id);
            $ids[$id] = true;
        }
        preg_match_all('/url\(#([a-z0-9-]+)\)/', $svg, $references);
        foreach ($references[1] as $reference) {
            artworkCheck($xpath->query('//*[@id="' . $reference . '"]')->length === 1, $type . ': broken gradient reference');
        }
        foreach ($xpath->query('//*') as $node) {
            artworkCheck(!in_array(strtolower($node->localName), ['script', 'foreignobject', 'image', 'text', 'style', 'a', 'use'], true), $type . ': unexpected SVG content');
            foreach ($node->attributes as $attribute) {
                artworkCheck(strpos(strtolower($attribute->name), 'on') !== 0 && !in_array(strtolower($attribute->localName), ['href', 'src'], true), $type . ': external or executable content');
            }
        }
        artworkCheck(strpos($svg, '@BODY@') === false && strpos($svg, '@GLASS@') === false && strpos($svg, '@METAL@') === false, 'Unresolved paint reference');
        $shapes[$type] = preg_replace('/ld-vehicle-art-[0-9]+/', 'fixed-id', $svg);
    }
}
artworkCheck(count(array_unique($shapes)) === count($types), 'Vehicle types must have distinct artwork');
$fallback = preg_replace('/ld-vehicle-art-[0-9]+/', 'fixed-id', \PandoraLox\vehicleArtwork('<script>alert(1)</script>'));
artworkCheck($fallback === $shapes['car'], 'Unknown type must safely fall back to car');
echo "PASS artwork: five distinct self-contained SVGs, valid markup, unique paint IDs, fixed dashboard contract and safe fallback.\n";
