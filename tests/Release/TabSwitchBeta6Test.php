<?php

/*
 * TODO-0.0.3-beta6 item 2: switching to a tab that holds a kept, empty Markdown(tail: true)
 * cost 18 ms more than to any other tab at 120x40. The Tabs (as tall as its content) was laid
 * out at the old tab's size first: the Markdown was resized to one row, drawn, resized back
 * and drawn again, and every host repainted its whole window, on every switch, and switching
 * away resized it to one row once more (its rows were lost). Now a component whose size changes
 * with its render is laid out once, at the size its parent gives it next; a window shown again
 * writes only the cells its host does not show already.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Markdown;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

/** A Markdown that counts its renders by size. */
final class CountedMarkdown extends Markdown
{
    /** @var array<string, int> */
    public array $renders = [];

    protected function render(): null
    {
        $size = $this->surface->cols() . 'x' . $this->surface->rows();
        $this->renders[$size] = ($this->renders[$size] ?? 0) + 1;
        return parent::render();
    }
}

/** The shape from use: an owner that calls setTabs() on every render, the tab content kept by the owner. */
final class TabSwitchOwner extends Component
{
    public ?Tabs $tabs = null;

    public function __construct(public Element $content)
    {
    }

    protected function render(): Element
    {
        $this->tabs = $this->child('tabs', fn() => new Tabs(hints: true));
        $this->tabs->setTabs(['a' => ['label' => 'A', 'content' => new Text('first tab')], 'b' => ['label' => 'B', 'content' => $this->content]]);
        return new Column([$this->tabs, new Help()]);
    }
}

/** Ten switches to tab B (and back) on $t; the median time to B in ms. */
function switchTimes(FakeTerminal $t, int $switches = 10): float
{
    $times = [];
    for ($k = 0; $k < $switches; $k++) {
        $a = microtime(true);
        $t->press('alt+2');
        $t->settle();
        $times[] = (microtime(true) - $a) * 1000;
        $t->press('alt+1');
        $t->settle();
    }
    sort($times);
    return $times[intdiv($switches, 2)];
}

it('lays a kept Markdown out once over ten tab switches, at its own size, never resizing it', function (string $text) {
    fiber\run(function () use ($text) {
        $md = new CountedMarkdown($text, tail: true);
        $app = new TabSwitchOwner($md);
        $t = Tui::headless($app, 120, 40);
        $t->settle();
        $t->press('alt+2');
        $t->settle();
        $resizes = 0;
        $md->surface->on('resize', function () use (&$resizes) {
            $resizes++;
        });
        $md->renders = [];
        switchTimes($t);
        $t->press('alt+2');
        $t->settle();
        expect($resizes)->toBe(0)
            ->and(array_keys($md->renders))->toBe(['120x38']);
        if ($text !== '') {
            expect($t->text())->toContain('Notes')->toContain('the second line');     // (its rows were not lost)
        }
        $t->detach();
    });
})->with(['empty' => '', 'with text' => "# Notes\n\nthe first line\n\nthe second line\n"]);

it('switches to a Markdown tab about as fast as to a Text tab', function () {
    $median = [];
    foreach (['md' => fn() => new Markdown(tail: true), 'text' => fn() => new Text('hello')] as $kind => $make) {
        fiber\run(function () use ($kind, $make, &$median) {
            $t = Tui::headless(new TabSwitchOwner($make()), 120, 40);
            $t->settle();
            switchTimes($t, 4);         // (warm)
            $median[$kind] = switchTimes($t);
            $t->detach();
        });
    }
    // about 3.7 ms and 2.8 ms on the machine this was written on (the report: 23 ms and 5 ms)
    expect($median['md'])->toBeLessThan($median['text'] * 2 + 3.0);
});

it('shows a window over cells its host shows already without writing them', function () {
    $host = new Surface(30, 6);
    $host->write("\e[3;1Hsame text");
    $child = new Surface(30, 6);
    $child->write("\e[3;1Hsame text");
    $host->compose();
    $host->takeDamage();
    $host->host($child, 0, 0, 30, 6);
    $host->compose();
    // (the cells were the same: the host wrote none of them, so nothing is damaged up the tree)
    expect($host->takeDamage())->toBe([]);
    $child->write("\e[5;1Hnew");
    $host->compose();
    expect($host->takeDamage())->toBe([4])->and($host->line(4))->toBe('new');
});
