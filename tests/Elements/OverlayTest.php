<?php

/*
 * Overlays (SPEC 8.5): anything floats, over everything, inside the root; placed by Placement,
 * placed again after the layout of a resize, cancelled when the anchor is gone; modal ones take
 * all input, others close on Escape, a click outside, or the focus moving elsewhere.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;
use phasync\Tui\Overlay;
use phasync\Tui\Rect;

use function fiber\await;

/** A one-line field that opens a list of $n items under (or over) itself. */
final class Opener extends Component
{
    public ?Overlay $open = null;
    public array $results = [];
    public array $keys = [];

    public function __construct(public int $n = 30, public bool $modal = false)
    {
    }

    protected function bindings(): array
    {
        return ['f4' => 'show', 'k' => 'own'];
    }

    public function own(): void
    {
        $this->keys[] = 'k';
    }

    public function show(): void
    {
        $items = [];
        for ($i = 0; $i < $this->n; $i++) {
            $items["i$i"] = "item $i";
        }
        $this->open = $this->overlay(new Select($items, onActivate: fn($key) => $this->open->close($key)), modal: $this->modal);
        $o = $this->open;
        $this->go(function () use ($o) {
            $this->results[] = await($o->closed());
        });
    }

    public function list(): ?Select
    {
        $c = $this->open?->content;
        return $c instanceof Select ? $c : null;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, 1];
    }

    protected function render(): null
    {
        $this->surface->write("\e[2J\e[H[field]");
        return null;
    }
}

/** A screen with filler above an Opener and a field beside it. */
final class OverlayScreen extends Component
{
    public bool $showOpener = true;

    public function __construct(public int $above = 20, public int $n = 30, public bool $modal = false)
    {
    }

    public function opener(): Opener
    {
        return $this->child('opener', fn() => new Opener($this->n, $this->modal));
    }

    public function other(): Editor
    {
        return $this->child('other', fn() => new Editor(maxLines: 1));
    }

    protected function render(): Element
    {
        return new Column([
            new Text(implode("\n", array_fill(0, $this->above, 'filler')), height: $this->above),
            new Row([$this->showOpener ? $this->opener()->with(width: 12) : new Text('gone', width: 12), $this->other()], height: 1),
            new Text('', height: '1fr'),
        ]);
    }
}

/** The rows of the screen. */
function screen(FakeTerminal $t): array
{
    return explode("\n", $t->text());
}

it('opens a list below its anchor, above it near the bottom, shrinks and scrolls it when neither side fits, and keeps it inside the root', function () {
    $app = new OverlayScreen(above: 2, n: 5);
    mountOn($app, 40, 12, function (FakeTerminal $t, OverlayScreen $app) {
        frame();
        $app->opener()->focus();
        $t->press('f4');
        frame();
        // below the field at row 2: rows 3..7
        expect(screen($t)[3])->toStartWith('item 0')->and(screen($t)[7])->toStartWith('item 4');
        $app->opener()->open->close();
    });
    $app = new OverlayScreen(above: 9, n: 5);
    mountOn($app, 40, 12, function (FakeTerminal $t, OverlayScreen $app) {
        frame();
        $app->opener()->focus();
        $t->press('f4');
        frame();
        // the field is on row 9 of 12: the list goes above it, rows 4..8
        expect(screen($t)[4])->toStartWith('item 0')->and(screen($t)[8])->toStartWith('item 4')->and(screen($t)[9])->toStartWith('[field]');
    });
    $app = new OverlayScreen(above: 7, n: 30);
    mountOn($app, 40, 12, function (FakeTerminal $t, OverlayScreen $app) {
        frame();
        $app->opener()->focus();
        $t->press('f4');
        frame();
        // 30 items, 7 rows above, 4 below: above, shrunk to 7 rows, scrolling
        expect(screen($t)[0])->toStartWith('item 0')->and(screen($t)[6])->toStartWith('item 6')->and(screen($t)[7])->toStartWith('[field]');
        $t->press('end');
        frame();
        expect(screen($t)[6])->toStartWith('item 29')->and($app->opener()->list()->current())->toBe('i29');
    });
});

it('places a dropdown at the bottom again when the terminal shrinks under it and grows again, keeping its state', function () {
    $app = new OverlayScreen(above: 6, n: 8);
    mountOn($app, 40, 20, function (FakeTerminal $t, OverlayScreen $app) {
        frame();
        $app->opener()->focus();
        $t->press('f4');
        frame();
        expect(screen($t)[7])->toStartWith('item 0');       // below the field (row 6)
        $t->press('down', 'down', 'down');
        $t->resize(40, 10);                                 // 3 rows below the field, 6 above
        frame();
        $rows = screen($t);
        expect(count($rows))->toBe(10)->and($rows[6])->toStartWith('[field]')
            ->and($rows[0])->toStartWith('item ')->and(implode("\n", $rows))->toContain('item 3')
            ->and($app->opener()->list()->current())->toBe('i3');
        $t->resize(40, 7);                                  // the field on the last row: 6 above
        frame();
        expect(screen($t)[6])->toStartWith('[field]')->and(implode("\n", screen($t)))->toContain('item 3');
        $t->resize(40, 20);
        frame();
        expect(screen($t)[7])->toStartWith('item 0')->and(screen($t)[14])->toStartWith('item 7')->and($app->opener()->list()->current())->toBe('i3');
        $t->press('enter');
        frame();
        expect($app->opener()->results)->toBe(['i3'])->and($t->text())->not->toContain('item 0');
    });
});

it('cancels an overlay whose anchor is no longer shown', function () {
    $app = new OverlayScreen(above: 2, n: 3);
    mountOn($app, 40, 12, function (FakeTerminal $t, OverlayScreen $app) {
        frame();
        $opener = $app->opener();
        $opener->focus();
        $t->press('f4');
        frame();
        $app->showOpener = false;
        $app->requestRender();
        frame();
        frame();
        expect($opener->results)->toBe([null])->and($t->text())->not->toContain('item 0')->and($t->text())->toContain('gone');
    });
});

it('closes a non-modal overlay with Escape, a click outside, and the focus moving elsewhere; the focus goes back', function () {
    $app = new OverlayScreen(above: 2, n: 3);
    mountOn($app, 40, 12, function (FakeTerminal $t, OverlayScreen $app) {
        frame();
        $opener = $app->opener();
        $opener->focus();
        $t->press('f4');
        frame();
        expect($opener->hasFocus())->toBeTrue()->and($opener->list()->hasFocus())->toBeTrue();
        $t->press('escape');
        frame();
        expect($opener->results)->toBe([null])->and($t->text())->not->toContain('item 0')->and($opener->hasFocus())->toBeTrue();

        $t->press('f4');
        frame();
        $t->click(30, 10);                      // outside the list and the field
        frame();
        expect($opener->results)->toBe([null, null])->and($t->text())->not->toContain('item 0');

        $opener->focus();
        $t->press('f4');
        frame();
        $t->click(1, 4);                        // on the list: picks
        frame();
        expect($t->text())->toContain('item 0');
        $app->other()->focus();                 // the focus moves elsewhere
        frame();
        expect($opener->results)->toBe([null, null, null])->and($t->text())->not->toContain('item 0');
    });
});

it('takes all input with a modal overlay: clicks outside reach nothing, the opener\'s keys wait', function () {
    $app = new OverlayScreen(above: 2, n: 3, modal: true);
    mountOn($app, 40, 12, function (FakeTerminal $t, OverlayScreen $app) {
        frame();
        $opener = $app->opener();
        $opener->focus();
        $t->press('k');
        expect($opener->keys)->toBe(['k']);
        $t->press('f4');
        frame();
        $t->click(20, 2);                       // the other field: not focused, nothing closes
        frame();
        expect($app->other()->hasFocus())->toBeFalse()->and($t->text())->toContain('item 0');
        $t->press('k');                         // the list filters, the opener does not see it
        expect($opener->keys)->toBe(['k'])->and($opener->list()->filter())->toBe('k');
        $t->press('escape', 'escape');          // the filter, then: a modal list stays
        frame();
        expect($t->text())->toContain('item 0');
        $opener->open->close('done');
        frame();
        expect($opener->results)->toBe(['done'])->and($opener->hasFocus())->toBeTrue();
    });
});

it('keeps later overlays above earlier ones across renders', function () {
    $app = new class () extends Component {
        public array $overlays = [];

        public function open(string $text, Rect $at): void
        {
            $this->overlays[] = $this->overlay(new Box(new Text($text), style: ['border' => 'single']), place: $at);
        }

        protected function render(): Element
        {
            return new Text(str_repeat('.', 40 * 10));
        }
    };
    mountOn($app, 40, 10, function (FakeTerminal $t, Component $app) {
        frame();
        $app->open('first', new Rect(2, 2, 10, 3));
        $app->open('second', new Rect(5, 3, 10, 3));
        frame();
        expect(screen($t)[4])->toBe('..└──│second  │.........................');
        $app->requestRender();
        $t->resize(40, 9);
        frame();
        expect(screen($t)[4])->toBe('..└──│second  │.........................');
        $app->overlays[1]->close();
        frame();
        expect(screen($t)[4])->toBe('..└────────┘............................');
    });
});

it('does not disturb a Terminal pane it covers, which shows its output again when it closes', function () {
    $app = new class () extends Component {
        public Terminal $term;
        public ?Overlay $tip = null;

        protected function render(): Element
        {
            $this->term = $this->child('t', fn() => new Terminal(['sh', '-c', 'for i in 1 2 3 4 5 6; do echo line$i; sleep 0.05; done; sleep 5']));
            return new Column([$this->term]);
        }

        public function tip(): void
        {
            $this->tip = $this->overlay(new Box(new Text('a tip over the pane')), place: new Rect(0, 1, 24, 3));
        }
    };
    mountOn($app, 30, 8, function (FakeTerminal $t, Component $app) {
        expect($t->waitFor('line1'))->toBeTrue();
        $app->tip();
        expect($t->waitFor('line6'))->toBeTrue();
        expect($t->text())->toContain('a tip over the pane')->and($app->term->surface->text())->toContain("line2\nline3");
        $app->tip->close();
        frame();
        $rows = screen($t);
        expect(array_slice($rows, 0, 6))->toBe(['line1', 'line2', 'line3', 'line4', 'line5', 'line6']);
    });
});

it('shows any element, at most as large as the root', function () {
    $app = new class () extends Component {
        public function tip(): Overlay
        {
            return $this->overlay(new Text(str_repeat('long words ', 20)), place: 'center');
        }

        protected function render(): Element
        {
            return new Text('under');
        }
    };
    mountOn($app, 20, 4, function (FakeTerminal $t, Component $app) {
        frame();
        $tip = $app->tip();
        frame();
        expect($tip->rect->width)->toBe(20)->and($tip->rect->height)->toBe(4)->and(screen($t)[0])->toBe('long words long');
    });
});
