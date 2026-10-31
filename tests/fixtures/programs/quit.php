<?php
// A closure program on the real terminal that quits with the key action 'quit' on `q`, bound
//   php quit.php element|root array|string
// on the cards (`onKey:` of two Texts) or on the root (`Tui::run(keys:)`), in the array form
// (['q' => ['quit', 'quit']]) or the string form (['q' => 'quit']). It prints "ended" after
// Tui::run() returned.
require __DIR__ . '/../../../vendor/autoload.php';

use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\Tui;

[, $where, $form] = $argv + [1 => 'element', 2 => 'array'];
$bind = $form === 'array' ? ['q' => ['quit', 'quit']] : ['q' => 'quit'];
$cards = $where === 'element' ? $bind : ['x' => fn() => null];
fiber\run(function () use ($bind, $cards, $where) {
    Tui::run(fn() => new Column([
        new Text('card one', onKey: $cards),
        new Text('card two', onKey: $cards),
    ]), keys: $where === 'root' ? $bind : []);
});
echo "ended\n";
