<?php

/*
 * The stylesheet: selectors, cascade, states, parts, variables, inheritance; and that every
 * styled cell gets there through write().
 */

use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Component\TextInput;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Span;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;
use phasync\Tui\Style;
use phasync\Tui\Stylesheet;
use phasync\Tui\Surface;

/** A component whose render is a closure. */
final class Styled extends Component
{
    public function __construct(public \Closure $tree)
    {
    }

    protected function render(): Element
    {
        return ($this->tree)($this);
    }

    public function get(string $key, ?\Closure $create = null): ?Component
    {
        return $this->child($key, $create);
    }
}

/** A component that shows its own computed style, so `:hover` can be seen. */
final class HoverCard extends Component
{
    protected function render(): Element
    {
        return new Text('card', style: ['inverse' => $this->style()->text->has(Style::INVERSE)]);
    }
}

it('cascades rules by specificity, then order, then the inline style', function () {
    $sheet = new Stylesheet([
        'Text' => ['color' => 'red', 'bold' => true],
        '.note' => ['color' => 'green'],
        'Text.note' => ['italic' => true],
        '#n' => ['color' => 'blue'],
        '*' => ['underline' => true],
        '.late' => ['color' => 'yellow'],
        '.later' => ['color' => 'magenta'],
    ]);
    $plain = $sheet->compute(new Text('x'));
    $note = $sheet->compute(new Text('x', class: 'note'));
    $id = $sheet->compute(new Text('x', id: 'n', class: 'note'));
    $inline = $sheet->compute(new Text('x', id: 'n', class: 'note', style: ['color' => '#102030', 'bold' => false]));
    $order = $sheet->compute(new Text('x', class: 'later late'));
    expect($plain->text)->toBe(Style::of(Color::RED, attrs: Style::BOLD | Style::UNDERLINE))
        ->and($note->text)->toBe(Style::of(Color::GREEN, attrs: Style::BOLD | Style::UNDERLINE | Style::ITALIC))
        ->and($id->text->fg)->toBe(Color::BLUE)
        ->and($inline->text)->toBe(Style::of(Color::hex('#102030'), attrs: Style::UNDERLINE | Style::ITALIC))
        ->and($order->text->fg)->toBe(Color::MAGENTA);
});

it('resolves variables, merges borders by their parts, and reads padding and sizes', function () {
    $sheet = new Stylesheet([
        'Box' => ['border' => 'round $accent', 'padding' => '0 1', 'height' => 5],
        'Box.alert' => ['border' => '$warn'],
        'Box.plain' => ['border' => 'none'],
    ], ['accent' => '$base', 'base' => 'cyan', 'warn' => 'red']);
    $box = $sheet->compute(new Box(new Text('x')));
    $alert = $sheet->compute(new Box(new Text('x'), class: 'alert'));
    expect($box->border)->toBe('round')->and($box->borderStyle->fg)->toBe(Color::CYAN)
        ->and($box->padding)->toBe([0, 1, 0, 1])->and($box->height)->toBe(5)
        ->and($alert->border)->toBe('round')->and($alert->borderStyle->fg)->toBe(Color::RED)
        ->and($sheet->compute(new Box(new Text('x'), class: 'plain'))->border)->toBeFalse()
        ->and(fn() => (new Stylesheet(['Text' => ['color' => '$nope']]))->compute(new Text('x')))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new Stylesheet(['Text' => ['colour' => 'red']]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new Stylesheet(['Text >> b' => []]))->toThrow(InvalidArgumentException::class);
});

it('matches states and parts, and parts inherit from their element', function () {
    $sheet = new Stylesheet([
        'Text' => ['color' => 'green'],
        'Text:focused' => ['bold' => true],
        'Text:disabled' => ['dim' => true],
        'Text::mark' => ['underline' => true],
        'Text::mark:on' => ['inverse' => true],
        'Text:focused::mark:on' => ['italic' => true],
    ]);
    $t = new Text('x');
    expect($sheet->compute($t, ['focused'])->text->attrs)->toBe(Style::BOLD)
        ->and($sheet->compute(new Text('x', disabled: true))->text->attrs)->toBe(Style::DIM)
        ->and($sheet->compute($t, [], null, 'mark')->text)->toBe(Style::of(Color::GREEN, attrs: Style::UNDERLINE))
        ->and($sheet->compute($t, [], null, 'mark', ['on'])->text->attrs)->toBe(Style::UNDERLINE | Style::INVERSE)
        ->and($sheet->compute($t, ['focused'], null, 'mark', ['on'])->text->attrs)->toBe(Style::BOLD | Style::UNDERLINE | Style::INVERSE | Style::ITALIC);
});

it('inherits colours and attributes down the tree, spans included', function () {
    $app = new Styled(fn() => new Box(new Text(['a', new Span('b', class: 'hot')]), style: ['color' => 'green', 'border' => 'single']));
    $app->setStylesheet(new Stylesheet(['Span.hot' => ['bold' => true]]));
    mountOn($app, 6, 3, function (FakeTerminal $t) {
        expect($t->dump())->toContain('│a[/][bold fg=2]b[/]')
            ->and($t->dump())->toContain('[fg=2]┌────┐[/]');
    });
});

it('draws the border of a box around the focused field in the focused style', function () {
    $app = new Styled(fn(Styled $app) => new Column([
        new Box($app->get('a', fn() => new TextInput()), 'a'),
        new Box($app->get('b', fn() => new TextInput()), 'b'),
    ]));
    $app->setStylesheet(Stylesheet::default()->with(['Box' => ['border' => 'single white'], 'Box:focused' => ['border' => 'double red']]));
    mountOn($app, 8, 6, function (FakeTerminal $t, Styled $app) {
        $app->get('a')->focus();
        frame();
        expect($t->text())->toStartWith("╔ a ═══╗\n║")->and($t->text())->toContain('┌ b ───┐')
            ->and($t->dump())->toContain('[fg=1]╔ a ═══╗[/]');
        $app->get('b')->focus();
        frame();
        expect($t->text())->toStartWith("┌ a ───┐")->and($t->text())->toContain('╔ b ═══╗');
    });
});

it('follows the mouse for :hover', function () {
    $app = new Styled(fn(Styled $app) => new Column([$app->get('card', fn() => new HoverCard()), new Text('below')]));
    $app->setStylesheet(new Stylesheet(['HoverCard:hover' => ['inverse' => true]]));
    mountOn($app, 10, 4, function (FakeTerminal $t) {
        frame();
        expect($t->dump())->not->toContain('[inverse]card');
        $t->mouse(new Mouse(Mouse::MOVE, 1, 0));
        frame();
        expect($t->dump())->toContain('[inverse]card');
        $t->mouse(new Mouse(Mouse::MOVE, 1, 3));
        frame();
        expect($t->dump())->not->toContain('[inverse]card')
            ->and($t->output())->toContain("\e[?1003h");      // the device asked for motion reports
    });
});

it('styles only through write(): there is no cell API, and a replay of the bytes is the same screen', function () {
    expect(method_exists(Surface::class, 'put'))->toBeFalse()->and(method_exists(Surface::class, 'fill'))->toBeFalse();
    $app = new Styled(fn(Styled $app) => new Column([
        new Text('title', class: 'title', style: ['background' => '$accent']),
        new Box($app->get('in', fn() => new TextInput('typed')), 'in'),
    ]));
    mountOn($app, 12, 4, function (FakeTerminal $t, Styled $app) {
        $t->refresh();
        $replay = new Surface(12, 4);
        $replay->write($t->output());
        expect($replay->dump())->toBe($app->surface->dump())
            ->and($app->surface->dump())->toContain('[bold bg=6]title[/][bg=6]       [/]');     // (erased cells take the background only)
    });
});
