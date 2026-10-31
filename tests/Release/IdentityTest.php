<?php

/*
 * TODO-0.0.3-beta3 item 2: a component instance kept across closure renders keeps its
 * identity, state and surface wherever it appears in the next tree; instances that left the
 * tree are stopped; new instances are new. The reported shape: Row([Text, $bars[$i] ??= new
 * ProgressBar(fn() => $s->p[$i])]) per job plus a total row with a ProgressBar made anew on
 * every render; dropping row a made row b show the total's 50%.
 */

use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;
use phasync\Tui\Tui;

/**
 * Jobs $names with values $values, shown as rows "name: [bar]" and a total row whose bar is made
 * anew on every render; $change(State) changes what is shown. Checks every bar shows its own
 * job's value after the change, on the screen and in the frozen history.
 *
 * @param list<string> $names
 */
function keptBars(array $names, \Closure $change, bool $inline): array
{
    $result = [];
    \fiber\run(function () use ($names, $change, $inline, &$result) {
        $values = [];
        foreach ($names as $i => $name) {
            $values[$name] = round(($i + 1) / (\count($names) + 1), 2);
        }
        $s = new State(['open' => $names, 'p' => $values]);
        $bars = [];
        $terminal = Tui::headless(function () use ($s, &$bars) {
            $rows = [];
            foreach ($s->open as $name) {
                $rows[] = new Row([new Text("$name:", width: 3), $bars[$name] ??= new ProgressBar(fn() => $s->p[$name], label: "job $name")]);
            }
            $total = array_sum(array_map(fn($n) => $s->p[$n], $s->open)) / max(1, \count($s->open));
            $rows[] = new Row([new Text('all:', width: 4), new ProgressBar($total, label: 'total')]);
            return new Column($rows);
        }, 50, 14, inline: $inline);
        $terminal->run(function (FakeTerminal $t) use ($s, $change, $inline, &$bars, &$result) {
            $t->settle();
            $s->p = array_map(fn(float $v) => $v + 0.01, $s->p);       // the jobs make progress: renders follow
            $t->settle();
            $before = array_map(fn(ProgressBar $b) => [$b, $b->surface], $bars);
            $change($s, $bars, $t);
            $t->settle();
            $result = ['text' => $t->text(), 'bars' => $bars, 'before' => $before, 'open' => $s->open, 'p' => $s->p,
                'frozen' => $inline ? $t->frozen() : '', 'mounted' => array_map(fn(ProgressBar $b) => $b->isMounted(), $bars),
                'lines' => array_map(fn(ProgressBar $b) => $b->surface->line(0), $bars)];
        });
    });
    return $result;
}

/** The row of the screen that shows job $name. */
function jobRow(string $text, string $name): ?string
{
    foreach (explode("\n", $text) as $line) {
        if (str_starts_with($line, "$name:")) {
            return $line;
        }
    }
    return null;
}

dataset('modes', ['full screen' => false, 'inline' => true]);

dataset('changes', [
    'two jobs, drop the first' => [['a', 'b'], fn(State $s) => $s->open = ['b']],
    'eight jobs, drop the first' => [range('a', 'h'), fn(State $s) => $s->open = array_slice(range('a', 'h'), 1)],
    'eight jobs, drop the middle' => [range('a', 'h'), fn(State $s) => $s->open = array_values(array_diff(range('a', 'h'), ['d', 'e']))],
    'two jobs, drop the last' => [['a', 'b'], fn(State $s) => $s->open = ['a']],
    'eight jobs, drop the last two' => [range('a', 'h'), fn(State $s) => $s->open = range('a', 'f')],
    'eight jobs, reorder' => [range('a', 'h'), fn(State $s) => $s->open = ['h', 'c', 'a', 'g', 'b', 'f', 'e', 'd']],
    'eight jobs, drop several, reorder the rest' => [range('a', 'h'), function (State $s) {
        $s->open = ['b', 'c', 'f', 'g'];
        \fiber\sleep(0.05);
        $s->open = ['g', 'c', 'b'];
    }],
]);

it('keeps each kept bar showing its own job wherever its row moves', function (array $names, \Closure $change, bool $inline) {
    $r = keptBars($names, $change, $inline);
    foreach ($r['open'] as $name) {
        $row = jobRow($r['text'], $name);
        $percent = (int) round($r['p'][$name] * 100) . '%';
        expect($row)->not->toBeNull()
            ->and($row)->toContain("job $name")->toContain($percent);
        // the instance the program kept is the one shown, with its own surface and value
        [$bar, $surface] = $r['before'][$name] ?? [$r['bars'][$name], $r['bars'][$name]->surface];
        expect($r['bars'][$name])->toBe($bar)
            ->and($r['mounted'][$name])->toBeTrue()
            ->and($bar->surface)->toBe($surface)
            ->and($r['lines'][$name])->toContain("job $name")->toContain($percent);
    }
    $total = array_sum(array_map(fn($n) => $r['p'][$n], $r['open'])) / count($r['open']);
    expect(jobRow($r['text'], 'all'))->toContain('total')->toContain((int) round($total * 100) . '%');
    // the bars of the jobs that left the tree were stopped
    foreach (array_diff($names, $r['open']) as $gone) {
        expect($r['mounted'][$gone])->toBeFalse();
    }
})->with('changes')->with('modes');

it('freezes the kept bar\'s own row after rows above it left the tree', function (bool $inline) {
    $r = keptBars(['a', 'b'], function (State $s, array $bars, FakeTerminal $t) {
        $s->open = ['b'];
        $t->settle();
        $bars['b']->setValue(1.0);
        $t->settle();
        $t->freeze($bars['b']->surface); 
        $s->open = [];
    }, $inline);
    if ($inline) {
        expect($r['frozen'])->toContain('job b')->toContain('100%')
            ->and($r['frozen'])->not->toContain('total');
    }
    expect($r['mounted']['b'])->toBeFalse();
})->with('modes');

it('keeps a kept instance with a key from its first frame on', function (bool $inline) {
    \fiber\run(function () use ($inline) {
        $s = new State(['open' => ['a', 'b']]);
        $bars = [];
        $terminal = Tui::headless(function () use ($s, &$bars) {
            $rows = [];
            foreach ($s->open as $name) {
                $rows[] = new Row([new Text("$name:", width: 3), $bars[$name] ??= new ProgressBar($name === 'a' ? 0.25 : 0.75, label: "job $name", key: "job-$name")]);
            }
            $rows[] = new Row([new Text('all:', width: 4), new ProgressBar(0.5, label: 'total')]);
            return new Column($rows);
        }, 50, 8, inline: $inline);
        $terminal->run(function (FakeTerminal $t) use ($s, &$bars) {
            $t->settle();
            $s->open = ['a'];               // right after the first frame
            $t->settle();
            expect($bars['b']->isMounted())->toBeFalse()
                ->and($bars['a']->isMounted())->toBeTrue()
                ->and(jobRow($t->text(), 'a'))->toContain('job a')->toContain('25%')
                ->and(jobRow($t->text(), 'all'))->toContain('total')->toContain('50%');
        });
    });
})->with('modes');

it('says what to do when a stopped instance is placed again', function () {
    $error = null;
    try {
        \fiber\run(function () {
            $s = new State(['show' => true]);
            $bar = new ProgressBar(0.5);
            $terminal = Tui::headless(fn() => new Column([new Text('x'), $s->show ? $bar : null]), 40, 5);
            $terminal->run(function (FakeTerminal $t) use ($s) {
                $t->settle();
                $s->show = false;
                $t->settle();
                $s->show = true;
                $t->settle();
            });
        });
    } catch (\LogicException $e) {
        $error = $e->getMessage();
    }
    expect($error)->toContain('ProgressBar')->toContain('left the tree')->toContain('new instance');
});

it('reaches the components of a closure program by key through the device', function () {
    \fiber\run(function () {
        $s = new State(['n' => 1]);
        $terminal = Tui::headless(fn() => new Column([
            new Text("n = {$s->n}"),
            new ProgressBar($s->n / 10, key: 'progress'),
        ]), 40, 5);
        $terminal->run(function (FakeTerminal $t) use ($s) {
            $t->settle();
            $bar = $t->component()->children()['progress'];
            expect($bar)->toBeInstanceOf(ProgressBar::class)->and($bar->value)->toBe(0.1);
            $s->n = 5;
            $t->settle();
            expect($t->component()->children()['progress'])->toBe($bar)->and($bar->value)->toBe(0.5);
        });
    });
});
