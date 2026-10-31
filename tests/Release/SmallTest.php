<?php

/*
 * TODO-0.0.3-beta2 item 6: small things from use.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Spinner;
use phasync\Tui\Component\Table;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;
use phasync\Tui\Style;
use phasync\Tui\Tui;

it('reads markup in a Spinner label', function () {
    \fiber\run(function () {
        $t = Tui::headless(fn() => new Spinner('saving <b>report.pdf</b>'), 30, 2);
        $t->settle();
        expect(screenRows($t)[0])->toEndWith(' saving report.pdf')
            ->and($t->surface->cell(9, 0)->style->has(Style::BOLD))->toBeTrue()
            ->and($t->surface->cell(2, 0)->style->has(Style::BOLD))->toBeFalse();
        $t->detach();
    });
});

it('takes a closure for a Button label, drawn again when a State it read changes; $b->label = ... still works', function () {
    \fiber\run(function () {
        $s = new State(['n' => 1]);
        $button = new Button(fn() => "Run ({$s->n})");
        $t = Tui::headless(new Column([$button, new Text('below')]), 30, 3);
        $t->settle();
        expect(screenRows($t)[0])->toBe('[ Run (1) ]');
        $s->n = 12;
        $t->settle();
        expect(screenRows($t)[0])->toBe('[ Run (12) ]');
        $button->label = 'Stop';
        $button->requestRender();
        $t->settle();
        expect(screenRows($t)[0])->toBe('[ Stop ]');
        $t->detach();
    });
});

it('shows the class and message of an error in a closure content, and keeps a Log line appended before it is shown', function () {
    \fiber\run(function () {
        $t = Tui::headless(fn() => new Column([
            new Text('above'),
            new Text(fn() => throw new \TypeError('Argument #1 must be of type int')),
            new Text('below'),
        ]), 50, 3);
        $t->settle();
        expect(screenRows($t)[1])->toBe('✖ TypeError: Argument #1 must be of type int')
            ->and(screenRows($t)[2])->toBe('below');
        $t->detach();
    });
    \fiber\run(function () {
        // a narrow one: as much as fits, cut with …
        $t = Tui::headless(fn() => new Text(fn() => throw new \RuntimeException('the disk is full, nothing was saved')), 24, 1);
        $t->settle();
        expect(screenRows($t)[0])->toStartWith('✖ RuntimeException:')->toEndWith('…');
        $t->detach();
    });
    \fiber\run(function () {
        $log = new Log();
        $log->append("started\n");
        $log->append('second');
        $log->setWordWrap(true);
        $t = Tui::headless(new Column([new Text('log'), $log]), 20, 4);
        $t->settle();
        expect(screenRows($t))->toBe(['log', 'started', 'second', ''])->and($log->lines())->toBe(['started', 'second'])
            ->and($log->wordWrap())->toBeTrue();
        $log->append(" line\nthird");
        $t->settle();
        expect(screenRows($t))->toBe(['log', 'started', 'second line', 'third']);
        $t->detach();
    });
});

it('evaluates a ProgressBar value closure as often as its State changes but draws only when the bar looks different', function () {
    \fiber\run(function () {
        $s = new State(['v' => 0.5]);
        $calls = 0;
        $bar = new ProgressBar(function () use ($s, &$calls) { $calls++; return $s->v; });
        $t = Tui::headless(new Column([$bar]), 24, 1);
        $t->settle();
        $drawn = $bar->surface->version();
        for ($i = 1; $i <= 20; $i++) {
            $s->v = 0.5 + $i / 10000;       // the same cells, the same percentage
            \fiber\sleep(1 / 60 + 0.002);
        }
        expect($calls)->toBeGreaterThan(10)->and($bar->surface->version())->toBe($drawn);
        $s->v = 0.9;
        $t->settle();
        expect($bar->surface->version())->toBeGreaterThan($drawn)->and(screenRows($t)[0])->toEndWith('90%');
        $t->detach();
    });
});

final class ClosableTabs extends Component
{
    public ?Tabs $tabs = null;

    protected function render(): Element
    {
        $this->tabs ??= $this->child('tabs', fn() => new Tabs(closable: true));
        // the same tabs on every render, as a program writes it
        $this->tabs->setTabs([
            'a' => ['label' => 'Alpha', 'content' => new Text('alpha')],
            'b' => ['label' => 'Beta', 'content' => new Text('beta')],
            'c' => ['label' => 'Gamma', 'content' => new Text('gamma')],
        ]);
        return $this->tabs;
    }
}

it('keeps a tab the user closed closed while render() goes on listing it', function () {
    $app = new ClosableTabs();
    mountOn($app, 40, 4, function (FakeTerminal $t, ClosableTabs $app) {
        $t->settle();
        expect($app->tabs->tabs())->toBe(['a', 'b', 'c']);
        $app->tabs->focus();
        $t->press('right', 'delete');      // close Beta
        $t->settle();
        expect($app->tabs->tabs())->toBe(['a', 'c'])->and($t->text())->not->toContain('Beta');
        $app->requestRender();              // render() lists it again
        $t->settle();
        expect($app->tabs->tabs())->toBe(['a', 'c'])->and($t->text())->not->toContain('Beta');
        // named as current: open again
        $app->tabs->setTabs(['a' => ['label' => 'Alpha', 'content' => new Text('alpha')], 'b' => ['label' => 'Beta', 'content' => new Text('beta')]], current: 'b');
        $t->settle();
        expect($app->tabs->tabs())->toBe(['a', 'b'])->and($app->tabs->current())->toBe('b');
    });
});

it('does not measure a Select again for the same rows built anew on every render', function () {
    \fiber\run(function () {
        $s = new State(['tick' => 0]);
        $select = null;
        $t = Tui::headless(function () use ($s, &$select) {
            $rows = [];
            for ($i = 0; $i < 500; $i++) {
                $rows["k$i"] = ["item $i", (string) ($i * 3)];
            }
            $select ??= new Select(items: $rows, columns: ['Name' => 'auto', 'Size' => 'auto']);
            $select->setItems($rows);
            return new Column([new Text("tick {$s->tick}"), $select]);
        }, 40, 10);
        $t->settle();
        $measured = $select->measuredCells();
        for ($i = 1; $i <= 5; $i++) {
            $s->tick = $i;
            $t->settle();
        }
        expect(screenRows($t)[0])->toBe('tick 5')->and($select->measuredCells())->toBe($measured);
        $select->setItems(['x' => ['other', '1']]);
        $t->settle();
        expect($select->measuredCells())->toBeGreaterThan($measured);
        $t->detach();
    });
});

it('does not measure a Table again for the same rows given anew as props by every render', function () {
    \fiber\run(function () {
        $s = new State(['tick' => 0, 'rows' => 500]);
        $first = null;
        $t = Tui::headless(function () use ($s, &$first) {
            $rows = [];
            for ($i = 0; $i < $s->rows; $i++) {
                $rows["k$i"] = ["item $i", (string) ($i * 3)];
            }
            $table = new Table(columns: ['Name' => 'auto', 'Size' => 'auto'], rows: $rows);
            $first ??= $table;      // (the instance that runs: later ones hand it their props)
            return new Column([new Text("tick {$s->tick}"), $table]);
        }, 40, 10);
        $t->settle();
        $measured = $first->measuredCells();
        expect($measured)->toBe(1000);
        for ($i = 1; $i <= 5; $i++) {
            $s->tick = $i;
            $t->settle();
        }
        expect(screenRows($t)[0])->toBe('tick 5')->and($first->measuredCells())->toBe($measured);
        $s->rows = 3;
        $t->settle();
        expect($first->measuredCells())->toBe($measured + 6);
        $t->detach();
    });
});

it('makes a Row as high as text needs where it wraps in its share of the width, also as it grows', function () {
    \fiber\run(function () {
        $s = new State(['t' => 'short']);
        $t = Tui::headless(fn() => new Column([
            new \phasync\Tui\Element\Row([
                new \phasync\Tui\Element\Box(new Column([new Text('bar'), new Text(fn() => $s->t)]), 'b', class: 'grow'),
                new \phasync\Tui\Element\Box(new Text('x'), 'c', class: 'grow'),
            ]),
            new Text('below'),
        ]), 20, 12);
        $t->settle();
        expect(screenRows($t)[4])->toBe('below');
        $s->t = 'a long text that wraps over lines';
        $t->settle();
        expect(array_slice(screenRows($t), 2, 7))->toBe(['│a long  ││        │', '│text    ││        │', '│that    ││        │', '│wraps   ││        │', '│over    ││        │', '│lines   ││        │', '╰────────╯╰────────╯'])
            ->and(screenRows($t)[9])->toBe('below');
        $t->detach();
    });
});
