<?php

/*
 * Regressions found reviewing the widgets added for 0.0.6, through FakeTerminal.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Badge;
use phasync\Tui\Component\BarChart;
use phasync\Tui\Component\DirectoryTree;
use phasync\Tui\Component\Heatmap;
use phasync\Tui\Component\Link;
use phasync\Tui\Component\Menu;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Component\RadioGroup;
use phasync\Tui\Component\Rule;
use phasync\Tui\Component\Slider;
use phasync\Tui\Component\Toast;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text as TextEl;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;
use phasync\Tui\Rect;

it('re-clamps a Slider when a parent render narrows its range, and sets the value only from the track', function () {
    $max = 100;
    $seen = new ArrayObject();
    $app = new WidgetScreen(function () use (&$max, $seen) {
        return new Column([new Slider(0, $max, value: 90, label: 'vol', onChange: fn($v) => $seen[] = $v)]);
    });
    mountOn($app, 20, 1, function (FakeTerminal $t, WidgetScreen $app) use (&$max, $seen) {
        $t->settle();
        $max = 50;
        $app->requestRender();
        $t->settle();
        expect(screenRows($t)[0])->toEndWith(' 50');
        $t->click(1, 0);                       // on the label: no change
        $t->click(19, 0);                      // on the number: no change
        expect($seen->getArrayCopy())->toBe([]);
        $t->click(10, 0);                      // on the track
        expect($seen->count())->toBe(1);
    });
});

it('closes a Badge on its real × in a stretched column, not at the far right', function () {
    $closed = new ArrayObject();
    $app = new WidgetScreen(fn() => new Column([new Badge('php', closable: true, onClose: function () use ($closed) { $closed[] = 1; })]));
    mountOn($app, 20, 1, function (FakeTerminal $t) use ($closed) {
        $t->settle();
        $t->click(19, 0);
        expect(count($closed))->toBe(0);
        $t->click(5, 0);                       // ' php ×'
        expect(count($closed))->toBe(1);
    });
});

it('activates a Link on release inside its text only', function () {
    $seen = new ArrayObject();
    $app = new WidgetScreen(fn() => new Column([new Link('https://a.test', 'abc', function ($url) use ($seen) { $seen[] = $url; })]));
    mountOn($app, 20, 1, function (FakeTerminal $t) use ($seen) {
        $t->settle();
        $t->click(15, 0);                      // far right of the stretched row
        $t->mouse(new Mouse(Mouse::PRESS, 1, 0));
        expect(count($seen))->toBe(0);         // a press alone does not activate
        $t->mouse(new Mouse(Mouse::RELEASE, 1, 0));
        expect($seen->getArrayCopy())->toBe(['https://a.test']);
    });
});

final class MenuOwner extends Component
{
    public function open(): \phasync\Tui\Overlay
    {
        return Menu::popup($this, ['a' => 'Alpha', 'b' => 'Beta'], new Rect(2, 1, 1, 1));
    }

    protected function render(): \phasync\Tui\Element
    {
        return new TextEl('hello');
    }
}

it('closes a popup menu on a click outside it', function () {
    $app = new MenuOwner();
    mountOn($app, 30, 8, function (FakeTerminal $t, MenuOwner $app) {
        $t->settle();
        $overlay = $app->open();
        $t->settle();
        $t->click(25, 7);
        $t->settle();
        expect($overlay->isOpen())->toBeFalse();
    });
});

it('selects only drawn Heatmap cells', function () {
    $seen = new ArrayObject();
    $app = new WidgetScreen(fn() => new Column([new Heatmap([[1, 2, 3, 4]], cellWidth: 3, onSelect: function ($r, $c) use ($seen) { $seen[] = [$r, $c]; })]));
    mountOn($app, 8, 1, function (FakeTerminal $t) use ($seen) {
        $t->settle();
        $t->click(7, 0);                       // the two columns right of the second cell
        expect($seen->getArrayCopy())->toBe([]);
        $t->click(4, 0);
        expect($seen->getArrayCopy())->toBe([[0, 1]]);
    });
});

it('keeps a RadioGroup valid when its options shrink', function () {
    $options = ['a' => 'A', 'b' => 'B', 'c' => 'C'];
    $app = new WidgetScreen(function () use (&$options) { return new Column([new RadioGroup($options, value: 'c', key: 'r')]); });
    mountOn($app, 12, 3, function (FakeTerminal $t, WidgetScreen $app) use (&$options) {
        $t->settle();
        $options = ['a' => 'A'];
        $app->requestRender();
        $t->settle();
        $group = $app->children()['r'];
        expect($group->pick())->toBeTrue();
        expect($group->value())->toBe('a');
    });
});

it('cuts BarChart labels and values to the room they have', function () {
    $app = new WidgetScreen(fn() => new Column([new BarChart(['a-very-long-label-here' => 5, 'b' => 10])]));
    mountOn($app, 12, 2, function (FakeTerminal $t) {
        $t->settle();
        $rows = screenRows($t);
        expect(count($rows))->toBe(2);
        expect(mb_strwidth($rows[0]))->toBeLessThanOrEqual(12);
        expect($rows[0])->toStartWith('a-ver…');
        expect($rows[1])->toStartWith('b      ');
    });
    $app = new WidgetScreen(fn() => new Column([new BarChart(['x' => 12345], vertical: true)]));
    mountOn($app, 8, 4, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t)[0])->toBe('123');
    });
});

it('keeps one Toast stack per position', function () {
    $app = new WidgetScreen(fn() => new Column([]));
    mountOn($app, 20, 4, function (FakeTerminal $t) {
        $t->settle();
        Toast::show('one', timeout: 0);
        Toast::show('two', position: 'top-left', timeout: 0);
        $t->settle();
        $rows = screenRows($t);
        expect($rows[0])->toStartWith('▌ ℹ two');
        expect($rows[3])->toContain('▌ ℹ one');
        Toast::clear();
    });
});

it('rejects a ProgressBar or Rule character that is not one column wide', function () {
    expect(fn() => new ProgressBar(0.5, fill: '漢'))->toThrow(InvalidArgumentException::class);
    expect(fn() => new ProgressBar(0.5, empty: 'ab'))->toThrow(InvalidArgumentException::class);
    expect(fn() => new ProgressBar(0.5, empty: ''))->toThrow(InvalidArgumentException::class);
    expect(fn() => new Rule(char: '漢'))->toThrow(InvalidArgumentException::class);
    expect(fn() => new Rule(char: ''))->toThrow(InvalidArgumentException::class);
    expect(new Rule(char: '='))->toBeInstanceOf(Rule::class);
});

it('matches DirectoryTree extensions given with a leading dot', function () {
    $dir = sys_get_temp_dir() . '/tui-ext-' . getmypid();
    mkdir($dir);
    touch("$dir/a.php");
    touch("$dir/b.txt");
    $app = new WidgetScreen(fn() => new Column([new DirectoryTree($dir, extensions: ['.PHP'])]));
    mountOn($app, 20, 4, function (FakeTerminal $t) {
        $t->settle();
        $text = implode("\n", screenRows($t));
        expect($text)->toContain('a.php')->and($text)->not->toContain('b.txt');
    });
    unlink("$dir/a.php");
    unlink("$dir/b.txt");
    rmdir($dir);
});
