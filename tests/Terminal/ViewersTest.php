<?php

use phasync\Tui\Component;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Element;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Key;
use phasync\Tui\Surface;
use phasync\Tui\Tests\Support\PushDevice;

use function fiber\go;
use function fiber\run;
use function fiber\sleep;

// What a device needs when its terminal changes under it: a viewer that joins a multiplexer's
// daemon late, a device that has no terminal to ask at attach, a shell that must run before
// it is shown.

it('redraw() sends the whole screen, the title, the cursor state and the mouse modes again', function () {
    run(function () {
        $root = new Surface();
        $terminal = new FakeTerminal(20, 4);
        $terminal->attach($root);
        $pane = new Surface();
        $root->setLayout(phasync\Tui\Split::horizontal()->add($pane));
        $pane->write("hello\e]2;mux\x07\e[?1003h\e[?1006h\e[5 q");
        expect($root->title())->toBe('mux');
        $terminal->refresh();
        $terminal->takeOutput();
        $terminal->refresh();
        expect($terminal->takeOutput())->toBe('');                   // nothing changed: nothing sent

        $terminal->redraw();
        $terminal->refresh();
        $out = $terminal->takeOutput();
        expect($out)->toContain("\e[2J")->toContain('hello')->toContain("\e]2;mux")
            ->toContain("\e[?1003h")->toContain("\e[5 q")->toContain("\e[?25l")->toContain("\e[?25h");
        expect($terminal->surface->dump(true))->toBe($root->dump(true));

        $pane->write("\e[?25l");
        $terminal->refresh();
        $terminal->redraw();
        $terminal->refresh();
        expect($terminal->takeOutput())->toContain("\e[?25l")->not->toContain("\e[?25h");  // hidden: said so
        expect($terminal->surface->dump(true))->toBe($root->dump(true))
            ->and($terminal->surface->cursor()->visible)->toBeFalse();
        $terminal->detach();
        $terminal->redraw();                                              // not attached: nothing
    });
});

it('a device with probe false asks the terminal nothing, is probed at once, and takes input handed to receive()', function () {
    run(function () {
        $device = new PushDevice();
        $root = new Surface();
        $probed = new ArrayObject();
        $device->whenProbed(fn() => $probed[] = 'early');
        $device->attach($root);
        $device->whenProbed(fn() => $probed[] = 'at attach');
        expect($device->output)->not->toContain("\e[c")->not->toContain("\e[?u")->not->toContain("\e]11;?")
            ->and((array) $probed)->toBe(['early', 'at attach'])
            ->and($device->images())->toBe('none')
            ->and($device->hasKittyKeyboard())->toBeFalse()
            ->and($root->cols())->toBe(40)->and($root->rows())->toBe(8);

        $keys = new ArrayObject();
        $root->on('key', fn(Key $k) => $keys[] = (string) $k);
        go(fn() => $device->push("\e[A"));                           // from another coroutine
        $started = microtime(true);
        while (\count($keys) === 0 && microtime(true) - $started < 1) {
            sleep(0.005);
        }
        expect((array) $keys)->toBe(['up'])->and(microtime(true) - $started)->toBeLessThan(0.1);

        $device->push("\e");                                            // a lone ESC: Escape after the timeout
        $started = microtime(true);
        while (\count($keys) === 1 && microtime(true) - $started < 1) {
            sleep(0.005);
        }
        expect((array) $keys)->toBe(['up', 'escape']);

        $device->resizeTo(30, 5);
        expect($root->cols())->toBe(30)->and($root->rows())->toBe(5);
        $device->detach();
    });
});

final class BackgroundShells extends Component
{
    public ?Terminal $hidden = null;
    public bool $show = false;

    protected function mount(): void
    {
        $this->hidden = $this->keep('bg', fn() => new Terminal(['sh', '-c', 'stty size; echo started; sleep 5'], size: [50, 10]));
    }

    protected function render(): Element
    {
        return $this->show ? $this->hidden : new Text('nothing shown');
    }
}

it('a Terminal given a size starts at mount at that size, before any render shows it, and follows its pane when shown', function () {
    $app = new BackgroundShells();
    mountOn($app, 30, 6, function (FakeTerminal $t, BackgroundShells $app) {
        $t->settle();
        $shell = $app->hidden;
        expect($t->text())->toContain('nothing shown')
            ->and($shell->pty())->not->toBeNull()
            ->and([$shell->surface->cols(), $shell->surface->rows()])->toBe([50, 10]);
        $deadline = microtime(true) + 5;
        while (!str_contains($shell->surface->text(), 'started') && microtime(true) < $deadline) {
            sleep(0.02);
        }
        expect($shell->surface->text())->toContain('10 50')->toContain('started');  // ran hidden, at 50x10
        $pid = $shell->pty()->pid();
        $app->show = true;
        $app->requestRender();
        $t->settle();
        expect($t->text())->toContain('started')
            ->and($shell->pty()->pid())->toBe($pid)                     // the same program, not started again
            ->and([$shell->surface->cols(), $shell->surface->rows()])->toBe([30, 6]);
    });
});
