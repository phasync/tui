<?php

/*
 * Markup in text (SPEC 9.3): symfony/console's tags, nesting, escaping, malformed markup, the
 * width of marked-up text, and the widgets whose labels take it.
 */

use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\Markdown;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Table;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Span;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Markup;
use phasync\Tui\Style;
use phasync\Tui\Surface;
use phasync\Tui\Text as TextUtil;
use phasync\Tui\Tui;

/** The style of every visible cell of $markup written into a surface, as "char:style" runs. */
function marked(string $markup, ?Style $base = null): array
{
    $s = new Surface(40, 1, 0);
    $s->write(\phasync\Tui\Ansi::style($base ?? Style::plain()) . Markup::toAnsi($markup, $base));
    $out = [];
    foreach ($s->rowData(0)[0] as $x => $ch) {
        if ($ch !== ' ' || $s->cell($x, 0)->style !== Style::plain()) {
            $out[] = $ch . ':' . $s->cell($x, 0)->style;
        }
    }
    return $out;
}

final class MarkupScreen extends Component
{
    public function __construct(public \Closure $tree)
    {
    }

    protected function bindings(): array
    {
        return ['ctrl+s' => ['save', 'save <b>now</b>']];
    }

    public function save(): void
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

it('styles text with each tag', function () {
    expect(marked('<b>b</b>'))->toBe(['b:bold'])
        ->and(marked('<bold>b</bold>'))->toBe(['b:bold'])
        ->and(marked('<i>i</i>'))->toBe(['i:italic'])
        ->and(marked('<u>u</u>'))->toBe(['u:underline'])
        ->and(marked('<s>s</s>'))->toBe(['s:strike'])
        ->and(marked('<dim>d</dim>'))->toBe(['d:dim'])
        ->and(marked('<fg=red>r</>'))->toBe(['r:fg=1'])
        ->and(marked('<bg=blue>b</>'))->toBe(['b:bg=4'])
        ->and(marked('<fg=#ff5500>h</>'))->toBe(['h:fg=#ff5500'])
        ->and(marked('<fg=sky-400>t</>'))->toBe(['t:fg=#38bdf8'])
        ->and(marked('<fg=black;bg=yellow;options=bold,underscore>o</>'))->toBe(['o:bold underline fg=0 bg=3'])
        ->and(marked('<span class="text-sky-400 bold">s</span>'))->toBe(['s:bold fg=#38bdf8'])
        ->and(marked('<info>i</info><comment>c</comment><error>e</error>'))->toBe(['i:fg=2', 'c:fg=3', 'e:fg=7 bg=1'])
        ->and(marked('<href=https://example.com>l</>'))->toBe(['l:link=https://example.com']);
});

it('nests: inner tags inherit, attributes add up, the innermost colour wins; </name> closes what it opened', function () {
    expect(marked('<fg=red>a<b>b<fg=green>c</>d</b>e</>f'))->toBe(['a:fg=1', 'b:bold fg=1', 'c:bold fg=2', 'd:bold fg=1', 'e:fg=1', 'f:'])
        ->and(marked('<b>a<i>b</b>c'))->toBe(['a:bold', 'b:bold italic', 'c:'])        // </b> closes the <i> inside it
        ->and(marked('<span class="bg-blue">a<span class="text-red">b</span></span>'))->toBe(['a:bg=4', 'b:fg=1 bg=4'])
        ->and(marked('x<b>y</b>', Style::of(Color::CYAN)))->toBe(['x:fg=6', 'y:bold fg=6']);  // over the style around it
});

it('escapes with \\<, and shows malformed markup as written without throwing', function () {
    expect(Markup::plain('a \<b> c'))->toBe('a <b> c')
        ->and(Markup::plain(Markup::escape('<b>x</b>')))->toBe('<b>x</b>')
        ->and(Markup::toAnsi('<b>never closed'))->toBe('<b>never closed')
        ->and(Markup::toAnsi('</b> nothing open'))->toBe('</b> nothing open')
        ->and(Markup::toAnsi('<foo>x</foo>'))->toBe('<foo>x</foo>')
        ->and(Markup::toAnsi('<fg=nocolour>x</>'))->toBe('<fg=nocolour>x</>')
        ->and(Markup::toAnsi('1 < 2 and 3 > 2'))->toBe('1 < 2 and 3 > 2')
        ->and(Markup::toAnsi('<b>ok</b> <b>open'))->toBe("\e[0;1mok\e[0m <b>open");
});

it('measures marked-up text by its visible text and parses each string once', function () {
    $s = '<b>bold</b> and <fg=red>red</>';
    $ansi = Markup::toAnsi($s);
    expect(TextUtil::width($ansi))->toBe(12)
        ->and(Markup::toAnsi($s))->toBe($ansi)
        ->and(Markup::toAnsi('no tags at all'))->toBe('no tags at all');
});

it('renders markup in Text and Span, and Text::raw() shows it as written', function () {
    $app = new MarkupScreen(fn() => new Column([
        new Text('<b>3</b> files'),
        new Text(['x ', new Span('<i>y</i>', class: 'text-red')]),
        Text::raw('<b>raw</b>'),
    ]));
    mountOn($app, 20, 4, function (FakeTerminal $t) {
        expect(screenRows($t))->toBe(['3 files', 'x y', '<b>raw</b>', ''])
            ->and((string) $t->surface->cell(0, 0)->style)->toBe('bold')
            ->and((string) $t->surface->cell(2, 1)->style)->toBe('italic fg=1')
            ->and((new Text('<b>a</b>'))->plain())->toBe('a')
            ->and(Text::raw('<b>a</b>')->plain())->toBe('<b>a</b>');
    });
});

it('cuts a marked-up label in a narrow box by its visible text', function () {
    $app = new MarkupScreen(fn() => new Row([new Box(new Text('<b>bold</b> <fg=red>and red</> words', wrap: false), '<b>title</b> here', width: 12)]));
    mountOn($app, 20, 3, function (FakeTerminal $t) {
        $rows = screenRows($t);
        expect($rows[0])->toBe('╭ title her╮')
            ->and($rows[1])->toBe('│bold and …│')
            ->and((string) $t->surface->cell(2, 0)->style)->toContain('bold')
            ->and((string) $t->surface->cell(6, 1)->style)->toContain('fg=1')
            ->and($t->text())->not->toContain('<');
    });
});

it('takes markup in Button labels, Table and Select cells, Help and Log lines', function () {
    $app = new MarkupScreen(fn(MarkupScreen $app) => new Column([
        new Button('<u>S</u>ave'),
        $app->get('table', fn() => new Table(columns: ['Name' => 'auto', 'State' => 'auto'], rows: [1 => ['a', '<fg=red>failed</>'], 2 => ['bb', 'ok']])),
        $app->get('select', fn() => new Select(['x' => '<b>bold</b> item', 'y' => 'plain'])),
        $app->get('log', fn() => new Log())->with(height: 3),
        new Help(),
    ]));
    mountOn($app, 30, 10, function (FakeTerminal $t, MarkupScreen $app) {
        $app->get('log')->append("<fg=green>ok</> done\n");
        $app->get('log')->append("<b>split ");
        $app->get('log')->append("tag</b> line\n");
        $t->settle();
        $rows = screenRows($t);
        expect($rows[0])->toBe('[ Save ]')
            ->and((string) $t->surface->cell(2, 0)->style)->toContain('underline')
            ->and($rows[1])->toBe('Name State')
            ->and($rows[2])->toBe('a    failed')
            ->and((string) $t->surface->cell(5, 2)->style)->toContain('fg=1')
            ->and($rows[4])->toStartWith('bold item')
            ->and((string) $t->surface->cell(0, 4)->style)->toContain('bold')
            ->and($rows[6])->toBe('ok done')
            ->and((string) $t->surface->cell(0, 6)->style)->toContain('fg=2')
            ->and($rows[7])->toBe('split tag line')
            ->and((string) $t->surface->cell(0, 7)->style)->toContain('bold')
            ->and($app->get('log')->lines()[0])->toBe('<fg=green>ok</> done')      // kept as given
            ->and($rows[9])->toContain('ctrl+s save now')
            ->and($t->text())->not->toContain('<');
    });
});

it('styles inline tags in Markdown text and keeps them literal in a code span', function () {
    \fiber\run(function () {
        $t = Tui::headless(new Markdown("Some <b>bold</b> text and `<b>code</b>` and \\<b> and &lt;i>"), 60, 3);
        $t->settle();
        $row = screenRows($t)[0];
        expect($row)->toBe('Some bold text and <b>code</b> and <b> and <i>');
        expect((string) $t->surface->cell(5, 0)->style)->toContain('bold')
            ->and((string) $t->surface->cell(19, 0)->style)->not->toContain('bold');
        $t->detach();
    });
});
