<?php

/*
 * TODO-0.0.3-beta5 item 2: the key action 'quit' on an element (`onKey: ['q' => ['quit',
 * 'quit']]`, `['q' => 'quit']`) ends the program as it does from `Tui::run(keys:)`: headless
 * and on a real terminal (a pty). Before, the element path treated the action as a closure, the
 * TypeError ended the terminal's input coroutine, and the program hung with its screen up.
 * Every action name behaves the same from both places, and a failure that nothing contains
 * ends the program instead of leaving the screen standing.
 */

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\Pty;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

dataset('quit bindings', [
    'array form' => [['q' => ['quit', 'quit']]],
    'string form' => [['q' => 'quit']],
]);

it('quits from an element binding, headless', function (array $bind) {
    fiber\run(function () use ($bind) {
        $t = Tui::headless(fn() => new Column([new Text('card one', onKey: $bind), new Text('card two', onKey: $bind)]), 30, 4);
        $t->settle();
        $app = $t->component();
        $t->press('tab', 'q');
        expect($app->isMounted())->toBeFalse()->and($t->isAttached())->toBeFalse();
    });
})->with('quit bindings');

it('quits from a root binding, headless', function (array $bind) {
    fiber\run(function () use ($bind) {
        $t = Tui::headless(fn() => new Column([new Text('card', onKey: ['x' => fn() => null])]), 30, 4, keys: $bind);
        $t->settle();
        $app = $t->component();
        $t->press('q');
        expect($app->isMounted())->toBeFalse();
    });
})->with('quit bindings');

it('calls a method of the component that rendered the element for a method name, as bindings() does', function () {
    $app = new class () extends Component {
        public int $opened = 0;

        public function open(): void
        {
            $this->opened++;
        }

        protected function render(): Element
        {
            return new Column([new Text('card', onKey: ['enter' => 'open', 'x' => ['missing', 'gone']])]);
        }
    };
    fiber\run(function () use ($app) {
        $t = Tui::headless($app, 30, 3);
        $t->settle();
        $t->press('enter');
        expect($app->opened)->toBe(1);
        // a name that is no public method: the program fails, and ends (no screen left standing)
        expect(fn() => $t->press('x'))->toThrow(LogicException::class, "bound to 'missing', which is not a public method");
        expect($t->isAttached())->toBeFalse();
    });
});

/** Run tests/fixtures/programs/quit.php on a pty, press keys once it shows; [exit status or null, what the pty shows]. */
function quitOnPty(string $where, string $form, string $keys): array
{
    $result = null;
    fiber\run(function () use ($where, $form, $keys, &$result) {
        $s = new Surface(40, 8);
        $pty = Pty::start([PHP_BINARY, dirname(__DIR__) . '/fixtures/programs/quit.php', $where, $form], $s);
        $until = microtime(true) + 5;
        while (!str_contains($s->text(), 'card two') && microtime(true) < $until) {
            fiber\sleep(0.02);
        }
        fiber\sleep(0.2);       // (past the probe of the terminal)
        $pty->write($keys);
        $code = $pty->wait(5);
        if ($code === null) {
            $pty->kill(9);
        }
        $result = [$code, $s->text()];
    });
    return $result;
}

it('quits from an element binding and from the root on a real terminal', function (string $where, string $form) {
    [$code, $screen] = quitOnPty($where, $form, "\tq");
    expect($code)->toBe(0)->and($screen)->toContain('ended');
})->with(['element', 'root'])->with(['array', 'string']);

it('ends the program when a failure nothing contains escapes the dispatch of a key', function () {
    fiber\run(function () {
        $t = new \phasync\Tui\FakeTerminal(20, 3);
        $root = new Surface();
        $t->attach($root);
        $root->on('key', function () {
            throw new RuntimeException('a listener failed');
        });
        expect(fn() => $t->press('a'))->toThrow(RuntimeException::class, 'a listener failed');
        expect($t->isAttached())->toBeFalse();
    });
});
