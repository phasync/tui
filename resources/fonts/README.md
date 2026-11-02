# Bundled bitmap fonts

`fixed-6x13.font` and `fixed-5x7.font` are the Misc-Fixed fonts `6x13` and `5x7` by Markus Kuhn
and others (xorg `font-misc-misc`, ISO 10646-1 versions): "Public domain font. Share and enjoy."
Packed from the BDF sources with `tools/bdf2font.php` into the form `Internal\BitmapFont` reads
(magic, width, height, count, then per glyph a 32-bit code point and one byte per row).
`BigText` draws them (`font: '6x13'`).
