<?php

/*
 * Menu and MenuBar, through FakeTerminal.
 */

use phasync\Tui\Component\Menu;
use phasync\Tui\Component\MenuBar;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text as TextEl;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Rect;

function menuBarApp(ArrayObject $seen): WidgetScreen
{
    return new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('bar', fn() => new MenuBar([
            'File' => [
                'new' => ['label' => 'New', 'shortcut' => 'Ctrl+N', 'onSelect' => function () use ($seen) { $seen[] = 'item:new'; }],
                'recent' => ['label' => 'Recent', 'items' => ['a' => 'one.txt', 'b' => 'two.txt']],
                '-',
                'off' => ['label' => 'Disabled', 'disabled' => true],
                'quit' => 'Quit',
            ],
            'Help' => ['about' => 'About'],
        ], onSelect: function ($key) use ($seen) { $seen[] = $key; })),
        new TextEl('body text'),
    ]));
}

it('opens a MenuBar dropdown with Enter, draws items, shortcuts, separators and submenu marks', function () {
    $seen = new ArrayObject();
    $app = menuBarApp($seen);
    mountOn($app, 30, 10, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        expect(screenRows($t)[0])->toBe(' File  Help');
        $bar = $app->children()['bar'];
        $bar->focus();
        $t->press('enter');
        $t->settle();
        expect($bar->openTitle())->toBe('File');
        $rows = screenRows($t);
        expect(array_slice($rows, 1, 7))->toBe([
            '╭──────────────────╮',
            '│ New       Ctrl+N │',
            '│ Recent         ▸ │',
            '│──────────────────│',
            '│ Disabled         │',
            '│ Quit             │',
            '╰──────────────────╯',
        ]);
    });
});

it('moves through a menu skipping separators and disabled items, picks with Enter and closes it', function () {
    $seen = new ArrayObject();
    $app = menuBarApp($seen);
    mountOn($app, 30, 10, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        $bar = $app->children()['bar'];
        $bar->focus();
        $t->press('down');                  // opens
        $t->press('up');                    // wraps to Quit (the disabled one is skipped)
        $t->press('enter');
        $t->settle();
        expect($seen->getArrayCopy())->toBe(['quit']);
        expect($bar->openTitle())->toBeNull();
        expect(screenRows($t)[1])->toBe('body text');
        $t->press('down');
        $t->press('enter');                 // New: its own callback, then the bar's
        $t->settle();
        expect($seen->getArrayCopy())->toBe(['quit', 'item:new', 'new']);
    });
});

it('opens a submenu with Right, picks there, and goes back with Left or Escape', function () {
    $seen = new ArrayObject();
    $app = menuBarApp($seen);
    mountOn($app, 40, 10, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        $bar = $app->children()['bar'];
        $bar->focus();
        $t->press('down', 'down', 'right');
        $t->settle();
        $rows = screenRows($t);
        expect($rows[3])->toContain('│ Recent         ▸ │ one.txt');
        $t->press('left');
        $t->settle();
        expect(screenRows($t)[3])->not->toContain('one.txt');
        expect($bar->openTitle())->toBe('File');
        $t->press('right', 'down', 'enter');
        $t->settle();
        expect($seen->getArrayCopy())->toBe(['b']);
        expect($bar->openTitle())->toBeNull();
        $t->press('down', 'down', 'right');
        $t->press('escape');
        $t->settle();
        expect(screenRows($t)[3])->not->toContain('one.txt');
        expect($bar->openTitle())->toBe('File');         // Escape closed the submenu only
        $t->press('escape');
        $t->settle();
        expect($bar->openTitle())->toBeNull();
    });
});

it('switches menus with Left/Right in an open menu and with clicks, and picks by a click', function () {
    $seen = new ArrayObject();
    $app = menuBarApp($seen);
    mountOn($app, 30, 10, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        $bar = $app->children()['bar'];
        $t->click(2, 0);                    // File
        $t->settle();
        expect($bar->openTitle())->toBe('File');
        $t->press('right');                 // nothing in File to open on 'New': on to Help
        $t->settle();
        expect($bar->openTitle())->toBe('Help');
        expect(screenRows($t)[2])->toContain('About');
        $t->click(2, 0);                    // back to File
        $t->settle();
        expect($bar->openTitle())->toBe('File');
        $t->click(2, 0);                    // the open title: closes it
        $t->settle();
        expect($bar->openTitle())->toBeNull();
        $t->click(2, 0);
        $t->settle();
        $t->click(4, 6);                    // Quit (row 6)
        $t->settle();
        expect($seen->getArrayCopy())->toBe(['quit']);
        $t->click(2, 0);
        $t->settle();
        $t->click(20, 8);                   // outside: closes
        $t->settle();
        expect($bar->openTitle())->toBeNull();
    });
});

final class PopupOwner extends \phasync\Tui\Component
{
    public function popupAt(array $items, Rect $at, Closure $onSelect): \phasync\Tui\Overlay
    {
        return Menu::popup($this, $items, $at, $onSelect);
    }

    protected function render(): \phasync\Tui\Element
    {
        return new TextEl('hello');
    }
}

it('opens a context menu on a component with Menu::popup, reports the key and resolves the overlay', function () {
    $seen = new ArrayObject();
    $app = new PopupOwner();
    mountOn($app, 30, 8, function (FakeTerminal $t, PopupOwner $app) use ($seen) {
        $t->settle();
        $overlay = $app->popupAt(['copy' => ['label' => 'Copy', 'shortcut' => 'Ctrl+C'], 'paste' => 'Paste'], new Rect(5, 2, 1, 1),
            function ($key) use ($seen) { $seen[] = $key; });
        $t->settle();
        expect(array_slice(screenRows($t), 3, 4))->toBe(['     ╭───────────────╮', '     │ Copy   Ctrl+C │', '     │ Paste         │', '     ╰───────────────╯']);
        $t->mouse(new \phasync\Tui\Mouse(\phasync\Tui\Mouse::MOVE, 8, 5));
        $t->settle();
        expect($overlay->content->current())->toBe('paste');    // hovering makes an item current
        $t->press('enter');
        $t->settle();
        expect($seen->getArrayCopy())->toBe(['paste']);
        expect($overlay->isOpen())->toBeFalse();
        expect($overlay->result())->toBe('paste');
    });
});
