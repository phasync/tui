<?php
/**
 * Writes examples/assets/photo.png, the picture examples/graphs.php shows with drawImage(): a
 * small landscape (sky, sun, hills) drawn with GD, 96x64 pixels.
 */
$w = 96;
$h = 64;
$gd = imagecreatetruecolor($w, $h);
for ($y = 0; $y < $h; $y++) {
    // the sky from deep blue to a warm horizon
    $t = $y / $h;
    $c = imagecolorallocate($gd, (int) (30 + 200 * $t), (int) (60 + 120 * $t), (int) (160 - 40 * $t));
    imageline($gd, 0, $y, $w - 1, $y, $c);
}
imagefilledellipse($gd, 70, 26, 22, 22, imagecolorallocate($gd, 255, 214, 90));
$far = imagecolorallocate($gd, 60, 110, 90);
$near = imagecolorallocate($gd, 34, 80, 50);
$hill = fn(int $base, int $amp, float $freq, float $phase, int $color) => imagefilledpolygon($gd, array_merge(
    ...array_map(fn(int $x) => [$x, (int) ($base - $amp * sin($x * $freq + $phase))], range(0, $w, 4)),
    ...[[$w, $h], [0, $h]],
), $color);
$hill(44, 8, 0.07, 0.5, $far);
$hill(52, 6, 0.11, 2.0, $near);
imagepng($gd, __DIR__ . '/../examples/assets/photo.png', 9);
