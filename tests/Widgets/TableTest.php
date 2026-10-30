<?php

/*
 * Table and Select through the terminal: columns, widths, alignment, truncation, sideways
 * scrolling, sorting, the current row, filtering, marking, and the selection kept by key.
 */

use phasync\Tui\Component\Select;
use phasync\Tui\Component\Table;
use phasync\Tui\FakeTerminal;

function procs(): Table
{
    return new Table(
        columns: ['PID' => ['width' => 5, 'align' => 'right'], 'Command' => '1fr', 'CPU' => ['width' => 4, 'align' => 'right']],
        rows: [1201 => ['1201', 'php server.php', '3.2'], 88 => ['88', 'sshd', '10.5'], 7 => ['7', "\e[31mkthreadd\e[0m", '0']],
    );
}

it('lays out columns by width and alignment under a header, cutting long cells', function () {
    mountOn(procs(), 22, 4, function (FakeTerminal $t) {
        expect($t->text())->toBe(implode("\n", [
            '  PID Command      CPU',
            ' 1201 php server…  3.2',
            '   88 sshd        10.5',
            '    7 kthreadd       0',
        ]))->and($t->dump())->toContain('[bold underline]  PID');
    });
});

it('moves the current row with the keys and shows it inverted, colours kept', function () {
    mountOn(procs(), 22, 4, function (FakeTerminal $t, Table $table) {
        expect($table->current())->toBe(1201);
        $t->press('down', 'down');
        frame();
        expect($table->current())->toBe(7)->and($t->dump())->toContain('[inverse]    7 [/][inverse fg=1]kthreadd[/]');
        $t->press('up', 'home');
        expect($table->current())->toBe(1201);
    });
});

it('sorts by a column, numbers as numbers, by a click on its title, and keeps the current row', function () {
    mountOn(procs(), 22, 4, function (FakeTerminal $t, Table $table) {
        $t->press('down');                    // 88
        $t->click(1, 0);                      // sort by PID
        frame();
        expect($t->text())->toStartWith("PID ▲")
            ->and(explode("\n", $t->text())[1])->toBe('    7 kthreadd       0')
            ->and($table->current())->toBe(88);
        $t->click(1, 0);                      // the other way
        frame();
        expect(explode("\n", $t->text())[1])->toBe(' 1201 php server…  3.2')->and($table->sorting())->toBe([0, true]);
        $table->sortBy(2);
        frame();
        expect(explode("\n", $t->text())[3])->toBe('   88 sshd        10.5');
    });
});

it('scrolls a table wider than its window sideways by column', function () {
    $table = new Table(columns: ['A' => 6, 'B' => 6, 'C' => 6], rows: [['a1', 'b1', 'c1']]);
    mountOn($table, 10, 2, function (FakeTerminal $t) {
        expect($t->text())->toBe("A      B\na1     b1");
        $t->press('right');
        frame();
        expect($t->text())->toBe("B      C\nb1     c1");
        $t->press('right');             // the last column in full
        frame();
        expect($t->text())->toBe("C\nc1");
        $t->press('right', 'left', 'left');
        frame();
        expect($t->text())->toBe("A      B\na1     b1");
    });
});

it('scrolls rows to keep the current one in view, and with the wheel', function () {
    $table = new Table(columns: ['n' => 'auto'], rows: array_map(fn($i) => ["row $i"], range(0, 19)));
    mountOn($table, 10, 4, function (FakeTerminal $t, Table $table) {
        $t->press('end');
        frame();
        expect($t->text())->toBe("n\nrow 17\nrow 18\nrow 19");
        $t->scroll(2, 2, -2);
        frame();
        expect($t->text())->toStartWith("n\nrow 11");
    });
});

it('selects one item: callbacks, Enter and the second click activate', function () {
    $log = [];
    $select = new Select(['a' => 'Apple', 'b' => 'Banana', 'c' => 'Cherry'],
        onSelect: function ($k) use (&$log) { $log[] = "select $k"; },
        onActivate: function ($k) use (&$log) { $log[] = "activate $k"; });
    mountOn($select, 12, 3, function (FakeTerminal $t, Select $s) use (&$log) {
        expect($t->text())->toBe("Apple\nBanana\nCherry");
        $t->press('down', 'enter');
        $t->click(1, 2);
        $t->click(1, 2);
        frame();
        expect($log)->toBe(['select b', 'activate b', 'select c', 'activate c'])
            ->and($s->selected())->toBe('c')->and($s->selection())->toBe(['c'])
            ->and($t->dump())->toContain('[bold inverse fg=6]Cherry');
    });
});

it('filters as you type, keeps the current item if shown, and clears with Escape', function () {
    $select = new Select(['a' => 'Apple', 'b' => 'Banana', 'c' => 'Cherry', 'd' => 'Date']);
    mountOn($select, 12, 5, function (FakeTerminal $t, Select $s) {
        $t->press('down');              // Banana
        $t->type('an');
        frame();
        expect($t->text())->toBe("Banana\n\n\n\n/an")->and($s->selected())->toBe('b');
        $t->press('backspace', 'backspace');
        $t->type('e');
        frame();
        expect($t->text())->toBe("Apple\nCherry\nDate\n\n/e")->and($s->selected())->toBe('a');
        $t->press('escape');
        frame();
        expect($s->filter())->toBe('')->and($t->text())->toBe("Apple\nBanana\nCherry\nDate\n");
    });
});

it('marks several items with Space, keeps marks and the current item across new items', function () {
    $changes = [];
    $select = new Select(['a' => 'A', 'b' => 'B', 'c' => 'C'], multiple: true, onChange: function (array $keys) use (&$changes) {
        $changes[] = $keys;
    });
    mountOn($select, 8, 3, function (FakeTerminal $t, Select $s) use (&$changes) {
        $t->press('space', 'down', 'down', 'space');
        expect($s->selection())->toBe(['a', 'c'])->and($changes)->toBe([['a'], ['a', 'c']]);
        $s->setItems(['z' => 'Z', 'c' => 'C', 'a' => 'A']);
        frame();
        expect($s->selection())->toBe(['c', 'a'])->and($s->selected())->toBe('c')
            ->and($t->dump())->toContain('[bold fg=6]A');
        $s->setItems(['z' => 'Z', 'a' => 'A']);       // the current item is gone: the one at its place
        expect($s->selection())->toBe(['a'])->and($s->selected())->toBe('a');
        $t->press('alt+a');
        expect($s->selection())->toBe(['z', 'a']);
    });
});
