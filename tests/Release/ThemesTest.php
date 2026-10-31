<?php

/*
 * TODO-0.0.3-beta2 item 1 (SPEC 9.4): good by default. Three themes that differ only in colours
 * and attributes, chosen by Tui's theme: or the terminal's background (OSC 11); a program's
 * rules over the theme's; mono without a colour; dialogs with a shadow; the common things as
 * constructor arguments.
 */

use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Checkbox;
use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\Markdown;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Spinner;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Style;
use phasync\Tui\Stylesheet;
use phasync\Tui\Tui;

/** A screen with most widgets, and a dialog open over it. */
final class ThemedScreen extends Component
{
    public ?Confirm $confirm = null;

    protected function mount(): void
    {
        $this->confirm = new Confirm('Delete 3 files?');
        $this->go(fn() => \fiber\await($this->dialog($this->confirm)));
    }

    protected function render(): Element
    {
        return new Column([
            new Row([new Spinner('<b>build</b>'), new Text('deploy <dim>v1.4</dim>', class: 'text-accent')], gap: 2),
            new Row([
                new Box(new Select(items: ['apple', 'banana', 'cherry']), 'fruit', class: 'grow'),
                new Box(new Column([new Checkbox('fast', checked: true), new Button('Run'), new ProgressBar(0.6, label: 'job')]), 'jobs', class: 'grow'),
            ], height: 7),
            new Box(new Markdown("# Notes\n\n- one `code`\n- **two**\n\n> quoted"), 'notes', height: 8),
            new Help(),
        ]);
    }
}

/** @return array{string, list<list<string>>, list<list<Style>>, string} text, chars, styles, output */
function themedScreen(string $theme, int $cols = 70, int $rows = 22): array
{
    $result = null;
    \fiber\run(function () use ($theme, $cols, $rows, &$result) {
        $app = new ThemedScreen();
        $t = Tui::headless($app, $cols, $rows, theme: $theme);
        $t->settle();
        $chars = $styles = [];
        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                $cell = $t->root()->cell($x, $y);
                $chars[$y][$x] = $cell->char;
                $styles[$y][$x] = $cell->style;
            }
        }
        $result = [$t->text(), $chars, $styles, $t->output(), $app->confirm->surface->area()];
        $t->detach();
    });
    return $result;
}

it('draws the same tree in the three themes with the same characters, only colours and attributes differ', function () {
    [$dark, $darkChars, $darkStyles] = themedScreen('dark');
    [$light, $lightChars, $lightStyles] = themedScreen('light');
    [$mono, $monoChars, $monoStyles] = themedScreen('mono');
    expect($dark)->toContain('Delete 3 files?')->toContain('╭ fruit')->toContain('apple')->toContain('Notes')
        ->and($light)->toBe($dark)->and($mono)->toBe($dark)
        ->and($lightChars)->toBe($darkChars)->and($monoChars)->toBe($darkChars)
        ->and($lightStyles)->not->toBe($darkStyles)->and($monoStyles)->not->toBe($darkStyles);
    // rounded borders, the accent on the focused window's border, dimmed titles elsewhere
    expect($dark)->toContain('╭')->not->toContain('┌');
});

it('emits no colour sequence at all in the mono theme, even for colour classes and markup', function () {
    [, , $styles, $output] = themedScreen('mono');
    foreach ($styles as $row) {
        foreach ($row as $style) {
            expect($style->fg)->toBe(Color::DEFAULT)->and($style->bg)->toBe(Color::DEFAULT);
        }
    }
    preg_match_all('/\e\[([0-9;:]*)m/', $output, $m);
    foreach ($m[1] as $params) {
        foreach (explode(';', $params) as $p) {
            expect((int) $p)->not->toBeIn([...range(30, 39), ...range(40, 49), ...range(90, 97), ...range(100, 107)]);
        }
    }
    // and the focus, the current tab and the selection still show, by attributes
    [$text, , $mono] = themedScreen('mono');
    $attrs = 0;
    foreach ($mono as $row) {
        foreach ($row as $style) {
            $attrs |= $style->attrs;
        }
    }
    expect($attrs & (Style::BOLD | Style::DIM | Style::INVERSE))->toBe(Style::BOLD | Style::DIM | Style::INVERSE);
});

it('casts a dialog\'s shadow on what is under it, right and below, and takes it away with the dialog', function () {
    foreach (['dark' => Color::parse('black'), 'light' => Color::parse('slate-300'), 'mono' => Color::DEFAULT] as $theme => $bg) {
        [, $chars, $styles, , $area] = themedScreen($theme);
        $right = $area->x + $area->width + 1;            // (the border is one cell outside the area)
        $below = $area->y + $area->height + 1;
        $shadow = $styles[$area->y + 1][$right];
        expect($shadow->has(Style::DIM))->toBeTrue()->and($shadow->bg)->toBe($bg)
            ->and($styles[$below][$area->x + 2]->has(Style::DIM))->toBeTrue()
            ->and($styles[$area->y - 1][$right]->bg)->not->toBe($theme === 'mono' ? -2 : $bg);  // (the row above the shadow is not shaded)
    }
    \fiber\run(function () {
        $app = new ThemedScreen();
        $t = Tui::headless($app, 70, 22);
        $t->settle();
        $area = $app->confirm->surface->area();
        $x = $area->x + $area->width + 1;
        $y = $area->y + 1;
        // (what the program shows; the terminal shows a shaded blank's background, not its dimming)
        expect($t->root()->cell($x, $y)->style->has(Style::DIM))->toBeTrue()
            ->and($t->surface->cell($x, $y)->style->bg)->toBe($t->root()->cell($x, $y)->style->bg);
        $app->confirm->close(false);
        $t->settle();
        expect($t->text())->not->toContain('Delete 3 files?')
            ->and($t->root()->cell($x, $y)->style->has(Style::DIM))->toBeFalse()
            ->and($t->surface->cell($x, $y)->style->bg)->toBe(Color::DEFAULT);
        $t->detach();
    });
});

it('chooses the theme from the terminal\'s background: light, dark, or dark when it does not answer', function () {
    $muted = ['light' => Color::parse('slate-400'), 'dark' => Color::parse('slate-500')];
    foreach ([['light', 'light'], ['dark', 'dark'], [null, 'dark']] as [$answer, $theme]) {
        \fiber\run(function () use ($answer, $theme, $muted) {
            $t = Tui::headless(fn() => new Box(new Text('x'), 'box'), 20, 4, background: $answer);
            $t->settle();
            expect($t->background())->toBe($answer)->and($t->root() !== null)->toBeTrue()
                ->and($t->surface->cell(0, 0)->style->fg)->toBe($muted[$theme]);
            $t->detach();
        });
    }
    // theme: wins over the background
    \fiber\run(function () {
        $t = Tui::headless(fn() => new Box(new Text('x'), 'box'), 20, 4, theme: 'mono', background: 'light');
        $t->settle();
        expect($t->surface->cell(0, 0)->style->fg)->toBe(Color::DEFAULT)->and($t->surface->cell(0, 0)->style->has(Style::DIM))->toBeTrue();
        $t->detach();
    });
    // the device asked without holding anything up: the query is in the first bytes, the frame follows
    \fiber\run(function () {
        $t = new FakeTerminal(20, 4, background: null);
        $t->attach(new \phasync\Tui\Surface());
        expect($t->output())->toContain("\e]11;?\e\\")->and($t->background())->toBeNull();
        $t->detach();
    });
});

it('lets a program\'s rule override the theme\'s, on whichever theme it runs', function () {
    foreach (['dark', 'light', 'mono'] as $theme) {
        \fiber\run(function () use ($theme) {
            $app = new class () extends Component {
                protected function render(): Element
                {
                    return new Column([new Box(new Text('x'), 'box'), new Text('title', class: 'title')]);
                }
            };
            $app->setStylesheet(Stylesheet::default()->with(['Box' => ['border' => 'double'], 'Text.title' => ['underline' => true]]));
            $t = Tui::headless($app, 20, 5, theme: $theme);
            $t->settle();
            expect($t->text())->toStartWith('╔ box')
                ->and($t->surface->cell(0, 3)->style->has(Style::UNDERLINE | Style::BOLD))->toBeTrue();     // the theme's bold, the program's underline
            expect($app->stylesheet()->themeName())->toBe($theme)->and($app->theme())->toBe($theme);
            $t->detach();
        });
    }
    $mine = Stylesheet::default()->with(['Box' => ['border' => 'heavy']], ['accent' => 'red']);
    expect($mine->onTheme('light')->themeName())->toBe('light')->and($mine->onTheme('light'))->toBe($mine->onTheme('light'))
        ->and($mine->onTheme('light')->var('accent'))->toBe('red')->and($mine->onTheme('light')->var('panel'))->toBe('slate-200')
        ->and((new Stylesheet())->onTheme('light')->themeName())->toBeNull();
    expect(fn() => Stylesheet::theme('neon'))->toThrow(InvalidArgumentException::class, "Unknown theme 'neon'");
});

it('takes the common things as constructor arguments, so no stylesheet is needed for a first version', function () {
    \fiber\run(function () {
        $t = Tui::headless(fn() => new Column([
            new Box(new Text('in a box'), title: 'title', padding: '0 1', class: 'border-double'),
            new Text('centered', align: 'center'),
            new Column([new Text('narrow')], align: 'end', padding: 1),
            new Row([new Button(fn() => 'press me')], align: 'center', height: 3),
        ], gap: 1), 30, 14);
        $t->settle();
        $rows = screenRows($t);
        expect($rows[0])->toStartWith('╔ title')->and($rows[1])->toBe('║ in a box' . str_repeat(' ', 19) . '║')
            ->and($rows[4])->toBe('           centered')
            ->and($rows[7])->toBe('                       narrow')
            ->and($rows[11])->toBe('[ press me ]');
        $t->detach();
    });
});
