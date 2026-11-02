#!/usr/bin/env php
<?php
/**
 * Pack a BDF bitmap font (up to 8 pixels wide, every glyph in the font's bounding box) into
 * the library's `.font` file for `BigText`: `php tools/bdf2font.php 6x13.bdf resources/fonts/fixed-6x13.font`.
 * The bundled fonts are Markus Kuhn's Misc-Fixed (public domain), from xorg's font-misc-misc.
 */

use phasync\Tui\Internal\BitmapFont;

require __DIR__ . '/../vendor/autoload.php';

[$in, $out] = [$argv[1] ?? null, $argv[2] ?? null];
if ($in === null || $out === null) {
    fwrite(STDERR, "usage: bdf2font.php <font.bdf> <out.font>\n");
    exit(1);
}
$font = BitmapFont::fromBdf((string) file_get_contents($in));
file_put_contents($out, $font->pack());
printf("%s: %dx%d, %d glyphs, %d bytes\n", $out, $font->width, $font->height, \count($font->glyphs), filesize($out));
