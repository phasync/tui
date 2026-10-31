<?php
/**
 * Writes tests/fixtures/images/{icat,imgcat,sixel}.bytes: a 120x90 PNG sent the way
 * `kitty +kitten icat` (centred in 40 columns, direct transmission in chunks) and iTerm2's
 * `imgcat` send it, as a pty passes them on (a line feed becomes CR LF). There is no kitty or
 * iTerm2 on the machine these were made on; the forms follow their sources. sixel.bytes is
 * what ImageMagick's `convert image.png sixel:-` prints for it (made only where convert is
 * installed).
 */
require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Image;

$pixels = '';
for ($y = 0; $y < 90; $y++) {
    for ($x = 0; $x < 120; $x++) {
        $pixels .= chr($x * 2) . chr($y * 2) . chr(128);
    }
}
$png = Image::encodePng($pixels, 120, 90, false);
$dir = __DIR__ . '/../tests/fixtures/images';
// icat: CUF to centre, then a=T,f=100,t=d with q=2 (it wants no replies), 4096-byte chunks
$chunks = str_split(base64_encode($png), 4096);
$icat = "\e[12C";
foreach ($chunks as $i => $chunk) {
    $more = $i < count($chunks) - 1 ? 1 : 0;
    $icat .= $i === 0 ? "\e_Ga=T,f=100,t=d,q=2,m=$more;$chunk\e\\" : "\e_Gm=$more;$chunk\e\\";
}
file_put_contents("$dir/icat.bytes", $icat . "\r\n");
// imgcat: OSC 1337 File= with the name and size, BEL, a line feed
file_put_contents("$dir/imgcat.bytes", "\e]1337;File=name=" . base64_encode('gradient.png') . ';size=' . strlen($png) . ';inline=1:' . base64_encode($png) . "\x07\r\n");
// sixel: ImageMagick's encoder, a line feed after
$convert = trim((string) shell_exec('command -v convert 2>/dev/null'));
if ($convert !== '') {
    $file = sys_get_temp_dir() . '/gradient-' . getmypid() . '.png';
    file_put_contents($file, $png);
    file_put_contents("$dir/sixel.bytes", shell_exec(escapeshellarg($convert) . ' ' . escapeshellarg($file) . ' sixel:-') . "\r\n");
    unlink($file);
}
