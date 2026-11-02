<?php

/**
 * A kitchen sink: a little of everything on one screen, with no class of its own. The README's
 * first program.
 *
 *     php examples/kitchen-sink.php                 # Tab moves the focus, Ctrl-Q quits
 *     php examples/kitchen-sink.php --theme=light   # dark, light, mono
 *     TUI_HEADLESS=80x24 php examples/kitchen-sink.php   # the screen as text, without a terminal
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component\{Button, Canvas, CanvasContext, Checkbox, Confirm, Dialog, Editor, Log, ProgressBar, Select, Spinner};
use phasync\Tui\Element\{Box, Column, Help, Row, Text};
use phasync\Tui\{State, Tui};

$s = new State(['progress' => 0.0, 'samples' => [], 'fruit' => 'apple', 'loud' => false]);
$log = new Log();
$log->append("started\n");
\fiber\run(function () use ($s, $log) {
    Tui::go(function () use ($s) {            // a coroutine feeds the bar and the graph
        while (true) {
            $s->progress = $s->progress >= 1 ? 0.0 : $s->progress + 0.01;
            $s->samples = [...array_slice($s->samples, -59), 50 + 40 * sin(count($s->samples) / 5)];
            Tui::sleep(0.1);
        }
    });
    $graph = function (CanvasContext $c) use ($s) {
        $c->lineWidth = 0.25;                 // one Braille dot
        $c->beginPath();
        foreach ($s->samples as $i => $v) {
            $c->lineTo($i * $c->width() / 60, $c->height() * (1 - $v / 100));
        }
        $c->stroke();
    };
    Tui::run(fn() => new Column([
        new Row([new Spinner('working'), new Text(fn() => sprintf('%d%%', $s->progress * 100), class: 'grow text-right text-muted')], class: 'px-1'),
        new Row([
            new Box(new Select(items: ['apple' => 'Apple', 'pear' => 'Pear', 'plum' => 'Plum'], onSelect: fn(string $key) => $s->fruit = $key), 'select', class: 'w-20'),
            new Box(new Column([
                new ProgressBar(fn() => $s->progress, label: 'progress {percent}'),
                new Editor(placeholder: 'type and press Enter', maxLines: 1, onSubmit: fn(string $text, Editor $e) => [$log->append("you typed: $text\n"), $e->setValue('')]),
                new Checkbox('Loud', onChange: fn(bool $on) => $s->loud = $on),
                new Button('Quit…', function () use ($log) {
                    \fiber\await(Dialog::open(new Confirm('Quit the kitchen sink?'))) ? Tui::quit() : $log->append("stayed\n");
                }),
            ], gap: 1), fn() => "widgets · {$s->fruit}" . ($s->loud ? ' · <b>loud</b>' : ''), class: 'grow'),
        ], class: 'h-10'),
        new Row([
            new Box(new Canvas(draw: $graph, marker: 'braille'), 'canvas', class: 'w-1/2'),
            new Box($log, 'log', class: 'grow'),
        ], class: 'grow'),
        new Help(),
    ]), keys: ['ctrl+l' => [fn() => $log->append(date('H:i:s') . " hello\n"), 'log a line']]);
});
