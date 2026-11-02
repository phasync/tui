<?php

/*
 * Sparkline, Rule, Slider, RadioGroup, through FakeTerminal.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\RadioGroup;
use phasync\Tui\Component\Rule;
use phasync\Tui\Component\Slider;
use phasync\Tui\Component\Sparkline;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;

final class ParityScreen extends Component
{
    public function __construct(private Closure $build)
    {
    }

    protected function render(): Element
    {
        return ($this->build)($this);
    }

    public function make(string $key, Closure $create): Component
    {
        return $this->child($key, $create);
    }
}

it('scales a Sparkline to its largest value, right-aligned, newest last, gaps blank', function () {
    expect(Sparkline::bars([0, 1, 2, 4, 8], 5))->toBe('▁▂▃▅█');
    expect(Sparkline::bars([1, 2, 3, 4], 2))->toBe('▆█');
    expect(Sparkline::bars([5, null, 10], 3, 10))->toBe('▅ █');
    expect(Sparkline::bars([0, 0], 2))->toBe('▁▁');
    $app = new ParityScreen(fn() => new Column([new Sparkline([1, 8], class: 'h-1')]));
    mountOn($app, 6, 1, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t)[0])->toBe('    ▂█');
    });
});

it('draws a Rule with and without a title', function () {
    $app = new ParityScreen(fn() => new Column([new Rule(), new Rule('Hi'), new Rule('Hi', align: 'center', char: '=')]));
    mountOn($app, 12, 3, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t))->toBe(['────────────', '── Hi ──────', '==== Hi ====']);
    });
});

it('moves a Slider with keys and the mouse and reports changes', function () {
    $seen = new ArrayObject();
    $app = new ParityScreen(fn(ParityScreen $s) => new Column([
        $s->make('v', fn() => new Slider(0, 10, value: 5, step: 1, onChange: fn($v) => $seen[] = $v)),
    ]));
    mountOn($app, 14, 2, function (FakeTerminal $t, ParityScreen $app) use ($seen) {
        $t->settle();
        $slider = $app->children()['v'];
        $slider->focus();
        $t->press('right', 'right', 'left', 'home', 'end', 'pagedown');
        expect($slider->value())->toBe(0.0);
        $t->settle();
        $t->click(0, 0);
        expect($slider->value())->toBe(0.0);
        $t->click(11, 0);                  // track is cols 0..10 (width 14 - 3 for " 10")
        expect($slider->value())->toBe(10.0);
        expect($seen->getArrayCopy())->toBe([6.0, 7.0, 6.0, 0.0, 10.0, 0.0, 10.0]);
        $slider->setValue(99);
        expect($slider->value())->toBe(10.0);
    });
});

it('picks in a RadioGroup with arrows, Space and a click; arrows at the ends go on', function () {
    $seen = new ArrayObject();
    $app = new ParityScreen(fn(ParityScreen $s) => new Column([
        $s->make('r', fn() => new RadioGroup(['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'], value: 'm', onChange: fn($k) => $seen[] = $k)),
    ]));
    mountOn($app, 20, 4, function (FakeTerminal $t, ParityScreen $app) use ($seen) {
        $t->settle();
        expect(screenRows($t)[0])->toBe('( ) Small');
        expect(screenRows($t)[1])->toBe('(•) Medium');
        $r = $app->children()['r'];
        $r->focus();
        $t->press('down', 'down');          // second press: at the end, the key goes on
        expect($r->value())->toBe('l');
        $t->press('up', 'up');
        expect($r->value())->toBe('s');
        $t->click(2, 1);
        expect($r->value())->toBe('m');
        expect($seen->getArrayCopy())->toBe(['l', 'm', 's', 'm']);
    });
});

it('draws a horizontal BarChart scaled to the largest value', function () {
    $app = new ParityScreen(fn() => new Column([new Component\BarChart(['a' => 4, 'bb' => 8])]));
    mountOn($app, 14, 2, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t))->toBe(['a  ████▄     4', 'bb █████████ 8']);
    });
});

it('draws a vertical BarChart with values above and labels below', function () {
    $app = new ParityScreen(fn() => new Column([new Component\BarChart(['x' => 1, 'y' => 2], vertical: true)]));
    mountOn($app, 8, 4, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t))->toBe([' 1   2', '    ███', '███ ███', ' x   y']);
    });
});

it('moves a Calendar by day and month, picks a date, and counts the weeks from Monday', function () {
    $picked = new ArrayObject();
    $app = new ParityScreen(fn(ParityScreen $s) => new Column([
        $s->make('c', fn() => new Component\Calendar('2026-11-02', onSelect: fn($d) => $picked[] = $d)),
    ]));
    mountOn($app, 22, 9, function (FakeTerminal $t, ParityScreen $app) use ($picked) {
        $t->settle();
        $rows = screenRows($t);
        expect($rows[0])->toBe('‹  November 2026   ›');
        expect($rows[1])->toBe('Mo Tu We Th Fr Sa Su');
        expect($rows[2])->toBe('26 27 28 29 30 31  1');
        expect($rows[3])->toBe(' 2  3  4  5  6  7  8');
        $c = $app->children()['c'];
        $c->focus();
        $t->press('down', 'right', 'enter');
        expect($c->value())->toBe('2026-11-10');
        $t->press('pagedown');
        expect($c->current())->toBe('2026-12-10');
        $t->press('end');
        expect($c->current())->toBe('2026-12-31');
        $t->press('pagedown');
        expect($c->current())->toBe('2027-01-31');
        $t->press('pageup', 'pageup');
        expect($c->current())->toBe('2026-11-30');   // 30 Nov, not an overflowed 31 -> 1 Dec
        $t->settle();
        $t->click(0, 3);                              // the 2nd of November (row 3, column 0)
        expect($picked->getArrayCopy())->toBe(['2026-11-10', '2026-11-02']);
    });
});
