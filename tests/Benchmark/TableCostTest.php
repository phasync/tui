<?php

/*
 * TODO-0.0.3-beta1 item 4: a Table with 'auto' columns measures its cells when its rows change,
 * not on every cursor move, and a move through 10,000 rows costs what one through 100 does.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Table;
use phasync\Tui\Element;
use phasync\Tui\FakeTerminal;

final class BigTable extends Component
{
    public Table $table;

    public function __construct(private int $n)
    {
    }

    protected function mount(): void
    {
        $rows = [];
        for ($i = 0; $i < $this->n; $i++) {
            $rows[$i] = [(string) $i, "process-$i", sprintf('%.1f', $i % 100 / 10)];
        }
        $this->table = $this->child('t', fn() => new Table(columns: ['PID' => 'auto', 'Command' => 'auto', 'CPU' => 'auto'], rows: $rows));
        $this->table->focus();
    }

    protected function render(): Element
    {
        return $this->table;
    }
}

/** The CPU seconds of $moves Down keys (each settled into a frame) on a table of $n rows, and the cells it measured meanwhile. */
function tableMoves(int $n, int $moves): array
{
    $result = null;
    mountOn($app = new BigTable($n), 80, 30, function (FakeTerminal $t, BigTable $app) use ($moves, &$result) {
        $t->settle();
        $measured = $app->table->measuredCells();
        $start = hrtime(true);
        for ($i = 0; $i < $moves; $i++) {
            $t->press('down');
            $t->refresh();
        }
        $seconds = (hrtime(true) - $start) / 1e9;
        $result = [$seconds, $app->table->measuredCells() - $measured, $app->table->current()];
    });
    return $result;
}

it('measures the cells of auto columns once per change of the rows, not per cursor move', function () {
    [, $measured, $current] = tableMoves(10000, 200);
    expect($measured)->toBe(0)->and($current)->toBe(200);
    // new rows: one pass over them
    mountOn(new BigTable(10000), 80, 30, function (FakeTerminal $t, BigTable $app) {
        $t->settle();
        $before = $app->table->measuredCells();
        $app->table->rows = [...$app->table->rows, 10000 => ['10000', 'a much longer command line here', '0.0']];
        $app->table->requestRender();
        $t->settle();
        $after = $app->table->measuredCells();
        expect($after - $before)->toBe(3 * 10001);
        $t->press('down', 'down', 'up');
        $t->settle();
        expect($app->table->measuredCells())->toBe($after)->and($t->text())->toContain('Command');
    });
});

it('moves the cursor through 10,000 rows as fast as through 100', function () {
    tableMoves(100, 20);      // (warm up)
    // (90 moves: all of them move in both tables, the view scrolling from the 29th on)
    [$small] = tableMoves(100, 90);
    [$big] = tableMoves(10000, 90);
    // the same work per key: the rows in view drawn; with headroom for a busy machine
    expect($big)->toBeLessThan(max(2 * $small, $small + 0.1))
        ->and($big / 90)->toBeLessThan(0.03);       // well inside a key every 30 ms
});
