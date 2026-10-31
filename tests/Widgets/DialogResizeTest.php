<?php

/*
 * Overlays inside a dialog across resizes (0.0.2-beta4): a Dialog holding a Form with an open
 * dropdown Select is placed again (centred) when the terminal is resized, the list stays
 * anchored to its field inside the dialog (or closes when the field is no longer shown), and
 * nothing under them is left unpainted. The list prefers the side that keeps the dialog's
 * buttons visible.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Form;
use phasync\Tui\Component\Select;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Surface;

use function fiber\await;

/** A screen full of text that opens the dialog it is given. */
final class ExportScreen extends Component
{
    public array $answers = [];

    public function ask(Dialog $dialog): void
    {
        $this->go(function () use ($dialog) {
            $this->answers[] = await($this->dialog($dialog));
        });
    }

    protected function render(): Element
    {
        return new Text(str_repeat('background ', 300));
    }
}

/** The dialog of the report: a name, a dropdown of roles, notes; OK and Cancel. */
function exportDialog(?Select &$select): Dialog
{
    $select = (new Select(['dev' => 'Developer', 'ops' => 'Operations', 'pm' => 'Product', 'qa' => 'Quality'], dropdown: true))->with(id: 'role');
    return new Dialog('Export', new Form(new Column([
        new Text('Name'), (new Editor(maxLines: 1))->with(id: 'name'),
        new Text('Role'), $select,
        new Text('Notes'), (new Editor(maxLines: 2))->with(id: 'notes'),
    ])), ['OK' => 'ok', 'Cancel' => null]);
}

/**
 * Check what $t shows (or the rectangle $in of it): the dialog's box is whole (every row
 * between its corners has both side borders: nothing under it unpainted), and the open list
 * of $select is next to its field, inside the dialog's sides. Returns [field row, list rows, buttons shown].
 *
 * @return array{int, list<int>, bool}
 */
function checkExport(FakeTerminal $t, Select $select, ?array $in = null): array
{
    $rows = explode("\n", $t->text());
    if ($in !== null) {
        [$x0, $y0, $w, $h] = $in;
        $rows = array_map(fn($r) => mb_substr($r, $x0, $w), array_slice($rows, $y0, $h));
    }
    $top = $bottom = null;
    foreach ($rows as $y => $r) {
        if ($top === null && str_contains($r, '╭')) {
            $top = $y;
        } elseif ($top !== null && str_contains($r, '╰')) {
            $bottom = $y;
            break;
        }
    }
    expect($top)->not->toBeNull(implode("\n", $rows))->and($bottom)->not->toBeNull(implode("\n", $rows));
    $left = mb_strpos($rows[$top], '╭');
    $right = mb_strpos($rows[$top], '╮');
    expect($right)->not->toBeFalse();
    for ($y = $top + 1; $y < $bottom; $y++) {
        expect(mb_substr($rows[$y], $left, 1))->toBe('│', "row $y\n" . implode("\n", $rows))
            ->and(mb_substr($rows[$y], $right, 1))->toBe('│', "row $y\n" . implode("\n", $rows));
    }
    $field = null;
    $list = [];
    foreach ($rows as $y => $r) {
        if (str_contains($r, '▴') || str_contains($r, '▾')) {
            $field = $y;
        } elseif (preg_match('/│ (Developer|Operations|Product|Quality)\s/u', $r) || preg_match('/^\s*(Developer|Operations|Product|Quality)\s*$/u', mb_substr($r, $left + 1, $right - $left - 1))) {
            $list[] = $y;
        }
    }
    $buttons = str_contains(implode("\n", $rows), '[ OK ]');
    if ($select->isOpen()) {
        expect($field)->not->toBeNull()->and($list)->not->toBe([]);
        // contiguous, right above or right below the field
        expect($list)->toBe(range($list[0], end($list)));
        expect($list[0] === $field + 1 || end($list) === $field - 1)->toBeTrue("the list is not next to its field\n" . implode("\n", $rows));
    }
    return [$field ?? -1, $list, $buttons];
}

it('places the dialog again and keeps the open dropdown under its field, at several sizes', function () {
    mountOn(new ExportScreen(), 60, 20, function (FakeTerminal $t, ExportScreen $app) {
        $app->ask(exportDialog($select));
        $t->settle();
        $select->focus();
        $t->press('enter');
        $t->settle();
        [, , $buttons] = checkExport($t, $select);
        expect($select->isOpen())->toBeTrue()->and($buttons)->toBeTrue();      // the list went the way that keeps them
        foreach ([[50, 18], [70, 24], [44, 16], [80, 30], [60, 20]] as [$cols, $rows]) {
            $t->resize($cols, $rows);
            $t->settle();
            [, $list, $buttons] = checkExport($t, $select);
            expect($select->isOpen())->toBeTrue("at {$cols}x$rows")->and($list)->toHaveCount(4)->and($buttons)->toBeTrue();
        }
        $t->press('down', 'enter');
        $t->settle();
        expect($select->current())->toBe('ops')->and($select->isOpen())->toBeFalse();
        checkExport($t, $select);
    });
});

it('keeps the list anchored or closes it when the dialog no longer fits, and recovers when it grows again', function () {
    mountOn(new ExportScreen(), 60, 20, function (FakeTerminal $t, ExportScreen $app) {
        $app->ask(exportDialog($select));
        $t->settle();
        $select->focus();
        $t->press('enter');
        $t->settle();
        foreach ([[40, 9], [30, 7], [24, 6], [60, 20]] as [$cols, $rows]) {
            $t->resize($cols, $rows);
            $t->settle();
            checkExport($t, $select);       // anchored next to its field when open; the dialog whole
            if ([$cols, $rows] === [40, 9]) {
                expect($select->isOpen())->toBeTrue("at {$cols}x$rows: the focused field scrolled into view keeps it open");
            }
            foreach (explode("\n", $t->text()) as $line) {
                expect(mb_strwidth($line))->toBeLessThanOrEqual($cols);
            }
        }
        expect($app->answers)->toBe([]);
        $t->press('escape');
        $t->settle();
        if ($select->isOpen()) {
            $t->press('escape');
            $t->settle();
        }
        expect($app->answers)->toBe([null])->and($t->text())->not->toContain('Export');
    });
});

it('survives a resize storm with a dialog and a dropdown open', function () {
    mt_srand(4);
    mountOn(new ExportScreen(), 60, 20, function (FakeTerminal $t, ExportScreen $app) {
        $app->ask(exportDialog($select));
        $t->settle();
        $select->focus();
        $t->press('enter');
        $t->settle();
        for ($round = 0; $round < 6; $round++) {
            for ($i = 0; $i < 15; $i++) {
                $t->resize(mt_rand(30, 120), mt_rand(14, 40));
                if (mt_rand(0, 2) === 0) {
                    frame();
                }
            }
            $t->resize(mt_rand(50, 100), mt_rand(18, 36));
            $t->settle();
            [, $list, $buttons] = checkExport($t, $select);
            expect($select->isOpen())->toBeTrue()->and($list)->toHaveCount(4)->and($buttons)->toBeTrue();
            $replay = new Surface($t->surface->cols(), $t->surface->rows());
            $replay->write($t->output());
        }
        $t->press('escape', 'escape');
        $t->settle();
        expect($app->answers)->toBe([null]);
    });
});

it('keeps a dropdown in a dialog of a nested instance inside the inner pane', function () {
    $inner = new ExportScreen();
    $outer = new class ($inner) extends Component {
        public function __construct(private ExportScreen $inner)
        {
        }

        protected function render(): Element
        {
            return new Column([new Text('outer program'), new Box($this->child('inner', fn() => $this->inner), 'inner')]);
        }
    };
    \fiber\run(function () use ($inner, $outer) {
        $inner->start(new Surface(50, 16));       // a program of its own, shown in a pane of the outer one
        $t = new FakeTerminal(60, 20);
        $outer->start($t);
        $t->run(function (FakeTerminal $t) use ($inner) {
            $t->settle();
            $inner->ask(exportDialog($select));
            $t->settle();
            $select->focus();
            $t->press('enter');
            $t->settle();
            $pane = fn() => [1, 2, $t->surface->cols() - 2, $t->surface->rows() - 3];  // inside the Box under the title row
            [, $list] = checkExport($t, $select, $pane());
            expect($select->isOpen())->toBeTrue()->and($list)->toHaveCount(4);
            foreach ([[50, 18], [70, 26], [60, 20]] as [$cols, $rows]) {
                $t->resize($cols, $rows);
                $t->settle();
                [, $list] = checkExport($t, $select, $pane());
                expect($select->isOpen())->toBeTrue()->and($list)->toHaveCount(4);
            }
            $inner->dispose();
        });
    });
});
