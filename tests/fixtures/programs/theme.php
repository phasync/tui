<?php
// A closure program that shows the theme it is drawn in:
//   TUI_HEADLESS=40x3 TUI_THEME=light php theme.php [--theme=mono] [explicit]
// With "explicit" it passes theme: 'dark' to Tui::run() (the program's choice wins).
require __DIR__ . '/../../../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Element\Text;
use phasync\Tui\Tui;

$explicit = in_array('explicit', $argv, true) ? 'dark' : null;
fiber\run(function () use ($explicit) {
    Tui::run(fn() => new Text(fn() => 'theme ' . Component::runningRoot()?->theme()), theme: $explicit);
});
