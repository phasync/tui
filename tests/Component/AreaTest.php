<?php

/*
 * A component's rendering depends on its rectangle: the library renders it again when its area
 * (size or place on the root) changed since its last render.
 */

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Layout;
use phasync\Tui\Rect;
use phasync\Tui\Split;
use phasync\Tui\Stack;
use phasync\Tui\Surface;

use function fiber\run;
use function fiber\sleep;

/** Draws its size and where it is on the screen. */
final class WhereAmI extends Component
{
    public int $renders = 0;

    protected function render(): ?Layout
    {
        $this->renders++;
        $a = $this->surface->area();
        $this->surface->write(Ansi::clearScreen() . Ansi::moveTo(0, 0) . "{$this->surface->cols()}x{$this->surface->rows()}@{$a->x},{$a->y}");
        return null;
    }
}

it('renders a component again when a layout moves it without resizing it', function () {
    $app = new class extends Component {
        public int $x = 2;
        public WhereAmI $probe;

        protected function render(): ?Layout
        {
            $this->probe = $this->child('p', fn() => new WhereAmI());
            return (new Stack())->add(new Surface(), '100%', '100%')->add($this->probe->surface, 12, 1, x: $this->x, y: 1);
        }
    };
    run(function () use ($app) {
        $app->start($t = new FakeTerminal(30, 4));
        try {
            expect($t->text())->toContain('12x1@2,1');
            $app->x = 7;
            $app->requestRender();
            frame();
            expect($t->text())->toContain('12x1@7,1');
            $t->resize(40, 4);                      // the root resized, the probe neither moved nor resized
            frame();
            $renders = $app->probe->renders;
            $t->resize(41, 4);
            frame();
            expect($app->probe->renders)->toBe($renders);
        } finally {
            $t->detach();
        }
    });
});

it('renders a component in a nested layout again when the layout around it moves it', function () {
    $app = new class extends Component {
        public int $left = 10;
        public WhereAmI $probe;

        protected function render(): ?Layout
        {
            $this->probe = $this->child('p', fn() => new WhereAmI());
            return Split::horizontal()->add(new Surface(), $this->left)
                ->add(Split::vertical()->add(new Surface(), 1)->add($this->probe->surface));
        }
    };
    run(function () use ($app) {
        $app->start($t = new FakeTerminal(40, 6));
        try {
            expect($t->text())->toContain('30x5@10,1');
            $t->resize(50, 8);
            frame();
            expect($t->text())->toContain('40x7@10,1');
            $app->left = 20;
            $app->requestRender();
            frame();
            expect($t->text())->toContain('30x7@20,1');
        } finally {
            $t->detach();
        }
    });
});

it('renders a component again when a window it is in is moved by hand', function () {
    run(function () {
        $root = new Surface(30, 5);
        $panel = new Surface(20, 3, 0);
        $probe = (new WhereAmI())->start($inner = new Surface(10, 1, 0));
        $panel->host($inner, 1, 1);
        $view = $root->host($panel, 0, 0);
        frame();
        expect($inner->line(0))->toBe('10x1@1,1');
        $view->move(5, 2);                          // the panel moves: the probe's area with it
        frame();
        expect($inner->line(0))->toBe('10x1@6,3');
        $probe->dispose();
    });
});

it('tells area listeners once per change of the tree, with the new rectangle', function () {
    $root = new Surface(30, 5);
    $a = new Surface(5, 1);
    $seen = [];
    $a->on('area', function (Rect $r) use (&$seen) {
        $seen[] = [$r->x, $r->y, $r->width, $r->height];
    });
    $root->setLayout(Split::horizontal()->add(new Surface(), 10)->add($a));
    expect($seen)->toBe([[10, 0, 20, 5]]);
    $root->resize(40, 5);
    expect($seen)->toBe([[10, 0, 20, 5], [10, 0, 30, 5]]);
    $root->viewport($a)->move(12, 0);
    $root->unhost($a);
    expect($seen)->toBe([[10, 0, 20, 5], [10, 0, 30, 5], [12, 0, 28, 5], [0, 0, 30, 5]]);
});
