<?php

/*
 * TODO-0.0.3-beta2 item 5: variants on elements. selected: and state: put an element in a
 * state; hover: follows the mouse on any element; a component's border colour goes to the Box
 * it renders; onFocus/onBlur closures follow the focus without polling.
 */

use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Component\Checkbox;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;
use phasync\Tui\State;
use phasync\Tui\Style;
use phasync\Tui\Stylesheet;
use phasync\Tui\Tui;

it('puts an element in the selected state with selected:, and in states of its own with state:', function () {
    \fiber\run(function () {
        $s = new State(['sel' => 'b']);
        $t = Tui::headless(fn() => new Column(array_map(
            fn(string $id) => new Text("<b>Card $id</>", class: 'selected:bg-sky-700 selected:italic', selected: $id === $s->sel),
            ['a', 'b', 'c'],
        )), 20, 4);
        $t->settle();
        $sky = Color::parse('sky-700');
        expect($t->surface->cell(0, 1)->style->bg)->toBe($sky)->and($t->surface->cell(0, 1)->style->has(Style::ITALIC | Style::BOLD))->toBeTrue()
            ->and($t->surface->cell(0, 0)->style->bg)->toBe(Color::DEFAULT)->and($t->surface->cell(0, 0)->style->has(Style::ITALIC))->toBeFalse();
        $s->sel = 'c';
        $t->settle();
        expect($t->surface->cell(0, 2)->style->bg)->toBe($sky)->and($t->surface->cell(0, 1)->style->bg)->toBe(Color::DEFAULT);
        $t->detach();
    });
    $sheet = Stylesheet::default()->with(['Text:done' => ['strike' => true]]);
    $done = new Text('x', state: ['done']);
    expect($sheet->compute($done)->text->has(Style::STRIKE))->toBeTrue()
        ->and($sheet->compute(new Text('x'))->text->has(Style::STRIKE))->toBeFalse()
        ->and($done->with(selected: true)->attribute('state'))->toBe(['done', 'selected'])
        ->and($done->with(selected: false)->attribute('state'))->toBe(['done']);
});

it('follows the mouse for a hover: class on a Box element: its border changes under the pointer', function () {
    \fiber\run(function () {
        $t = Tui::headless(fn() => new Column([
            new Box(new Text('one'), 'a', class: 'hover:border-emerald-500'),
            new Box(new Text('two'), 'b', class: 'hover:border-emerald-500'),
        ]), 20, 6);
        $t->settle();
        $emerald = Color::parse('emerald-500');
        expect($t->surface->cell(0, 0)->style->fg)->not->toBe($emerald)
            ->and($t->output())->toContain("\e[?1003h");          // it asked for motion reports
        $t->mouse(new Mouse(Mouse::MOVE, 3, 1));
        $t->settle();
        expect($t->surface->cell(0, 0)->style->fg)->toBe($emerald)->and($t->surface->cell(0, 3)->style->fg)->not->toBe($emerald);
        $t->mouse(new Mouse(Mouse::MOVE, 3, 4));
        $t->settle();
        expect($t->surface->cell(0, 0)->style->fg)->not->toBe($emerald)->and($t->surface->cell(0, 3)->style->fg)->toBe($emerald);
        $t->mouse(new Mouse(Mouse::MOVE, 19, 5));
        $t->settle();
        expect($t->surface->cell(0, 3)->style->fg)->not->toBe($emerald);
        $t->detach();
    });
});

final class BorderedCard extends Component
{
    protected function render(): Element
    {
        return new Box(new Text('card'), 'card');
    }
}

it('gives the border colour of a component to the Box it renders when the Box sets none', function () {
    \fiber\run(function () {
        $red = Color::parse('red-500');
        $t = Tui::headless(fn() => new Column([
            (new BorderedCard())->with(class: 'border-red-500'),
            (new BorderedCard())->with(class: 'hover:border-red-500'),
            new Box(new BorderedCard(), class: 'border-red-500'),
        ]), 20, 9);
        $t->settle();
        // the component draws no border of its own: its Box is red
        expect($t->text())->toStartWith("╭ card ")->and(substr_count($t->text(), '╭'))->toBe(4)
            ->and($t->surface->cell(0, 0)->style->fg)->toBe($red)
            ->and($t->surface->cell(0, 3)->style->fg)->not->toBe($red);        // hover: not under the mouse
        $t->mouse(new Mouse(Mouse::MOVE, 3, 4));
        $t->settle();
        expect($t->surface->cell(0, 3)->style->fg)->toBe($red);
        // a Box with a colour of its own keeps it; the one inside it takes the theme's
        expect($t->surface->cell(0, 6)->style->fg)->toBe($red)->and($t->surface->cell(1, 7)->style->fg)->not->toBe($red);
        $t->detach();
    });
    // a Box's own class wins over the component's colour
    $sheet = Stylesheet::default();
    $card = $sheet->compute(new Text('x', class: 'border-red-500'));
    expect($sheet->compute(new Box(new Text('x'), class: 'border-sky-500'), [], $card)->borderStyle->fg)->toBe(Color::parse('sky-500'))
        ->and($sheet->compute(new Box(new Text('x')), [], $card)->borderStyle->fg)->toBe(Color::parse('red-500'))
        ->and($card->border)->toBeFalse();
});

it('calls onFocus and onBlur of elements and components as the focus moves, without polling', function () {
    \fiber\run(function () {
        $s = new State(['current' => null, 'log' => []]);
        $t = Tui::headless(fn() => new Column(array_map(fn(string $id) => new Row(
            [new Text($id, width: 4), new Checkbox("item $id")],
            onFocus: function () use ($s, $id) { $s->current = $id; $s->log = [...$s->log, "+$id"]; },
            onBlur: function () use ($s, $id) { $s->log = [...$s->log, "-$id"]; },
        ), ['a', 'b', 'c'])), 30, 4);
        $t->settle();
        expect($s->current)->toBe('a')->and($s->log)->toBe(['+a']);         // the first field has the focus
        $t->press('tab');
        $t->settle();
        expect($s->current)->toBe('b')->and($s->log)->toBe(['+a', '-a', '+b']);
        $t->press('tab');
        $t->settle();
        $t->press('tab');
        $t->settle();
        expect($s->current)->toBe('a')->and(array_slice($s->log, -4))->toBe(['-b', '+c', '-c', '+a']);
        // two moves in one frame: what the frame shows, the blur first
        $t->press('tab', 'tab');
        $t->settle();
        expect($s->current)->toBe('c')->and(array_slice($s->log, -2))->toBe(['-a', '+c']);
        $t->detach();
    });
    \fiber\run(function () {
        $seen = [];
        $boxes = [new Checkbox('one'), new Checkbox('two')];
        foreach ($boxes as $i => $box) {
            $box->with(onFocus: function (Checkbox $c) use (&$seen) { $seen[] = "+{$c->label}"; }, onBlur: function (Checkbox $c) use (&$seen) { $seen[] = "-{$c->label}"; });
        }
        $t = Tui::headless(new Column($boxes), 20, 3);
        $t->settle();
        $t->press('tab');
        $t->settle();
        expect($seen)->toBe(['+one', '-one', '+two']);
        $t->detach();
    });
});
