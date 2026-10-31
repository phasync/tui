<?php

/*
 * TODO-0.0.2-beta5 item 1: what a frame costs. A component rendering a Column of 50
 * Row([Text, Text]) in which one Text changes: the CPU of 100 such frames, and the work one
 * change makes (what is measured, rendered and painted), in the tree of one component and in a
 * tree of a component per row. The numbers are in docs/surface.md, "Performance".
 */

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Surface;

/** A part of a row that counts how often it is measured and rendered. */
final class CostProbe extends Component
{
    public static int $measures = 0;
    public static int $renders = 0;

    public function measure(int $cols, int $rows): ?array
    {
        self::$measures++;
        return [3, 1];
    }

    protected function render(): Element
    {
        self::$renders++;
        return new Text('[x]');
    }
}

/** 50 rows in one component's tree; row 7 shows a counter. */
final class FlatRows extends Component
{
    public int $n = 0;

    public function __construct(private bool $probes = false)
    {
    }

    protected function render(): Element
    {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $cells = [new Text("job $i", width: 10), new Text($i === 7 ? "{$this->n} ticks" : 'idle')];
            if ($this->probes) {
                $cells[] = new CostProbe();
            }
            $rows[] = new Row($cells, gap: 1);
        }
        return new Column($rows);
    }
}

/** A row that is a component of its own, with its state. */
final class CostRow extends Component
{
    public static int $renders = 0;

    public function __construct(public int $i = 0)
    {
    }

    public function tick(): void
    {
        $this->state->n = ($this->state->n ?? 0) + 1;
    }

    protected function render(): Element
    {
        self::$renders++;
        return new Row([new Text("job {$this->i}", width: 10), new Text(($this->state->n ?? 0) . ' ticks', width: 10), new CostProbe()], gap: 1);
    }
}

final class RowComponents extends Component
{
    public static int $renders = 0;

    /** @var list<CostRow> */
    public array $rows = [];

    protected function render(): Element
    {
        self::$renders++;
        for ($i = 0; $i < 50; $i++) {
            $this->rows[$i] = $this->child("row:$i", fn() => new CostRow($i));
        }
        return new Column($this->rows);
    }
}

/** @return array<int, array{Surface, int}> the surfaces below $s that nothing is hosted on (what gets painted), with their versions */
function paintedLeaves(Surface $s, array $out = []): array
{
    if ($s->viewports() === []) {
        $out[spl_object_id($s)] = [$s, $s->version()];
    }
    foreach ($s->viewports() as $v) {
        $out = paintedLeaves($v->source, $out);
    }
    return $out;
}

/** How many of the leaves in $before were painted since (their version moved). */
function paintedSince(array $before, Surface $root): int
{
    $n = 0;
    foreach (paintedLeaves($root) as $id => [, $version]) {
        $n += ($before[$id][1] ?? -1) !== $version ? 1 : 0;
    }
    return $n;
}

it('renders the 50-row tree 100 times in a fraction of a second of CPU', function () {
    mountOn(new FlatRows(), 120, 60, function (FakeTerminal $t, FlatRows $app) {
        $t->settle();
        $cpu = function (): float {
            $u = getrusage();
            return $u['ru_utime.tv_sec'] + $u['ru_utime.tv_usec'] / 1e6 + $u['ru_stime.tv_sec'] + $u['ru_stime.tv_usec'] / 1e6;
        };
        $start = $cpu();
        for ($k = 1; $k <= 100; $k++) {
            $app->n = $k;
            $app->requestRender();
            $t->refresh();      // render, compose and draw the frame
        }
        $spent = $cpu() - $start;
        expect(explode("\n", $t->text())[7])->toContain('100 ticks');
        // about 0.13 s (1.3 ms a frame) on the machine this was written on: the budget is 10x that
        expect($spent)->toBeLessThan(1.5);
    });
});

it('measures and paints only what changed when one Text of the tree of one component changes', function () {
    mountOn(new FlatRows(probes: true), 120, 60, function (FakeTerminal $t, FlatRows $app) {
        $t->settle();
        $before = paintedLeaves($app->surface);
        expect(\count($before))->toBe(150);      // 50 x (two Texts and a probe)
        CostProbe::$measures = CostProbe::$renders = 0;
        $app->n = 1;
        $app->requestRender();
        $t->settle();
        expect(explode("\n", $t->text())[7])->toContain('1 ticks')
            ->and(paintedSince($before, $app->surface))->toBe(1)       // the one Text
            ->and(CostProbe::$measures)->toBeLessThanOrEqual(1)      // at most the probe of the row that changed
            ->and(CostProbe::$renders)->toBeLessThanOrEqual(1);
    });
});

it('renders, measures and paints one row when the state of a row component changes, not its parent', function () {
    CostRow::$renders = RowComponents::$renders = 0;
    mountOn(new RowComponents(), 120, 60, function (FakeTerminal $t, RowComponents $app) {
        $t->settle();
        $before = paintedLeaves($app->surface);
        CostProbe::$measures = CostProbe::$renders = CostRow::$renders = RowComponents::$renders = 0;
        $app->rows[7]->tick();
        $t->settle();
        expect(explode("\n", $t->text())[7])->toContain('1 ticks')
            ->and(CostRow::$renders)->toBe(1)                       // that row
            ->and(RowComponents::$renders)->toBe(0)                  // not the parent: the row's size did not change
            ->and(CostProbe::$measures)->toBeLessThanOrEqual(1)
            ->and(paintedSince($before, $app->surface))->toBe(1);
    });
});

/** Asks for a render every millisecond. */
final class Eager extends Component
{
    public int $renders = 0;

    protected function run(): void
    {
        while ($this->sleep(0.001)) {
        }
    }

    protected function render(): Element
    {
        $this->renders++;
        return new Text("{$this->renders}");
    }
}

it('renders a component on a terminal with the terminal\'s frames, at most 60 a second', function () {
    mountOn(new Eager(), 20, 2, function (FakeTerminal $t, Eager $app) {
        $t->settle();
        $before = $app->renders;
        fiber\sleep(0.5);
        expect($app->renders - $before)->toBeLessThanOrEqual(33)->toBeGreaterThan(5);
    });
});

/** A child as many rows high as it showed last (it knows its new size only once it rendered). */
final class Grower extends Component
{
    private int $shown = 1;

    public function grow(): void
    {
        $this->state->n = ($this->state->n ?? 1) + 1;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, $this->shown];
    }

    protected function render(): Element
    {
        $this->shown = $this->state->n ?? 1;
        return new Text(implode("\n", array_map(fn($i) => "line $i", range(1, $this->shown))));
    }
}

final class GrowerParent extends Component
{
    public ?Grower $grower = null;
    public string $below = 'below';

    protected function render(): Element
    {
        $this->grower = $this->child('grower', fn() => new Grower());
        return new Column([$this->grower->with(height: 'auto'), new Text($this->below)]);
    }
}

it('draws a child\'s new size and the parent\'s layout for it in one frame', function () {
    mountOn(new GrowerParent(), 20, 5, function (FakeTerminal $t, GrowerParent $app) {
        $t->settle();
        $t->takeOutput();
        // both change in one moment: the parent renders first, then the child, whose size
        // changed, so the parent lays out again, in the same frame
        $app->below = 'under';
        $app->requestRender();
        $app->grower->grow();
        frame();
        frame();
        expect(substr_count($t->takeOutput(), "\e[?2026h"))->toBe(1)   // one frame, not one per render
            ->and($t->text())->toBe("line 1\nline 2\nunder\n\n");
    });
});
