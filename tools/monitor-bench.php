<?php
/**
 * The 50-row monitor of docs/surface.md, "Performance": what share of a core it takes.
 *
 *     php tools/monitor-bench.php [some|all|bare|keys] [seconds]
 *     php tools/monitor-bench.php scroll [rows] [focus|nofocus]
 *     php tools/monitor-bench.php tabs [cols] [rows]
 *     php tools/monitor-bench.php bars
 *
 * On a FakeTerminal of 120x60, a component of 50 row components, each row a `Text`, a
 * `ProgressBar`, a `Text` and two `Button`s with a coroutine of its own in a `sleep(0.1)` loop.
 * `some`: one row in twenty changes per tick; `all`: every row changes on every tick; `bare`:
 * the same 50 coroutines in a `fiber\sleep(0.1)` loop and no TUI at all (the runtime's part).
 * `keys`: the closure program of TODO-0.0.3-beta5 item 3 (no class: 50 `Row([Text, ProgressBar,
 * Button, Button])` with `onKey:` and `onFocus:`, the bars moved by 50 `Tui::go()` coroutines at
 * 10 Hz) and the time from a Tab to a settled screen (`press('tab')` and `settle()`, as a
 * program under `Tui::headless()` measures it), as median and worst of the keys pressed in the
 * seconds given.
 * `scroll`: the program of TODO-0.0.3-beta7 item 1, verbatim: `rows` (default 200) keyed
 * `Row([Text, Button, Button], class: 'gap-1')` in a `Column` in a `ScrollView`, a footer
 * `Text` closure reading the State, `onFocus:` on each row unless `nofocus`; 20 Tabs at
 * 160x50, median and worst.
 * `tabs`: the layout of TODO-0.0.3-beta7 item 2: `Tabs` with `Row([Markdown, Box(Select)])`
 * and `Box(Terminal)`, in `Column([Row([Tabs, Box(tools)]), Box(Editor), Text(closure)])`;
 * 20 switches with Alt-1/Alt-2 (`press()` then `text()`), median and worst, at the size given
 * (default 120x40).
 * `bars`: the program of `keys` without its coroutines, and the frame where all fifty bars move
 * at once (every value changed, then `settle()`), median and worst of 40 such frames.
 * Run with `-d zend.enable_gc=0` to see what the runtime's idle garbage collection adds.
 * Mind the CPU governor (see "Measuring" in docs/surface.md).
 */
require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Markdown;
use phasync\Tui\Component\ScrollView;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Element\Box;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\State;
use phasync\Tui\Tui;

$mode = $argv[1] ?? 'some';
$seconds = (float) ($argv[2] ?? 5);
if (!in_array($mode, ['some', 'all', 'bare', 'keys', 'scroll', 'tabs', 'bars'], true)) {
    fwrite(STDERR, "usage: php tools/monitor-bench.php [some|all|bare|keys] [seconds]\n       php tools/monitor-bench.php scroll [rows] [focus|nofocus]\n       php tools/monitor-bench.php tabs [cols] [rows]\n       php tools/monitor-bench.php bars\n");
    exit(1);
}

/** "median, worst" of a list of times in ms. */
function benchSummary(array $times): string
{
    sort($times);
    return sprintf('%.1f ms median, %.1f ms at worst, over %d', $times[intdiv(count($times), 2)], end($times), count($times));
}

if ($mode === 'scroll') {
    $n = (int) ($argv[2] ?? 200);
    $withFocus = ($argv[3] ?? 'focus') !== 'nofocus';
    fiber\run(function () use ($n, $withFocus) {
        $renders = 0;
        // --- the program, verbatim (onFocus: left out with `nofocus`) ---
        $s = new State(['sel' => 0]); $btns = [];
        $screen = function () use ($n, $s, &$btns, $withFocus, &$renders) {
            $renders++;
            $rows = [];
            for ($i = 0; $i < $n; $i++) {
                $btns[$i] ??= [new Button('Pause', fn() => null), new Button('Cancel', fn() => null)];
                $rows[] = $withFocus
                    ? new Row([new Text("file$i"), ...$btns[$i]], class: 'gap-1', key: "r$i", onFocus: fn() => $s->sel = $i)
                    : new Row([new Text("file$i"), ...$btns[$i]], class: 'gap-1', key: "r$i");
            }
            return new Column([new ScrollView(new Column($rows)), new Text(fn() => "sel {$s->sel}")]);
        };
        $t = Tui::headless($screen, 160, 50); $t->settle(); fiber\sleep(0.3);
        $renders = 0;
        $times = [];
        for ($k = 0; $k < 20; $k++) {
            $a = hrtime(true); $t->press('tab'); $t->settle(); $times[] = (hrtime(true) - $a) / 1e6; fiber\sleep(0.05);
        }
        printf("scroll: %d rows%s: a Tab to a settled screen in %s Tabs; %d renders of the closure\n", $n, $withFocus ? '' : ' (no onFocus)', benchSummary($times), $renders);
        $t->detach();
    });
    exit;
}
if ($mode === 'tabs') {
    $cols = (int) ($argv[2] ?? 120);
    $rows = (int) ($argv[3] ?? 40);
    fiber\run(function () use ($cols, $rows) {
        $notes = "# Release notes\n\n## 0.1\n\n- **Faster** tab switches\n- `Markdown` costs what a `Text` costs\n\n```php\n\$t = Tui::headless(\$app);\n```\n\nSee [the docs](https://example.com).\n";
        $md = new Markdown($notes);
        $list = new Select(['alpha', 'beta', 'gamma', 'delta']);
        $shell = new Terminal(['sh', '-c', 'echo ready; exec cat']);
        $tabs = new Tabs(['notes' => ['label' => 'Notes', 'content' => new Row([$md, new Box($list, 'Files')])], 'shell' => ['label' => 'Shell', 'content' => new Box($shell, 'sh')]], hints: true);
        $editor = new Editor('hello');
        $t = Tui::headless(fn() => new Column([
            new Row([$tabs, new Box(new Text('tools'), 'Tools', width: 20)]),
            new Box($editor, 'Editor', height: 6),
            new Text(fn() => 'status: ' . date('H:i')),
        ]), $cols, $rows);
        $t->settle();
        $t->press('alt+2'); $t->text(); $t->press('alt+1'); $t->text(); fiber\sleep(0.3);
        $times = [];
        for ($k = 0; $k < 20; $k++) {
            $a = hrtime(true); $t->press($k % 2 ? 'alt+1' : 'alt+2'); $t->text(); $times[] = (hrtime(true) - $a) / 1e6; fiber\sleep(0.05);
        }
        printf("tabs: %dx%d: a tab switch to the text in %s switches\n", $cols, $rows, benchSummary($times));
        $t->detach();
    });
    exit;
}

final class MonitorRow extends Component
{
    public static bool $everyTick = false;
    private ProgressBar $bar;
    private int $tick = 0;

    public function __construct(public int $i)
    {
    }

    protected function mount(): void
    {
        $this->go(function () {
            while ($this->sleep(0.1)) {
                $this->tick++;
                if (self::$everyTick || ($this->tick + $this->i) % 20 === 0) {
                    $this->state->n = ($this->state->n ?? 0) + 1;
                    $this->bar->setValue(($this->state->n % 100) / 100);
                }
            }
        });
    }

    protected function render(): Element
    {
        $this->bar = $this->child('bar', fn() => new ProgressBar(0.0));
        return new Row([
            new Text("job {$this->i}", width: 8),
            $this->bar,
            new Text(($this->state->n ?? 0) . ' done', width: 10),
            $this->child('stop', fn() => new Button('Stop')),
            $this->child('log', fn() => new Button('Log')),
        ], gap: 1);
    }
}

final class Monitor extends Component
{
    protected function render(): Element
    {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = $this->child("row:$i", fn() => new MonitorRow($i));
        }
        return new Column($rows);
    }
}

MonitorRow::$everyTick = $mode === 'all';
if ($mode === 'bars') {
    fiber\run(function () {
        $s = new State(['paused' => array_fill(0, 50, false), 'p' => array_fill(0, 50, 0.0), 'current' => null]);
        $terminal = Tui::headless(function () use ($s) {
            $rows = [];
            for ($i = 0; $i < 50; $i++) {
                $rows[] = new Row([
                    new Text("job $i", width: 8),
                    new ProgressBar($s->p[$i]),
                    new Button($s->paused[$i] ? 'Resume' : 'Pause', fn() => $s->paused[$i] = !$s->paused[$i]),
                    new Button('Log'),
                ], gap: 1, onKey: ['p' => fn() => $s->paused[$i] = !$s->paused[$i]], onFocus: fn() => $s->current = $i);
            }
            return new Column($rows);
        }, 120, 60);
        $terminal->settle();
        $times = [];
        for ($k = 0; $k < 44; $k++) {
            $t0 = hrtime(true);
            for ($i = 0; $i < 50; $i++) {
                $s->p[$i] = fmod($s->p[$i] + 0.01 + $i / 1000, 1.0);
            }
            $terminal->settle();
            if ($k >= 4) {
                $times[] = (hrtime(true) - $t0) / 1e6;
            }
            fiber\sleep(0.05);
        }
        printf("bars: the frame where all 50 bars move in %s frames\n", benchSummary($times));
        $terminal->detach();
    });
    exit;
}
if ($mode === 'keys') {
    fiber\run(function () use ($seconds) {
        $s = new State(['paused' => array_fill(0, 50, false), 'p' => array_fill(0, 50, 0.0), 'current' => null]);
        for ($i = 0; $i < 50; $i++) {
            Tui::go(function () use ($s, $i) {
                while (true) {
                    Tui::sleep(0.1);
                    if (!$s->paused[$i]) {
                        $s->p[$i] = fmod($s->p[$i] + 0.01, 1.0);
                    }
                }
            });
        }
        $terminal = Tui::headless(function () use ($s) {
            $rows = [];
            for ($i = 0; $i < 50; $i++) {
                $rows[] = new Row([
                    new Text("job $i", width: 8),
                    new ProgressBar($s->p[$i]),
                    new Button($s->paused[$i] ? 'Resume' : 'Pause', fn() => $s->paused[$i] = !$s->paused[$i]),
                    new Button('Log'),
                ], gap: 1, onKey: ['p' => fn() => $s->paused[$i] = !$s->paused[$i]], onFocus: fn() => $s->current = $i);
            }
            return new Column($rows);
        }, 120, 60);
        fiber\sleep(1);
        $times = [];
        $end = microtime(true) + $seconds;
        while (microtime(true) < $end) {
            $t0 = hrtime(true);
            $terminal->press('tab');
            $terminal->settle();        // (as a program measures it: until the screen is up to date)
            $times[] = (hrtime(true) - $t0) / 1e6;
            fiber\sleep(0.05);          // (a person's pace: the CPU may clock down in between)
        }
        sort($times);
        printf("keys: a Tab to a settled screen in %.1f ms (median), %.1f ms at worst, over %d keys\n", $times[intdiv(count($times), 2)], end($times), count($times));
        $terminal->detach();            // (the coroutines end with the program)
    });
    exit;
}
fiber\run(function () use ($mode, $seconds) {
    $terminal = null;
    if ($mode === 'bare') {
        $end = microtime(true) + $seconds + 1;
        for ($i = 0; $i < 50; $i++) {
            fiber\go(function () use ($i, $end) {
                fiber\sleep($i * 0.002);     // phases spread over the tick, as the rows' are
                while (microtime(true) < $end) {
                    fiber\sleep(0.1);
                }
            });
        }
    } else {
        $terminal = Tui::headless(new Monitor(), 120, 60);
    }
    fiber\sleep(1);     // past the first render
    $cpu = static function (): float {
        $r = getrusage();
        return $r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec'] + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1e6;
    };
    [$cpu0, $wall0] = [$cpu(), microtime(true)];
    fiber\sleep($seconds);
    [$used, $wall] = [$cpu() - $cpu0, microtime(true) - $wall0];
    printf("%s: %.1f%% of a core over %.1f s (%d garbage collections in the process)\n", $mode, 100 * $used / $wall, $wall, gc_status()['runs']);
    $terminal?->detach();
});
