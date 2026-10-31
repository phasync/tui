<?php

/*
 * TODO-0.0.2-beta5 item 3: a dialog taller than the terminal crops its content and scrolls,
 * it never squeezes it: a Box of a field stays three rows (border, field, border), also next
 * to a field that takes any room (an Editor without maxLines); giving a field the focus brings
 * its whole Box into view; a dropdown opened from a scrolled field opens under the field where
 * it is shown, and a resize with the list open leaves it anchored or closed, nothing squeezed.
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

use function fiber\await;

final class TaskScreen extends Component
{
    /** @var array<string, Component> the fields of the dialog, by the title of their Box */
    public array $fields = [];
    public ?Select $priority = null;

    public function open(): void
    {
        $this->fields = [
            'Title' => (new Editor(maxLines: 1))->with(id: 'title'),
            'Priority' => $this->priority = new Select(['low' => 'Low', 'mid' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'], dropdown: true),
            'Notes' => (new Editor())->with(id: 'notes'),        // takes any room
            'Owner' => (new Editor(maxLines: 1))->with(id: 'owner'),
            'Tags' => (new Editor(maxLines: 1))->with(id: 'tags'),
        ];
        $boxes = [];
        foreach ($this->fields as $title => $field) {
            $boxes[] = new Box($field, title: $title);
        }
        $dialog = (new Dialog('New task', new Form(new Column($boxes)), ['OK' => 'ok', 'Cancel' => null]))->with(width: 50, height: 24);
        $this->go(fn() => await($this->dialog($dialog)));
    }

    /** The title of the Box whose field has the focus, or null (a button has it). */
    public function focused(): ?string
    {
        foreach ($this->fields as $title => $field) {
            if ($field->hasFocus()) {
                return $title;
            }
        }
        return null;
    }

    protected function render(): Element
    {
        return new Text(str_repeat('background ', 300));
    }
}

/**
 * The Boxes of the fields as $t shows them: per title, the row of its top border and the column
 * of its corner. Each Box shown is whole as far as it is shown: below its top border comes the
 * field's row ('│'), not its bottom border (a squeezed Box), unless the dialog's scroll area
 * ends there. Returns [title => [top row, column]].
 *
 * @return array<string, array{int, int}>
 */
function taskBoxes(FakeTerminal $t): array
{
    $rows = explode("\n", $t->text());
    $boxes = [];
    foreach ($rows as $y => $row) {
        if (preg_match('/╭ (\w+) ─/u', $row, $m, PREG_OFFSET_CAPTURE)) {
            $x = mb_strlen(substr($row, 0, $m[0][1]));
            $boxes[$m[1][0]] = [$y, $x];
            $below = mb_substr($rows[$y + 1] ?? '', $x, 1);
            expect($below)->not->toBe('╰', "the {$m[1][0]} Box is squeezed:\n" . $t->text());
        }
    }
    return $boxes;
}

/** The Box of the focused field is shown whole: its top border, the field, its bottom border. */
function expectWholeBox(FakeTerminal $t, TaskScreen $app): void
{
    $title = $app->focused();
    expect($title)->not->toBeNull();
    $boxes = taskBoxes($t);
    expect(isset($boxes[$title]))->toBeTrue("the focused $title Box is not shown:\n" . $t->text());
    [$y, $x] = $boxes[$title];
    $rows = explode("\n", $t->text());
    expect(mb_substr($rows[$y + 1], $x, 1))->toBe('│', "$title:\n" . $t->text())
        ->and(mb_substr($rows[$y + 2], $x, 1))->toBe('╰', "the bottom of the focused $title Box is not shown:\n" . $t->text());
}

dataset('small terminals', [[80, 14], [80, 16], [40, 12]]);

it('crops a dialog taller than the terminal and shows the whole Box of each field it focuses', function (int $cols, int $rows) {
    mountOn(new TaskScreen(), $cols, $rows, function (FakeTerminal $t, TaskScreen $app) {
        $app->open();
        $t->settle();
        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            expectWholeBox($t, $app);
            $seen[] = $app->focused();
            $t->press('tab');
            $t->settle();
        }
        expect($seen)->toBe(['Title', 'Priority', 'Notes', 'Owner', 'Tags']);
        taskBoxes($t);          // (on a button: still nothing squeezed)
        $t->press('shift+tab', 'shift+tab', 'shift+tab', 'shift+tab', 'shift+tab');      // and back up
        $t->settle();
        expect($app->focused())->toBe('Title');
        expectWholeBox($t, $app);
    });
})->with('small terminals');

it('crops without a resize and after one', function () {
    mountOn(new TaskScreen(), 80, 30, function (FakeTerminal $t, TaskScreen $app) {
        $app->open();
        $t->settle();
        expect(array_keys(taskBoxes($t)))->toBe(['Title', 'Priority', 'Notes', 'Owner', 'Tags']);    // it fits: all of it
        $t->resize(80, 14);
        $t->settle();
        expectWholeBox($t, $app);
        $t->resize(80, 30);
        $t->settle();
        expect(array_keys(taskBoxes($t)))->toBe(['Title', 'Priority', 'Notes', 'Owner', 'Tags']);
    });
});

it('opens the dropdown of a scrolled field under the row it is shown on', function () {
    mountOn(new TaskScreen(), 80, 14, function (FakeTerminal $t, TaskScreen $app) {
        $app->open();
        $t->settle();
        $t->press('tab', 'tab', 'tab', 'tab');       // to Tags: the dialog scrolls down
        $t->settle();
        $t->press('shift+tab', 'shift+tab', 'shift+tab');    // back to Priority, scrolled
        $t->settle();
        expect($app->focused())->toBe('Priority');
        $boxes = taskBoxes($t);
        expect($boxes['Priority'][0])->toBeLessThan(5);     // (the Title Box is scrolled off)
        $t->press('down');
        $t->settle();
        expect($app->priority->isOpen())->toBeTrue();
        $rows = explode("\n", $t->text());
        [$y, $x] = $boxes['Priority'];
        expect(mb_substr($rows[$y + 1], $x + 1))->toStartWith('Low')     // the field on its row
            ->and(mb_substr($rows[$y + 2], $x))->toStartWith('╰')        // its box's border stays
            ->and(mb_substr($rows[$y + 3], $x + 1))->toStartWith('Low')  // the list right under the box
            ->and(mb_substr($rows[$y + 4], $x + 1))->toStartWith('Medium');
        $t->press('down', 'enter');
        $t->settle();
        expect($app->priority->selected())->toBe('mid')->and($app->priority->isOpen())->toBeFalse();
        expectWholeBox($t, $app);
    });
});

it('keeps the open list anchored or closes it when the terminal shrinks from 80x30 to 80x14', function () {
    mountOn(new TaskScreen(), 80, 30, function (FakeTerminal $t, TaskScreen $app) {
        $app->open();
        $t->settle();
        $t->press('tab', 'down');
        $t->settle();
        expect($app->priority->isOpen())->toBeTrue();
        $t->resize(80, 14);
        $t->settle();
        $rows = explode("\n", $t->text());
        $boxes = taskBoxes($t);
        if ($app->priority->isOpen()) {
            expect($boxes)->toHaveKey('Priority');
            [$y, $x] = $boxes['Priority'];
            $field = $y + 1;
            $under = mb_substr($rows[$field + 2], $x + 1);
            $over = mb_substr($rows[$field - 2], $x + 1);
            // the list next to the field's box: under it, or over it when there is no room below
            expect(str_starts_with($under, 'Low') || str_starts_with($over, 'Urgent') || str_starts_with($over, 'High'))->toBeTrue($t->text());
        }
        $t->press('escape');
        $t->settle();
        expect($app->priority->isOpen())->toBeFalse();
        expect($app->focused())->toBe('Priority');
        expectWholeBox($t, $app);
    });
});
