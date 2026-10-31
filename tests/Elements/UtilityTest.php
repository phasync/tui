<?php

/*
 * Utility classes (SPEC 9.2): each family, the cascade, variants of state and width, colours a
 * terminal does not have, and explain().
 */

use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Style;
use phasync\Tui\Stylesheet;
use phasync\Tui\Tui;

/** A component that renders what its closure gives. */
final class UtilityScreen extends Component
{
    public function __construct(public \Closure $tree)
    {
    }

    protected function render(): Element
    {
        return ($this->tree)($this);
    }
}

it('resolves the colour and text families', function () {
    $s = new Stylesheet();
    $c = fn(string $class) => $s->compute(new Text('x', class: $class));
    expect($c('text-sky-400')->text->fg)->toBe(Color::hex('#38bdf8'))
        ->and($c('bg-slate-800')->text->bg)->toBe(Color::hex('#1e293b'))
        ->and($c('text-[#ff5500]')->text->fg)->toBe(Color::hex('#ff5500'))
        ->and($c('text-208')->text->fg)->toBe(208)
        ->and($c('text-bright-blue bg-red')->text)->toBe(Style::of(Color::BRIGHT_BLUE, Color::RED))
        ->and($c('bold italic')->text->attrs)->toBe(Style::BOLD | Style::ITALIC)
        ->and($c('dim underline strike inverse')->text->attrs)->toBe(Style::DIM | Style::UNDERLINE | Style::STRIKE | Style::INVERSE)
        ->and($c('text-center')->align)->toBe('center')
        ->and($c('text-right')->align)->toBe('right')
        ->and($c('truncate')->wrap)->toBeFalse()
        ->and($c('nowrap')->wrap)->toBeFalse()
        ->and($c('truncate wrap')->wrap)->toBeTrue();
});

it('resolves spacing, border, size and layout classes to stylesheet properties', function () {
    $s = new Stylesheet();
    $c = fn(string $class) => $s->compute(new Column([], class: $class));
    expect($c('p-2')->padding)->toBe([2, 2, 2, 2])
        ->and($c('px-1 py-2')->padding)->toBe([2, 1, 2, 1])
        ->and($c('p-1 pt-3 pl-0')->padding)->toBe([3, 1, 1, 0])
        ->and($c('m-1 mr-2')->margin)->toBe([1, 2, 1, 1])
        ->and($c('gap-2')->gap)->toBe(2)
        ->and($c('border')->border)->toBe('single')
        ->and($c('border-round')->border)->toBe('round')
        ->and($c('border border-double')->border)->toBe('double')
        ->and($c('border-heavy border-none')->border)->toBeFalse()
        ->and($c('border-ascii border-emerald-500')->borderStyle->fg)->toBe(Color::hex('#10b981'))
        ->and($c('title-center')->titleAlign)->toBe('center')
        ->and($c('w-20 h-3')->width)->toBe(20)
        ->and($c('w-1/2')->width)->toBe('50%')
        ->and($c('w-1/3')->width)->toBe('33.33%')
        ->and($c('w-full')->width)->toBe('100%')
        ->and($c('grow')->grow)->toBe(1.0)
        ->and($c('grow-3')->grow)->toBe(3.0)
        ->and($c('shrink-0')->shrink)->toBe(0.0)
        ->and($c('items-center')->alignItems)->toBe('center')
        ->and($c('justify-between')->justify)->toBe('between')
        ->and($c('hidden')->hidden)->toBeTrue()
        ->and($c('hidden block')->hidden)->toBeFalse()
        ->and($c('flex-row')->direction)->toBe('row')
        ->and($c('flex-col')->direction)->toBe('column');
    // a theme sets the same with properties
    $theme = new Stylesheet(['Column' => ['padding-left' => 2, 'gap' => 1, 'flex-direction' => 'row', 'title-align' => 'right']]);
    $t = $theme->compute(new Column([]));
    expect($t->padding)->toBe([0, 0, 0, 2])->and($t->gap)->toBe(1)->and($t->direction)->toBe('row')->and($t->titleAlign)->toBe('right');
});

it('lays out with spacing, borders, titles and sizes from classes', function () {
    $app = new UtilityScreen(fn() => new Column([
        new Box(new Text('hi'), 'log', class: 'border-double title-center p-1 w-20'),
        new Row([new Text('a', class: 'w-1/2 bg-red'), new Text('b')]),
        new Text('gone', class: 'hidden'),
        new Text('m', class: 'ml-3'),
    ], class: 'gap-1'));
    mountOn($app, 20, 10, function (FakeTerminal $t) {
        $rows = screenRows($t);
        expect($rows[0])->toBe('╔══════ log ═══════╗')
            ->and($rows[1])->toBe('║                  ║')
            ->and($rows[2])->toBe('║ hi               ║')
            ->and($rows[4])->toBe('╚══════════════════╝')
            ->and($rows[5])->toBe('')                          // gap-1
            ->and($rows[6])->toBe('a         b')                 // w-1/2 of 20
            ->and($rows[8])->toBe('   m')                       // the hidden text left no row
            ->and($t->text())->not->toContain('gone');
        expect($t->surface->cell(9, 6)->style->bg)->toBe(Color::RED)->and($t->surface->cell(10, 6)->style->bg)->toBe(Color::DEFAULT);
    });
});

it('grows, keeps sizes with shrink-0, aligns items across and justifies along', function () {
    $app = new UtilityScreen(fn() => new Column([
        new Row([new Text('[a]'), new Box(new Text('g'), class: 'grow'), new Text('[b]')]),
        new Row([new Text('left'), new Text('right')], class: 'justify-between'),
        new Row([new Text('mid')], class: 'justify-center'),
        new Column([new Text('c')], class: 'items-center'),
        new Column([new Text('e')], class: 'items-end'),
        new Row([new Text('aaaaaaaaaaaaaaaa'), new Text('keep', class: 'shrink-0')]),
    ]));
    mountOn($app, 20, 10, function (FakeTerminal $t) {
        $rows = screenRows($t);
        expect($rows[0])->toBe('[a]╭────────────╮[b]')
            ->and($rows[1])->toBe('   │g           │')
            ->and($rows[3])->toBe('left           right')
            ->and($rows[4])->toBe('        mid')
            ->and($rows[5])->toBe('         c')
            ->and($rows[6])->toBe('                   e')
            ->and($rows[7])->toEndWith('keep');
    });
});

it('cascades the stylesheet rules, then the classes in the order written, then style:', function () {
    $s = new Stylesheet(['Text' => ['color' => 'red', 'bold' => true], '#x' => ['color' => 'green']]);
    expect($s->compute(new Text('x', id: 'x', class: 'text-blue'))->text->fg)->toBe(Color::BLUE)       // a class over an #id rule
        ->and($s->compute(new Text('x', class: 'text-blue text-yellow'))->text->fg)->toBe(Color::YELLOW) // the later class
        ->and($s->compute(new Text('x', class: 'text-yellow text-blue'))->text->fg)->toBe(Color::BLUE)
        ->and($s->compute(new Text('x', class: 'text-blue', style: ['color' => 'cyan']))->text->fg)->toBe(Color::CYAN)
        ->and($s->compute(new Text('x', class: 'text-blue'))->text->attrs)->toBe(Style::BOLD);           // what the class does not set stays
});

it('ignores a class that is no utility, and explain() tells what resolved', function () {
    $s = Stylesheet::default()->with(['Text.title' => ['underline' => true]]);
    $text = new Text('x', class: 'title bold no-such-thing md:italic focus:bg-sky-800');
    expect($s->compute($text, ['sm'])->text->attrs)->toBe(Style::BOLD | Style::UNDERLINE);
    $why = $s->explain($text, ['md']);
    expect($why)->toContain("rule 'Text.title'")
        ->toContain("class 'title': not a utility")
        ->toContain("class 'no-such-thing': not a utility")
        ->toContain("class 'bold': bold: true")
        ->toContain("class 'md:italic': italic: true (in force)")
        ->toContain("class 'focus:bg-sky-800': background: sky-800 (not in force: needs focused)")
        ->toContain('= bold italic underline');
});

it('switches flex-col md:flex-row at a resize across 100 columns', function () {
    $app = new UtilityScreen(fn() => new Row([new Text('one'), new Text('two')], class: 'flex-col md:flex-row'));
    mountOn($app, 120, 4, function (FakeTerminal $t) {
        expect(screenRows($t)[0])->toBe('one')->and(screenRows($t)[1])->toBe('two');      // 120: lg, a column
        $t->resize(80, 4);
        $t->settle();
        expect(screenRows($t)[0])->toBe('onetwo');                                          // 80: md, a row
        $t->resize(101, 4);
        $t->settle();
        expect(screenRows($t)[0])->toBe('one');
        $t->resize(50, 4);
        $t->settle();
        expect(screenRows($t)[0])->toBe('one');                                             // sm: the class without a variant
    });
});

it('draws a truecolour class in the nearest colour a 16- or 256-colour terminal has', function () {
    foreach ([[Color::DEPTH_16, '91'], [Color::DEPTH_256, '38;5;202'], [Color::DEPTH_TRUECOLOR, '38;2;255;85;0']] as [$depth, $sgr]) {
        \fiber\run(function () use ($depth, $sgr) {
            $t = Tui::headless(new Text('hot', class: 'text-[#ff5500]'), 10, 2);
            $t->setColors($depth);
            $t->settle();
            expect($t->output())->toContain("\e[0;{$sgr}mhot")
                ->and($t->surface->cell(0, 0)->style->fg)->toBe($depth === Color::DEPTH_16 ? Color::BRIGHT_RED : ($depth === 256 ? 202 : Color::hex('#ff5500')));
            $t->detach();
        });
    }
    expect((new FakeTerminal(10, 2, colors: 16))->colors())->toBe(16)
        ->and(Color::nearest(Color::parse('[#ff5500]'), 16))->toBe(Color::BRIGHT_RED);
});

it('applies focus:bg-sky-800 to the focused element only', function () {
    $app = new UtilityScreen(fn() => new Column([
        new Button('One', class: 'focus:bg-sky-800'),
        new Button('Two', class: 'focus:bg-sky-800'),
    ]));
    mountOn($app, 20, 3, function (FakeTerminal $t) {
        $sky = Color::hex('#075985');
        $t->settle();
        expect($t->surface->cell(0, 0)->style->bg)->toBe($sky)->and($t->surface->cell(0, 1)->style->bg)->toBe(Color::DEFAULT);
        $t->press('tab');
        $t->settle();
        expect($t->surface->cell(0, 0)->style->bg)->toBe(Color::DEFAULT)->and($t->surface->cell(0, 1)->style->bg)->toBe($sky);
    });
});
