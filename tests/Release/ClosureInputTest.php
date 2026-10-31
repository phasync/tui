<?php

/*
 * TODO-0.0.3-beta3 item 1 (SPEC 9.1 as amended): a closure program receives input. keys: on
 * Tui::run()/inline()/headless(), onKey:/onMouse: on elements (focusable, in the Tab walk, a
 * drag's release with what is under it), Dialog::open()/Confirm::ask() awaitable from a key
 * handler. The test of the TODO: a closure Kanban of three Boxes with Text cards.
 */

use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Form;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\State;
use phasync\Tui\Tui;

/** The board's state: three columns of cards, the selected [column, row], the card being dragged. */
function kanbanState(): State
{
    return new State(['cols' => ['todo' => ['write', 'test'], 'doing' => ['ship'], 'done' => []], 'sel' => [0, 0], 'drag' => null, 'log' => []]);
}

/** Move the selected card $by columns (Shift+arrows). */
function kanbanMove(State $s, int $by): void
{
    [$c, $r] = $s->sel;
    $names = array_keys($s->cols);
    $to = $c + $by;
    if (!isset($names[$to]) || !isset($s->cols[$names[$c]][$r])) {
        return;
    }
    $cols = $s->cols;
    $card = $cols[$names[$c]][$r];
    array_splice($cols[$names[$c]], $r, 1);
    $cols[$names[$to]][] = $card;
    $s->cols = $cols;
    $s->sel = [$to, count($cols[$names[$to]]) - 1];
}

/** The board: a Row of Boxes, a Text per card; a card dragged onto another column moves there. */
function kanbanBoard(State $s): Element
{
    $columns = [];
    $c = 0;
    foreach ($s->cols as $name => $cards) {
        $texts = [];
        foreach ($cards as $r => $card) {
            $texts[] = new Text($card, key: "card-$card", selected: $s->sel === [$c, $r], class: 'selected:inverse',
                onKey: ['delete' => [function () use ($s, $name, $card) {
                    $cols = $s->cols;
                    $cols[$name] = array_values(array_diff($cols[$name], [$card]));
                    $s->cols = $cols;
                }, 'delete card']],
                onMouse: function (Mouse $m) use ($s, $card) {
                    if ($m->action === Mouse::PRESS) {
                        $s->drag = $card;
                    } elseif ($m->action === Mouse::RELEASE && $s->drag === $card) {
                        foreach ($m->over() as $element) {
                            $key = (string) $element->attribute('key');
                            if (str_starts_with($key, 'col-')) {
                                $cols = $s->cols;
                                foreach ($cols as $n => $list) {
                                    $cols[$n] = array_values(array_diff($list, [$card]));
                                }
                                $cols[substr($key, 4)][] = $card;
                                $s->cols = $cols;
                                $s->log = [...$s->log, "dropped $card on " . substr($key, 4)];
                            }
                        }
                        $s->drag = null;
                    }
                });
        }
        $columns[] = new Box(new Column($texts), $name, key: "col-$name", class: 'grow', height: 8);
        $c++;
    }
    return new Column([new Row($columns), new Help()]);
}

/** The keys of the board: arrows select, Shift+arrows move, Enter renames in a dialog. */
function kanbanKeys(State $s): array
{
    $select = function (int $dc, int $dr) use ($s) {
        [$c, $r] = $s->sel;
        $names = array_keys($s->cols);
        $c = max(0, min(count($names) - 1, $c + $dc));
        $r = max(0, min(max(0, count($s->cols[$names[$c]]) - 1), $r + $dr));
        $s->sel = [$c, $r];
    };
    return [
        'up' => fn() => $select(0, -1),
        'down' => fn() => $select(0, 1),
        'left' => fn() => $select(-1, 0),
        'right' => fn() => $select(1, 0),
        'shift+left' => [fn() => kanbanMove($s, -1), 'move left'],
        'shift+right' => [fn() => kanbanMove($s, 1), 'move right'],
        'enter' => [function () use ($s) {
            [$c, $r] = $s->sel;
            $name = array_keys($s->cols)[$c];
            $form = new Form((new Editor(maxLines: 1, placeholder: 'title'))->with(id: 'title'));
            $answer = fiber\await(Dialog::open(new Dialog('Rename', $form, ['OK' => true, 'Cancel' => false])));
            if ($answer && ($title = trim($form->values()['title'])) !== '') {
                $cols = $s->cols;
                $cols[$name][$r] = $title;
                $s->cols = $cols;
            }
            $s->log = [...$s->log, $answer ? 'renamed' : 'kept'];
        }, 'rename'],
    ];
}

/** [x, y] of the first cell of $text on the screen. */
function screenAt(FakeTerminal $t, string $text): array
{
    foreach (explode("\n", $t->text()) as $y => $line) {
        $x = mb_strpos($line, $text);
        if ($x !== false) {
            return [mb_strwidth(mb_substr($line, 0, $x)), $y];
        }
    }
    throw new RuntimeException("'$text' is not on the screen:\n" . $t->text());
}

it('runs a Kanban board without a class: keys, Shift+arrows, a drag between columns, a dialog awaited in a key handler', function () {
    fiber\run(function () {
        $s = kanbanState();
        $t = Tui::headless(fn() => kanbanBoard($s), 100, 14, keys: kanbanKeys($s));
        $t->run(function (FakeTerminal $t) use ($s) {
            $t->settle();
            expect($t->text())->toContain('write')->toContain('ship')
                ->and($t->text())->toContain('shift+left move left')->toContain('enter rename')->toContain('ctrl+q quit');

            $t->press('down');                                 // the arrows select
            expect($s->sel)->toBe([0, 1]);
            $t->press('right', 'shift+left');                  // 'ship' moves to todo
            expect($s->cols)->toBe(['todo' => ['write', 'test', 'ship'], 'doing' => [], 'done' => []])
                ->and($s->sel)->toBe([0, 2]);
            $t->press('shift+right', 'shift+right');           // and on to done
            expect($s->cols['done'])->toBe(['ship'])->and($s->sel)->toBe([2, 0]);
            $t->settle();
            expect(screenRows($t)[1])->toContain('ship');

            // a press on a card and a release over another column moves it there
            [$x, $y] = screenAt($t, 'write');
            [$dx] = screenAt($t, 'doing');
            $t->drag($x, $y, $dx + 2, $y + 5);
            expect($s->cols)->toBe(['todo' => ['test'], 'doing' => ['write'], 'done' => ['ship']])
                ->and($s->log)->toBe(['dropped write on doing']);
            $t->settle();
            [$wx] = screenAt($t, 'write');
            expect($wx)->toBeGreaterThan($dx - 2);

            // Enter: a dialog with a form, awaited in the key handler; the answer lands in the State
            $s->sel = [1, 0];
            $t->press('enter');
            $t->settle();
            expect($t->text())->toContain('Rename')->toContain('OK');
            $t->press('left');                                 // the board's keys wait while the dialog is open
            expect($s->sel)->toBe([1, 0]);
            $t->type('deploy');
            $t->press('enter');
            $t->settle();
            expect($s->cols['doing'])->toBe(['deploy'])->and($s->log)->toBe(['dropped write on doing', 'renamed'])
                ->and($t->text())->not->toContain('Rename')->toContain('deploy');

            $t->press('enter');                                // Escape: the cancel answer
            $t->settle();
            $t->type('nope');
            $t->press('escape');
            $t->settle();
            expect($s->cols['doing'])->toBe(['deploy'])->and(array_slice($s->log, -1))->toBe(['kept']);
        });
    });
});

it('gives the focus to elements with onKey: the Tab walk, focus: classes, their keys, a click', function () {
    fiber\run(function () {
        $s = kanbanState();
        $t = Tui::headless(fn() => kanbanBoard($s), 100, 14, keys: kanbanKeys($s));
        $t->run(function (FakeTerminal $t) use ($s) {
            $t->settle();
            // the first element that takes keys has the focus; Tab walks the cards in the order they show
            expect($t->text())->toContain('delete delete card');       // Help lists the focused card's keys
            $t->press('delete');
            expect($s->cols['todo'])->toBe(['test']);
            $t->settle();
            $t->press('tab', 'delete');                        // test is first now; Tab: on to ship
            expect($s->cols)->toBe(['todo' => ['test'], 'doing' => [], 'done' => []]);
            $t->settle();
            [$x, $y] = screenAt($t, 'test');
            $t->click($x, $y);                                 // a click focuses a card
            $t->press('delete');
            expect($s->cols)->toBe(['todo' => [], 'doing' => [], 'done' => []]);
        });
    });
});

it('shows focus: classes on an element with onKey and calls a closure for every key', function () {
    fiber\run(function () {
        $s = new State(['typed' => '', 'other' => 0]);
        $t = Tui::headless(fn() => new Column([
            new Text("typed: {$s->typed}", class: 'focus:bold', onKey: fn(Key $k) => $k->isText() && ($s->typed .= $k->text) !== null),
            new Text('second', class: 'focus:underline', onKey: ['x' => fn() => $s->other++]),
        ]), 30, 4);
        $t->run(function (FakeTerminal $t) use ($s) {
            $t->settle();
            $t->type('ab');
            $t->settle();
            expect($s->typed)->toBe('ab')->and($t->dump())->toContain('typed: ab');
            $dump = $t->dump();
            expect(preg_match('/\e\[[0-9;]*1[;m][^\n]*typed/', $t->output()))->toBe(1);
            $t->press('tab');                                  // the closure did not take Tab: the walk goes on
            $t->type('x');
            expect($s->other)->toBe(1)->and($s->typed)->toBe('ab');
            $t->press('shift+tab');
            $t->type('c');
            expect($s->typed)->toBe('abc');
        });
    });
});

it('opens Confirm::ask() from a coroutine and from an element handler, one at a time', function () {
    fiber\run(function () {
        $s = new State(['answers' => []]);
        $ask = function () use ($s) {
            $answer = fiber\await(Confirm::ask('Quit', 'Really quit?')) ? 'yes' : 'no';
            $s->answers = [...$s->answers, $answer];
        };
        $t = Tui::headless(fn() => new Text('answers: ' . implode(',', $s->answers), onKey: ['q' => $ask]), 40, 8);
        $t->run(function (FakeTerminal $t) use ($s, $ask) {
            $t->settle();
            $t->press('q');
            $t->settle();
            expect($t->text())->toContain('Really quit?');
            $t->press('q');                                    // the board is suspended: it does not ask twice
            fiber\go($ask);                                    // from a coroutine: the one open dialog's answer
            fiber\sleep(0.02);
            $t->press('enter');                                // Yes
            $t->settle();
            expect($s->answers)->toBe(['yes', 'yes'])->and($t->text())->not->toContain('Really');
            fiber\go($ask);
            fiber\sleep(0.02);
            $t->press('escape');
            $t->settle();
            expect($s->answers)->toBe(['yes', 'yes', 'no']);
        });
    });
});

it('refuses Dialog::open() when no program runs', function () {
    expect(fn() => Dialog::open(new Dialog('x')))->toThrow(LogicException::class, 'no program is running');
});

it('binds keys on a component root and in inline mode, and refuses a bad binding before starting', function () {
    fiber\run(function () {
        $s = new State(['n' => 0]);
        $t = Tui::headless(fn() => new Text("n = {$s->n}"), 20, 4, inline: true, keys: ['+' => fn() => $s->n++, 'ctrl+q' => fn() => $s->n = 100]);
        $t->run(function (FakeTerminal $t) use ($s) {
            $t->settle();
            $t->type('++');
            $t->settle();
            expect($t->region())->toContain('n = 2');
            $t->press('ctrl+q');                              // bound by the program: no quit
            expect($s->n)->toBe(100)->and($t->isAttached())->toBeTrue();
        });
    });
    expect(fn() => Tui::headless(new Text('x'), keys: ['nonsense-key' => fn() => 1]))->toThrow(InvalidArgumentException::class);
});
