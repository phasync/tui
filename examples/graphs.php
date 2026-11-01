<?php

/**
 * Graphics: a live line graph of two series, a filled area under one of them, axes and labels,
 * a logo drawn with paths and a photo, all on `Canvas` elements with the browser's 2D context.
 *
 *     php examples/graphs.php                       # pixels where the terminal shows images, else Braille
 *     php examples/graphs.php --marker=braille      # or quarter, half, block, pixels
 *     php examples/graphs.php --inline              # the graph only, under the shell's output
 *     php examples/graphs.php --theme=light         # dark, light, mono
 *     php examples/graphs.php --headless=100x30     # the Braille version, printed
 *
 * In kitty, WezTerm, Ghostty, Konsole (Kitty graphics), iTerm2 and VS Code's terminal (iTerm2
 * images), xterm, foot, mlterm and Windows Terminal (Sixel) the canvases are real pixels;
 * everywhere else they are Braille dots, and nothing in the program changes. Two coroutines sample the series; the canvases draw from the `State`.
 * Ctrl-Q quits.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component\{Canvas, CanvasContext};
use phasync\Tui\Element\{Box, Column, Help, Row, Text};
use phasync\Tui\{State, Tui};

$marker = ($m = preg_grep('/^--marker=/', $argv)) ? substr(reset($m), 9) : 'auto';
$theme = ($t = preg_grep('/^--theme=/', $argv)) ? substr(reset($t), 8) : null;
$s = new State(['cpu' => [], 'net' => []]);
$photo = imagecreatefrompng(__DIR__ . '/assets/photo.png');

// the graph: 0-100 % over the last 60 samples, the network as a filled area, the cpu as a line
$graph = function (CanvasContext $c) use ($s) {
    [$w, $h] = [$c->width(), $c->height()];
    [$left, $bottom] = [6, $h - 1.5];
    $x = fn(int $i) => $left + ($w - $left - 1) * $i / 59;
    $y = fn(float $v) => $bottom - ($bottom - 0.5) * $v / 100;
    $c->strokeStyle = '#64748b';
    $c->lineWidth = 0.25;               // (in cells: one Braille dot wide)
    $c->beginPath();
    $c->moveTo($left, 0.5);
    $c->lineTo($left, $bottom);
    $c->lineTo($w - 1, $bottom);
    $c->stroke();
    $c->fillStyle = '#94a3b8';
    $c->textAlign = 'end';
    $c->textBaseline = 'middle';
    foreach ([100, 50, 0] as $v) {
        $c->fillText("$v%", $left - 1, $y($v));
    }
    $c->textAlign = 'center';
    $c->textBaseline = 'top';
    $c->fillText('-60 s', $x(0), $bottom + 0.5);
    $c->fillText('now', $x(59) - 1, $bottom + 0.5);
    if ($s->net !== []) {
        $c->fillStyle = 'rgba(52, 211, 153, 0.45)';
        $c->beginPath();
        $c->moveTo($x(0), $bottom);
        foreach ($s->net as $i => $v) {
            $c->lineTo($x($i), $y($v));
        }
        $c->lineTo($x(count($s->net) - 1), $bottom);
        $c->closePath();
        $c->fill();
    }
    $c->strokeStyle = '#38bdf8';
    $c->lineWidth = 0.4;
    $c->beginPath();
    foreach ($s->cpu as $i => $v) {
        $i === 0 ? $c->moveTo($x($i), $y($v)) : $c->lineTo($x($i), $y($v));
    }
    $c->stroke();
    $c->textAlign = 'start';
    $c->fillStyle = '#38bdf8';
    $c->fillText('cpu', $left + 1, 0.5);
    $c->fillStyle = '#34d399';
    $c->fillText('net', $left + 6, 0.5);
};

// a logo of paths: a hexagon with a lightning bolt in it
$logo = function (CanvasContext $c) {
    [$cx, $cy] = [$c->width() / 2, $c->height() / 2];
    $r = min($c->width() / 2, $c->height()) - 0.5;
    $gradient = $c->createLinearGradient($cx - $r, 0, $cx + $r, 0);
    $gradient->addColorStop(0, '#6366f1');
    $gradient->addColorStop(1, '#ec4899');
    $c->fillStyle = $gradient;
    $c->beginPath();
    for ($k = 0; $k < 6; $k++) {
        $a = M_PI / 3 * $k + M_PI / 6;
        $c->lineTo($cx + $r * cos($a), $cy + $r / 2 * sin($a));     // (a cell is twice as tall as wide)
    }
    $c->closePath();
    $c->fill();
    $c->fillStyle = '#fde047';
    $c->beginPath();
    foreach ([[0.1, -0.8], [-0.35, 0.1], [0, 0.1], [-0.1, 0.8], [0.35, -0.1], [0, -0.1]] as [$dx, $dy]) {
        $c->lineTo($cx + $dx * $r, $cy + $dy * $r / 2);
    }
    $c->closePath();
    $c->fill();
};

$picture = fn(CanvasContext $c) => $c->drawImage($photo, 0, 0, $c->width(), $c->height());

$graphBox = fn() => new Box(new Canvas(draw: $graph, marker: $marker), '<b>cpu</b> and <b>net</b>', class: 'grow');
$screen = fn() => new Column([
    new Row([new Text('<b>graphs</b>'), new Text(fn() => 'marker: ' . $marker . (count($s->cpu) ? sprintf('   cpu %d%%', end($s->cpu)) : ''), class: 'grow text-right text-muted')], class: 'px-1'),
    new Row([
        $graphBox(),
        new Column([
            new Box(new Canvas(draw: $logo, marker: $marker), 'logo', class: 'h-9'),
            new Box(new Canvas(draw: $picture, marker: $marker), 'photo', class: 'grow'),
        ], class: 'w-26'),
    ], class: 'grow flex-col md:flex-row'),
    new Help(class: 'px-1'),
]);

\fiber\run(function () use ($s, $screen, $graphBox, $theme, $argv) {
    // two series sampled by coroutines: a noisy cpu load and a bursty network
    Tui::go(function () use ($s) {
        $v = 40.0;
        while (true) {
            $v = max(2, min(98, $v + mt_rand(-120, 120) / 10));
            $s->cpu = [...array_slice($s->cpu, -59), $v];
            Tui::sleep(0.2);
        }
    });
    Tui::go(function () use ($s) {
        while (true) {
            $burst = mt_rand(0, 9) === 0 ? mt_rand(40, 90) : mt_rand(5, 25);
            $s->net = [...array_slice($s->net, -59), (float) $burst];
            Tui::sleep(0.3);
        }
    });
    in_array('--inline', $argv, true)
        ? Tui::inline(fn() => $graphBox()->with(height: 12), theme: $theme, fps: 30)
        : Tui::run($screen, theme: $theme, fps: 30);
});
