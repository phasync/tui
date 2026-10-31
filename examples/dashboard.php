<?php

// The sixty-line dashboard: three jobs updated by coroutines, their events and the release
// notes, styled with utility classes only, stacked under 80 columns. Ctrl-Q quits.
//     php examples/dashboard.php [--inline] [--theme=dark|light|mono]

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component\{Log, Markdown, ProgressBar, Spinner};
use phasync\Tui\Element\{Box, Column, Help, Row, Text};
use phasync\Tui\{State, Tui};

$jobs = ['build' => 0.07, 'test' => 0.15, 'deploy' => 0.4];          // seconds per step
$s = new State(['done' => array_map(fn() => 0.0, $jobs), 'runs' => array_map(fn() => 0, $jobs), 'steps' => array_map(fn() => [], $jobs)]);
$log = new Log();
$notes = new Markdown(<<<'MD'
    # Release 2.4
    - **Faster** start-up, half the memory
    - `--json` output for every command
    - Wide characters line up: 日本語 ✓
    > Thanks to everyone who reported bugs!
    MD);
$spark = fn(array $steps) => implode('', array_map(fn(float $v) => mb_substr(' ▁▂▃▄▅▆▇█', (int) min(8, $v * 9), 1), $steps));
$box = fn(string $job) => new Box(new Column([
    new ProgressBar(fn() => $s->done[$job], label: '{percent}'),
    new Text(fn() => "<dim>run</dim> {$s->runs[$job]}  <span class=\"text-accent\">{$spark($s->steps[$job])}</span>"),
]), "<b>$job</b>", class: 'grow px-1 hover:border-accent');
$boxes = fn() => new Row(array_map($box, array_keys($jobs)), class: 'flex-col md:flex-row md:gap-1');
$screen = fn() => new Column([
    new Row([new Spinner('<b>deploying</b> v2.4'), new Text('staging · eu-west', class: 'grow text-right text-muted')], class: 'px-1'),
    $boxes(),
    new Row([new Box($log, 'events', class: 'grow'), new Box($notes, 'release notes', class: 'grow px-1')], class: 'grow flex-col md:flex-row md:gap-1'),
    new Help(class: 'px-1'),
]);

$theme = ($t = preg_grep('/^--theme=/', $argv)) ? substr(reset($t), 8) : null;
\fiber\run(function () use ($jobs, $s, $log, $screen, $boxes, $theme, $argv) {
    $running = true;
    foreach ($jobs as $job => $every) {
        Tui::go(function () use ($job, $every, $s, $log, &$running) {
            while ($running) {
                \fiber\sleep($every * (0.5 + mt_rand() / mt_getrandmax()));
                $step = mt_rand(2, 12) / 100;
                $s->steps = [...$s->steps, $job => [...array_slice($s->steps[$job], -15), $step * 8]];
                $s->done = [...$s->done, $job => min(1.0, $s->done[$job] + $step)];
                if ($s->done[$job] >= 1.0) {
                    $s->runs = [...$s->runs, $job => $s->runs[$job] + 1];
                    $log->append(date('H:i:s') . " <span class=\"text-success\">✓</span> $job #{$s->runs[$job]} done\n");
                    $s->done = [...$s->done, $job => 0.0];
                }
            }
        });
    }
    try {
        in_array('--inline', $argv, true) ? Tui::inline($boxes, theme: $theme) : Tui::run($screen, theme: $theme);
    } finally {
        $running = false;       // the jobs end with the screen, so fiber\run() returns
    }
});
