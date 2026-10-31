<?php

/*
 * TODO-0.0.3-beta6 item 3: a one-file program with a lane ScrollView of 200 Text cards whose
 * `selected:` is set from a State by arrow keys bound in `keys:`: the highlight moved but the
 * view stayed at the top. A ScrollView now brings the element that becomes selected into view
 * (as little as shows it, as for the focus), and `focused:` gives an element (or a component)
 * the focus from a program's state.
 */

use phasync\Tui\Component\Button;
use phasync\Tui\Component\ScrollView;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;
use phasync\Tui\Tui;

/** The lane: 200 cards in a ScrollView in a Box of 10 rows inside, the arrows bound in keys:. */
function selectedLane(State $s, bool $focused = false): FakeTerminal
{
    return Tui::headless(function () use ($s, $focused) {
        $cards = [];
        $title = $focused ? "lane {$s->tick}" : 'lane';     // (a render the test can ask for)
        for ($i = 0; $i < 200; $i++) {
            $cards[] = $focused
                ? new Text("card $i", class: 'focus:inverse', focused: $s->sel === $i, onKey: [
                    'down' => fn() => $s->sel = min(199, $s->sel + 1),
                    'up' => fn() => $s->sel = max(0, $s->sel - 1),
                    'x' => fn() => $s->hit = $i,
                ])
                : new Text("card $i", class: 'selected:inverse', selected: $s->sel === $i);
        }
        return new Box(new ScrollView(new Column($cards)), $title);
    }, 30, 12, keys: $focused ? [] : [
        'down' => fn() => $s->sel = min(199, $s->sel + 1),
        'up' => fn() => $s->sel = max(0, $s->sel - 1),
    ]);
}

/** The cards shown in the lane, top to bottom. */
function laneCards(FakeTerminal $t): array
{
    preg_match_all('/card (\d+)/', $t->text(), $m);
    return array_map('intval', $m[1]);
}

it('brings the card that becomes selected into view, scrolling as little as shows it', function () {
    fiber\run(function () {
        $s = new State(['sel' => 0]);
        $t = selectedLane($s);
        $t->settle();
        expect(laneCards($t))->toBe(range(0, 9));
        $s->sel = 50;
        $t->settle();
        expect(laneCards($t))->toBe(range(41, 50));         // (at the bottom edge: the least scroll)
        $s->sel = 0;
        $t->settle();
        expect(laneCards($t))->toBe(range(0, 9));           // (back at the top)
        $t->press(...array_fill(0, 9, 'down'));
        $t->settle();
        expect(laneCards($t))->toBe(range(0, 9));           // (card 9 is the last row: no scroll yet)
        $t->press('down');
        $t->settle();
        expect(laneCards($t))->toBe(range(1, 10));          // (one row at the edge scrolls one row)
        $t->press('up');
        $t->settle();
        expect(laneCards($t))->toBe(range(1, 10));          // (card 9 is in view: no scroll)
        $t->detach();
    });
});

it('lets the wheel scroll away from the selected card until another is selected', function () {
    fiber\run(function () {
        $s = new State(['sel' => 0]);
        $t = selectedLane($s);
        $t->settle();
        $t->scroll(5, 5, 3);
        $t->settle();
        expect(laneCards($t)[0])->toBeGreaterThan(0);
        $s->sel = 1;
        $t->settle();
        expect(laneCards($t)[0])->toBe(1);
        $t->detach();
    });
});

it('gives the focus to the element whose focused: became true, and not back after Tab moved it', function () {
    fiber\run(function () {
        $s = new State(['sel' => 3, 'hit' => null, 'tick' => 0]);
        $t = selectedLane($s, focused: true);
        $t->settle();
        $t->press('x');
        expect($s->hit)->toBe(3);                         // the card focused: true has the keys from the start
        $t->settle();
        $t->press(...array_fill(0, 20, 'down'));          // its onKey moves sel; the focus follows, and the view
        $t->settle();
        $t->press('x');
        expect($s->sel)->toBe(23)->and($s->hit)->toBe(23)->and(laneCards($t))->toContain(23);
        $t->settle();
        $t->press('tab');                                 // the user moves the focus on
        $t->settle();
        $t->press('x');
        expect($s->hit)->toBe(24);
        $t->settle();
        $s->tick = 1;       // (a render with sel still 23: the focus stays where Tab put it)
        $t->settle();
        expect($t->text())->toContain('lane 1');
        $t->press('x');
        expect($s->hit)->toBe(24);
        $t->detach();
    });
});

it('focuses a component given focused: true', function () {
    fiber\run(function () {
        $s = new State(['which' => 'b', 'pressed' => null]);
        $t = Tui::headless(fn() => new Row([
            (new Button('A', fn() => $s->pressed = 'a'))->with(focused: $s->which === 'a'),
            (new Button('B', fn() => $s->pressed = 'b'))->with(focused: $s->which === 'b'),
        ]), 30, 3);
        $t->settle();
        $t->press('enter');
        expect($s->pressed)->toBe('b');
        $s->which = 'a';
        $t->settle();
        $t->press('enter');
        expect($s->pressed)->toBe('a');
        $t->detach();
    });
});
