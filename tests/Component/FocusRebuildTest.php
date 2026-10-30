<?php

/*
 * Focus follows the surface, whatever render() rebuilds: a focus request made on a surface
 * survives new layout objects, new sibling surfaces and a bordered, titled viewport around it,
 * and hasFocus() and key delivery read the same path.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\TextInput;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Key;
use phasync\Tui\Layout;
use phasync\Tui\Split;
use phasync\Tui\Stack;
use phasync\Tui\Surface;

use function fiber\run;
use function fiber\sleep;

/** The shape from use: a fresh Stack > Split > plain Surface per render, the dialog added directly with title:. */
final class RebuildingApp extends Component
{
    public ?TextInput $dialog = null;
    public int $renders = 0;

    protected function mount(): void
    {
        $this->child('main', fn() => new TextInput())->focus();
    }

    protected function render(): ?Layout
    {
        $this->renders++;
        $header = new Surface();
        $header->write('header ' . $this->renders);
        $main = $this->child('main', fn() => new TextInput());
        $layout = (new Stack())->add(Split::vertical()->add($header, 1)->add($main->surface));
        if ($this->dialog !== null) {
            $layout->add($this->dialog->surface, 20, 3, title: 'Dialog');   // added directly
        }
        return $layout;
    }

    protected function onKey(Key $key): bool
    {
        if ($key->is('ctrl+o')) {
            $this->dialog = $this->child('dialog', fn() => new TextInput());
            $this->dialog->focus();
            $this->requestRender();
            return true;
        }
        return false;
    }

    public function main(): TextInput
    {
        return $this->child('main');
    }
}

it('keeps the focus on a dialog shown by a render that builds new layouts, with a title around it', function () {
    run(function () {
        $app = (new RebuildingApp())->start($t = new FakeTerminal(40, 10));
        try {
            $t->type('m');
            $t->press('ctrl+o');
            $t->type('ab');                         // before the frame
            expect($app->dialog->value())->toBe('ab')
                ->and($app->dialog->hasFocus())->toBeTrue()
                ->and($app->main()->hasFocus())->toBeFalse();
            sleep(0.01);
            expect($t->text())->toContain('Dialog');
            $t->type('cd');                         // after the frame that shows it
            expect($app->dialog->value())->toBe('abcd')
                ->and($app->main()->value())->toBe('m')
                ->and($app->dialog->hasFocus())->toBeTrue()
                ->and($app->main()->hasFocus())->toBeFalse();
            // more renders, each with new layout objects and a new header surface
            for ($i = 0; $i < 3; $i++) {
                $app->requestRender();
                sleep(0.01);
                $t->type((string) $i);
            }
            expect($app->dialog->value())->toBe('abcd012')
                ->and($app->dialog->hasFocus())->toBeTrue()
                ->and($app->main()->value())->toBe('m');
        } finally {
            $t->detach();
        }
    });
});

/**
 * A random tree of new layout objects around $items: Splits and Stacks nested up to three deep,
 * with a border or a title on some windows. Uses mt_rand(), so seed it.
 *
 * @param list<Surface> $items
 */
function randomTree(array $items, int $depth = 0): Layout
{
    $nodes = [];
    while ($items !== []) {
        $n = $depth < 3 && \count($items) > 1 && mt_rand(0, 2) === 0 ? mt_rand(1, \count($items)) : 1;
        $group = array_splice($items, 0, $n);
        $nodes[] = $n > 1 || mt_rand(0, 4) === 0 ? randomTree($group, $depth + 1) : $group[0];
    }
    $title = fn() => mt_rand(0, 2) === 0 ? 'T' . mt_rand(0, 9) : null;
    if (mt_rand(0, 1) === 0) {
        $layout = mt_rand(0, 1) ? Split::horizontal((bool) mt_rand(0, 1)) : Split::vertical((bool) mt_rand(0, 1));
        foreach ($nodes as $node) {
            $layout->add($node, '1fr', border: mt_rand(0, 3) === 0 ? true : null, title: $title());
        }
        return $layout;
    }
    $layout = new Stack();
    foreach ($nodes as $i => $node) {
        $layout->add($node, $i === 0 ? '100%' : '50%', $i === 0 ? '100%' : '50%', border: mt_rand(0, 2) === 0 ? true : null, title: $title());
    }
    return $layout;
}

/** A dialog with a layout of its own and a text field it focuses in mount(). */
final class FieldDialog extends Component
{
    public TextInput $field;

    protected function mount(): void
    {
        $this->field = $this->child('field', fn() => new TextInput());
        $this->field->focus();
    }

    protected function render(): ?Layout
    {
        $label = new Surface();
        $label->write('Name:');
        return Split::vertical()->add($label, 1)->add($this->field->surface);
    }
}

/**
 * Every render builds a random tree of new layout objects around some of its inputs, new plain
 * surfaces and the focused leaf (added through child() or directly, by chance). Ctrl-O creates
 * the leaf and focuses it from the key handler, before a render shows it.
 */
final class RandomLayoutPane extends Component
{
    public int $seed = 1;
    public ?Component $leaf = null;
    /** @var array<string, TextInput> */
    public array $others = [];

    public function __construct(private readonly string $leafKind)
    {
    }

    protected function mount(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->others[$key] = $this->child($key, fn() => new TextInput());
        }
        $this->others['b']->focus();
    }

    protected function render(): ?Layout
    {
        mt_srand($this->seed);
        $items = [];
        foreach ($this->others as $key => $input) {
            $this->child($key);                     // kept, shown or not
            if (mt_rand(0, 2) > 0) {
                $items[] = $input->surface;
            }
        }
        for ($i = mt_rand(0, 2); $i > 0; $i--) {
            $s = new Surface();
            $s->write('plain');
            $items[] = $s;
        }
        if ($this->leaf !== null) {
            $leaf = mt_rand(0, 1) ? $this->leaf->surface : $this->child('leaf')->surface;
            array_splice($items, mt_rand(0, \count($items)), 0, [$leaf]);
        }
        return randomTree($items);
    }

    protected function onKey(Key $key): bool
    {
        if ($key->is('ctrl+o')) {
            $this->leaf = $this->child('leaf', fn() => $this->leafKind === 'dialog' ? new FieldDialog() : new TextInput());
            $this->leaf->focus();
            $this->requestRender();
            return true;
        }
        return false;
    }

    /** The text field the keys are to reach. */
    public function target(): TextInput
    {
        return $this->leaf instanceof FieldDialog ? $this->leaf->field : $this->leaf;
    }
}

/** A pane shown in a random tree of its parent's, rebuilt on every render of the parent too. */
final class RandomLayoutParent extends Component
{
    public int $seed = 1;

    public function __construct(public readonly RandomLayoutPane $pane)
    {
    }

    protected function render(): ?Layout
    {
        mt_srand($this->seed + 1000);
        $items = [$this->child('pane', fn() => $this->pane)->surface];
        for ($i = mt_rand(0, 2); $i > 0; $i--) {
            $items[] = new Surface();
        }
        shuffle($items);
        return randomTree($items);
    }
}

dataset('focused leaves', [
    'a text field, the pane at the root' => ['input', false],
    'a dialog focusing its field, the pane at the root' => ['dialog', false],
    'a text field, the pane in a parent rebuilding its layouts too' => ['input', true],
    'a dialog focusing its field, the pane in a parent rebuilding its layouts too' => ['dialog', true],
]);

it('delivers the keys to the focused leaf through any rebuild of the layouts above it', function (string $kind, bool $nested) {
    run(function () use ($kind, $nested) {
        $pane = new RandomLayoutPane($kind);
        $parent = $nested ? new RandomLayoutParent($pane) : null;
        ($parent ?? $pane)->start($t = new FakeTerminal(80, 24));
        try {
            $pane->focus();
            $t->press('ctrl+o');                    // creates and focuses the leaf, not shown yet
            $expected = '';
            for ($round = 1; $round <= 60; $round++) {
                $c = \chr(\ord('a') + $round % 26);
                $t->type($c);
                $expected .= $c;
                expect($pane->target()->value())->toBe($expected, "round $round")
                    ->and($pane->target()->hasFocus())->toBeTrue("leaf hasFocus, round $round")
                    ->and($pane->leaf->hasFocus())->toBeTrue("leaf hasFocus, round $round");
                foreach ($pane->others as $key => $input) {
                    expect($input->isMounted())->toBeTrue("input $key mounted, round $round")
                        ->and($input->value())->toBe('', "input $key, round $round")
                        ->and($input->hasFocus())->toBeFalse("input $key hasFocus, round $round");
                }
                // rebuild the pane's layouts, the parent's, or both
                $pane->seed = $round;
                if ($parent !== null && $round % 3 !== 0) {
                    $parent->seed = $round;
                    $parent->requestRender();
                }
                if ($parent === null || $round % 3 !== 1) {
                    $pane->requestRender();
                }
                sleep(0.002);
            }
        } finally {
            $t->detach();
        }
    });
})->with('focused leaves');

it('keeps the focus on a plain surface through any rebuild of the layouts above it', function () {
    run(function () {
        $root = new Surface(80, 24);
        $leaf = new Surface();
        $others = [new Surface(), new Surface(), new Surface()];
        $leaf->focus();
        for ($round = 1; $round <= 80; $round++) {
            mt_srand($round);
            $items = array_values(array_filter($others, fn() => mt_rand(0, 2) > 0));
            for ($i = mt_rand(0, 2); $i > 0; $i--) {
                $items[] = new Surface();
            }
            array_splice($items, mt_rand(0, \count($items)), 0, [$leaf]);
            $root->setLayout(randomTree($items));
            $root->dispatch(Key::parse('x'));
            expect($leaf->hasFocus())->toBeTrue("round $round")
                ->and($leaf->input->read(100, 0))->toBe('x', "round $round");
            foreach ($others as $i => $other) {
                // (one not shown is a root of its own, so hasFocus() is true there; it must not
                // stay hosted in a part of the tree that is no longer shown)
                expect($other->viewport() === null || !$other->hasFocus())->toBeTrue("other $i, round $round")
                    ->and($other->viewport() === null || $other->root() === $root)->toBeTrue("other $i in the tree, round $round")
                    ->and($other->input->peek())->toBe('');
            }
        }
    });
});
