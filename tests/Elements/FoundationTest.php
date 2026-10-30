<?php

/*
 * The small pieces under the components layer: Ansi::clearHome(), the fiber-interop/api
 * version check, border kinds, surfaces that do not take the focus, callbacks rendering
 * their owner.
 */

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Component\TextInput;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Internal\Runtime;
use phasync\Tui\Split;
use phasync\Tui\Surface;

it('clears the screen and homes the cursor with clearHome()', function () {
    $s = vt("abc\r\ndef");
    $s->write(Ansi::clearHome() . 'x');
    expect(rows($s))->toBe(str_pad('x', 20) . '|' . str_repeat(str_repeat(' ', 20) . '|', 3) . str_repeat(' ', 20))
        ->and(cursorAt($s))->toBe([1, 0]);
});

it('refuses to start on a fiber-interop/api older than it needs, with what to do', function () {
    expect(fn() => Runtime::check('v0.3.0'))->toThrow(RuntimeException::class, 'needs fiber-interop/api 0.3.1 or later, but v0.3.0 is installed')
        ->and(fn() => Runtime::check('0.2.9'))->toThrow(RuntimeException::class, 'composer require fiber-interop/api:^0.3.1');
    Runtime::check('0.3.1');
    Runtime::check('dev-main');         // (a branch: the functions decide)
    Runtime::check();
    expect(true)->toBeTrue();
});

it('draws borders in kinds of line', function () {
    $root = new Surface(12, 3);
    $a = new Surface(4, 1);
    $a->write('ab');
    $root->host($a, 0, 0, 6, 3)->setBorder('round');
    $b = new Surface(4, 1);
    $root->host($b, 6, 0, 6, 3)->setBorder('double');
    expect($root->text())->toBe("╭────╮╔════╗\n│ab  │║    ║\n╰────╯╚════╝")
        ->and(fn() => $root->viewport($a)->setBorder('wavy'))->toThrow(InvalidArgumentException::class);
    $root->viewport($a)->setBorder(true);     // true keeps the kind
    expect($root->viewport($a)->borderKind())->toBe('round');
});

it('passes the focus by a surface that does not take it, and Tab skips it', function () {
    $root = new Surface(30, 3);
    $label = new Surface();
    $label->setFocusable(false);
    $a = new Surface();
    $b = new Surface();
    $root->setLayout(Split::horizontal()->add($label)->add($a)->add($b));
    expect($root->focused())->toBe($a);
    $root->focusNext();
    expect($root->focused())->toBe($b);
    $root->focusNext();
    expect($root->focused())->toBe($a);
    $label->focus();                      // asked for: it gets it
    expect($root->focused())->toBe($label);
});

it('renders the component that made a callback after the callback ran', function () {
    $app = new class extends Component {
        public string $last = '';

        protected function render(): Element
        {
            return new Column([
                $this->child('in', fn() => new TextInput(onSubmit: function (string $v) {
                    $this->last = $v;         // a plain property: the render follows by itself
                })),
                new Text("got {$this->last}"),
            ]);
        }
    };
    mountOn($app, 20, 2, function (FakeTerminal $t) {
        $t->type("hello\n");
        frame();
        expect($t->text())->toBe("hello\ngot hello");
    });
});
