<?php

/*
 * Dialog: modal over the component that opens it, an awaitable answer, Escape cancels, the
 * focus goes back; over element trees, layouts and components that draw themselves.
 */

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Log;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Layout;
use phasync\Tui\Split;

use function fiber\await;

/** A screen with a field that asks before it deletes. */
final class Asker extends Component
{
    public array $answers = [];
    public int $own = 0;

    protected function bindings(): array
    {
        return ['f2' => 'delete', 'x' => 'own'];
    }

    public function delete(): void
    {
        $this->go(function () {
            $this->answers[] = await($this->dialog(new Confirm('Delete it?', title: 'Sure')));
        });
    }

    public function ask(Dialog $dialog): void
    {
        $this->go(function () use ($dialog) {
            $this->answers[] = await($this->dialog($dialog));
        });
    }

    public function own(): void
    {
        $this->own++;
    }

    protected function render(): Element
    {
        return new Column([new Text(str_repeat('background ', 20), height: 4), $this->child('field', fn() => new Editor(maxLines: 1))]);
    }

    public function field(): Editor
    {
        return $this->child('field');
    }
}

it('asks modally and gives the answer to the coroutine that awaits it', function () {
    mountOn(new Asker(), 30, 9, function (FakeTerminal $t, Asker $app) {
        $app->field()->focus();
        $t->type('ab');
        $t->press('f2');
        frame();
        expect($t->text())->toContain('│ Sure            │')->and($t->text())->toContain('│ [ Yes ]  [ No ] │');
        $t->type('x');                          // the dialog's now: not the field's, not the app's
        expect($app->field()->value())->toBe('ab')->and($app->own)->toBe(0);
        $t->press('right', 'enter');
        frame();
        expect($app->answers)->toBe([false])->and($t->text())->not->toContain('Sure');
        $t->type('c');                          // the focus is back on the field
        expect($app->field()->value())->toBe('abc');
    });
});

it('cancels with Escape, and Yes gives true', function () {
    mountOn(new Asker(), 30, 9, function (FakeTerminal $t, Asker $app) {
        $t->press('f2');
        frame();
        $t->press('escape');
        frame();
        $t->press('f2');
        frame();
        $t->press('enter');
        frame();
        expect($app->answers)->toBe([false, true]);
        $app->ask(new Dialog(content: 'Pick', buttons: ['A' => 'a', 'B' => 'b']));
        frame();
        $t->press('escape');
        frame();
        expect($app->answers)->toBe([false, true, null]);
    });
});

it('takes clicks on its buttons and ignores the mouse outside it', function () {
    mountOn(new Asker(), 30, 9, function (FakeTerminal $t, Asker $app) {
        $app->ask(new Dialog(content: 'Pick one', buttons: ['A' => 'a', 'B' => 'b']));
        frame();
        $lines = explode("\n", $t->text());
        $row = array_key_first(array_filter($lines, fn($l) => str_contains($l, '[ B ]')));
        $col = mb_strpos($lines[$row], '[ B ]');
        $t->click(0, 8);                        // outside: nothing
        frame();
        expect($app->answers)->toBe([]);
        $t->click($col + 1, $row);
        frame();
        expect($app->answers)->toBe(['b']);
    });
});

it('opens over a component that returns a layout and over one that draws itself', function () {
    $layouted = new class extends Component {
        public array $answers = [];

        protected function render(): ?Layout
        {
            return Split::vertical()->add($this->child('log', fn() => new Log())->surface);
        }

        public function ask(): void
        {
            $this->go(fn() => $this->answers[] = await($this->dialog(new Confirm('Go?'))));
        }
    };
    mountOn($layouted, 24, 7, function (FakeTerminal $t, Component $app) {
        $app->ask();
        frame();
        expect($t->text())->toContain('[ Yes ]  [ No ]');
        $t->press('enter');
        frame();
        expect($app->answers)->toBe([true])->and($t->text())->not->toContain('Yes');
    });
    $drawn = new class extends Component {
        public array $answers = [];

        protected function render(): null
        {
            $this->surface->write(Ansi::clearHome() . str_repeat('#', $this->surface->cols() * $this->surface->rows()));
            return null;
        }

        public function ask(): void
        {
            $this->go(fn() => $this->answers[] = await($this->dialog(new Confirm('Go?'))));
        }
    };
    mountOn($drawn, 24, 7, function (FakeTerminal $t, Component $app) {
        $app->ask();
        frame();
        expect($t->text())->toContain('[ Yes ]  [ No ]')->and($t->text())->toStartWith('####');
        $t->press('right', 'enter');
        frame();
        expect($app->answers)->toBe([false])->and($t->text())->not->toContain('Yes');
    });
});
