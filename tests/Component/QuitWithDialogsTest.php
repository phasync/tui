<?php

/*
 * Quitting with dialogs open ends the program: every dialog is answered with its cancel value,
 * disposed, and nothing keeps fiber\run() running. A modal dialog suspends the keys of the
 * component that opened it, except its bindings marked `always`; its parents' bindings work.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\Editor;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;

use function fiber\await;

/** A dialog with two Editors inside. */
final class NameForm extends Dialog
{
    public function __construct()
    {
        parent::__construct('Your name', new Column([new Editor(maxLines: 1, placeholder: 'first'), new Editor(maxLines: 1, placeholder: 'last')]), ['OK' => 'ok', 'Cancel' => 'cancel'], 'cancelled');
    }
}

final class QuittingApp extends Component
{
    public array $answers = [];

    public function __construct(private bool $always = true)
    {
    }

    protected function bindings(): array
    {
        return ['ctrl+q' => $this->always ? ['quit', 'quit', 'always' => true] : 'quit', 'f2' => 'confirm', 'f3' => 'form', 'x' => 'own'];
    }

    public int $own = 0;

    public function own(): void
    {
        $this->own++;
    }

    public function quit(): void
    {
        $this->dispose();
    }

    public function confirm(): void
    {
        $this->go(fn() => $this->answers[] = await($this->dialog(new Confirm('Sure?'))));
    }

    public function form(): void
    {
        $this->go(fn() => $this->answers[] = await($this->dialog(new NameForm())));
    }

    public function child2(): QuittingChild
    {
        return $this->child('c', fn() => new QuittingChild());
    }

    protected function render(): Element
    {
        return new Column([new Text('app'), $this->child2()]);
    }
}

final class QuittingChild extends Component
{
    public array $answers = [];

    protected function bindings(): array
    {
        return ['f5' => 'ask'];
    }

    public function ask(): void
    {
        $this->go(fn() => $this->answers[] = await($this->dialog(new NameForm())));
    }

    protected function render(): Element
    {
        return new Text('child');
    }
}

/** Run $test on a QuittingApp in fiber\run(); fails (instead of hanging) if run() does not return within 3 s. */
function quitting(QuittingApp $app, Closure $test): void
{
    $pid = pcntl_fork();
    if ($pid === 0) {
        // (in a child process, so a hang cannot hang the suite)
        try {
            \fiber\run(function () use ($app, $test) {
                $t = new FakeTerminal(50, 14);
                $app->start($t);
                \fiber\go(function () use ($t, $app, $test) {
                    try {
                        $test($t, $app);
                    } catch (Throwable $e) {
                        $t->detach();       // (a failing test fails, it does not hang)
                        throw $e;
                    }
                });
            });
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, (string) $e);
            exit(2);
        }
    }
    $deadline = microtime(true) + 3;
    while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
        if (microtime(true) > $deadline) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            throw new RuntimeException('fiber\run() did not return');
        }
        usleep(5000);
    }
    expect(pcntl_wexitstatus($status))->toBe(0);
}

it('ends the program when Ctrl-Q quits with the root\'s own Confirm open', function () {
    quitting(new QuittingApp(), function (FakeTerminal $t, QuittingApp $app) {
        $t->press('f2');
        frame();
        expect($t->text())->toContain('Sure?');
        $t->press('x');                         // the root's own keys wait while its dialog is open
        expect($app->own)->toBe(0);
        $t->press('ctrl+q');                    // ... except those marked always
        expect($app->isMounted())->toBeFalse();
    });
});

it('ends the program when Ctrl-Q quits with a Dialog of Editors open, the focus in an Editor', function () {
    quitting(new QuittingApp(), function (FakeTerminal $t, QuittingApp $app) {
        $t->press('f3');
        frame();
        $t->type('Ada');
        $t->settle();
        expect($t->text())->toContain('Ada');
        $t->press('ctrl+q');
        expect($app->isMounted())->toBeFalse();
    });
});

it('ends the program when a parent\'s binding quits while a child\'s dialog is open', function () {
    quitting(new QuittingApp(always: false), function (FakeTerminal $t, QuittingApp $app) {
        $app->child2()->focus();
        $t->press('f5');
        frame();
        $t->type('Bo');
        $t->press('ctrl+q');                    // the root's binding works: the child is the suspended one
        expect($app->isMounted())->toBeFalse();
    });
});

it('answers every open dialog with its cancel value when its opener is disposed', function () {
    \fiber\run(function () {
        $app = new QuittingApp();
        $t = new FakeTerminal(50, 14);
        $app->start($t);
        $child = $app->child2();
        $child->focus();
        $t->press('f5');
        $app->confirm();
        frame();
        $app->quit();
        frame();
        expect($child->answers)->toBe(['cancelled'])->and($app->answers)->toBe([false]);
    });
});

it('ends the program whatever the moment of the quit and the number of dialogs open', function () {
    mt_srand(2026);
    for ($round = 0; $round < 25; $round++) {
        $opens = mt_rand(0, 4);
        $plan = [];
        for ($i = 0; $i < $opens; $i++) {
            $plan[] = ['f2', 'f3', 'f5'][mt_rand(0, 2)];
        }
        $typing = mt_rand(0, 3);
        $direct = mt_rand(0, 1) === 1;
        $wait = mt_rand(0, 2);
        quitting(new QuittingApp(), function (FakeTerminal $t, QuittingApp $app) use ($plan, $typing, $direct, $wait) {
            foreach ($plan as $key) {
                if ($key === 'f5') {
                    $app->child2()->ask();
                } else {
                    $app->{$key === 'f2' ? 'confirm' : 'form'}();
                }
                if (mt_rand(0, 1)) {
                    frame();
                }
            }
            $t->type(str_repeat('a', $typing));
            for ($i = 0; $i < $wait; $i++) {
                frame();
            }
            $direct ? $app->quit() : $t->press('ctrl+q');
        });
    }
});
