<?php

/*
 * The small items of 0.0.2-beta4, each in the shape it was reported in: submit() inside a
 * Dialog's accept(), the values of a form after its dialog closed, a completion popup that
 * stays on its side as it shrinks, ProgressBar labels, and freezing a finished row inline.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Form;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;

use function fiber\await;

/** A dialog whose accept() submits its form again, as the report did. */
final class ResubmittingDialog extends Dialog
{
    public int $accepted = 0;

    public function __construct(public Form $form)
    {
        parent::__construct('Name', $form, ['OK' => 'ok', 'Cancel' => null]);
    }

    protected function accept(mixed $answer): bool
    {
        $this->accepted++;
        return $this->form->submit();       // (asked after the form was valid: does nothing)
    }
}

/** Opens a dialog and keeps the answer and what the form held afterwards. */
final class AskingScreen extends Component
{
    public array $answers = [];
    public array $values = [];

    public function ask(Dialog $dialog, ?Form $form = null): void
    {
        $this->go(function () use ($dialog, $form) {
            $this->answers[] = await($this->dialog($dialog));
            if ($form !== null) {
                $this->values[] = $form->values();
            }
        });
    }

    protected function render(): Element
    {
        return new Text('screen');
    }
}

function nameForm(array &$submitted): Form
{
    return new Form(
        new Column([new Text('Name'), (new Editor(maxLines: 1))->with(id: 'name')]),
        validate: fn(array $v) => $v['name'] === '' ? ['name' => 'A name, please.'] : [],
        onSubmit: function (array $v) use (&$submitted) {
            $submitted[] = $v;
        },
    );
}

it('closes a dialog whose accept() calls submit() on its form: Enter in the field and the OK button', function (string $how) {
    mountOn(new AskingScreen(), 40, 12, function (FakeTerminal $t, AskingScreen $app) use ($how) {
        $submitted = [];
        $dialog = new ResubmittingDialog($form = nameForm($submitted));
        $app->ask($dialog, $form);
        $t->settle();
        $t->type('Ada');
        $how === 'enter' ? $t->press('enter') : $t->press('tab', 'enter');
        $t->settle();
        expect($app->answers)->toBe(['ok'])->and($dialog->accepted)->toBe(1)
            ->and($submitted)->toBe([['name' => 'Ada']])        // once, not again from accept()
            ->and($app->values)->toBe([['name' => 'Ada']])      // read after the dialog closed
            ->and($t->text())->not->toContain('Name');
    });
})->with(['enter', 'button']);

it('keeps the dialog open while the form is not valid, then gives the values with the answer', function () {
    mountOn(new AskingScreen(), 40, 12, function (FakeTerminal $t, AskingScreen $app) {
        $submitted = [];
        $app->ask(new Dialog('Add', $form = nameForm($submitted), ['Add' => 'add', 'Cancel' => null]), $form);
        $t->settle();
        $t->press('enter');
        $t->settle();
        expect($app->answers)->toBe([])->and($t->text())->toContain('A name, please.');
        $t->type("Bo\n");
        $t->settle();
        expect($app->answers)->toBe(['add'])->and($app->values)->toBe([['name' => 'Bo']])
            ->and($form->isMounted())->toBeFalse();
    });
});

it('keeps a completion popup on the side it opened on while it shrinks', function () {
    $words = ['alpha', 'alpine', 'alps', 'also'];
    $editor = new Editor(maxLines: 1, complete: fn(string $w) => array_values(array_filter($words, fn($c) => str_starts_with($c, $w))));
    $app = new class ($editor) extends Component {
        public function __construct(private Editor $editor)
        {
        }

        protected function render(): Element
        {
            return new Column([new Text(implode("\n", range(1, 10))), new Box($this->child('e', fn() => $this->editor), 'word')]);
        }
    };
    mountOn($app, 30, 13, function (FakeTerminal $t) use ($editor) {
        $editor->focus();
        $t->type('al');
        $t->press('tab');
        $t->settle();
        $rows = explode("\n", $t->text());
        // the field is on row 11, one row under it: four candidates go above
        expect($rows[11])->toStartWith('│al')->and($rows[7])->toContain('alpha')->and($rows[10])->toContain('also');
        $t->type('ph');
        $t->settle();
        $rows = explode("\n", $t->text());
        expect($rows[10])->toContain('alpha')                  // still above, one row now
            ->and($rows[12])->toStartWith('╰')                   // the box's border under the field is not covered
            ->and($rows[7])->toBe('8')->and($rows[9])->toBe('10');
    });
});

it('shows a ProgressBar\'s label and its percentage, fills placeholders, and never draws past its box', function () {
    $bars = [
        new ProgressBar(0.3, label: '3/10 files'),
        new ProgressBar(0.42, label: 'upload {percent} ({value})'),
        new ProgressBar(0.5),
        new ProgressBar(null, label: 'wait {percent}'),
    ];
    $long = new ProgressBar(0.25, label: 'exporting a very long name of a file.csv');
    $app = new class ($bars, $long) extends Component {
        public function __construct(private array $bars, private ProgressBar $long)
        {
        }

        protected function render(): Element
        {
            return new Column([...$this->bars, new Row([new Box($this->long, 'export', width: 24), new Text('|')])]);
        }
    };
    mountOn($app, 40, 7, function (FakeTerminal $t) {
        $t->settle();
        $rows = explode("\n", $t->text());
        expect($rows[0])->toEndWith(' 3/10 files 30%')
            ->and($rows[1])->toEndWith(' upload 42% (0.42)')
            ->and($rows[2])->toEndWith(' 50%')
            ->and($rows[3])->toEndWith(' wait')->not->toContain('{percent}');
        // the bar in a 24-wide box: 22 cells inside, the label cut to fit, the border whole
        expect(mb_substr($rows[5], 0, 1))->toBe('│')->and(mb_substr($rows[5], 23))->toBe('│')
            ->and(mb_substr($rows[5], 1, 22))->toContain('…');
    });
});

/** Jobs on rows; a finished one can be frozen and dropped. */
final class JobsScreen extends Component
{
    /** @var array<string, float> */
    public array $jobs = ['fetch' => 0.0, 'build' => 0.0, 'test' => 0.0];

    public function bar(string $name): ?ProgressBar
    {
        $c = $this->child("job:$name");
        return $c instanceof ProgressBar ? $c : null;
    }

    protected function render(): Element
    {
        $rows = [];
        foreach ($this->jobs as $name => $done) {
            $rows[] = $this->child("job:$name", fn() => new ProgressBar($done, label: $name));
            $this->bar($name)->setValue($done);
        }
        $rows[] = new Text(\count($this->jobs) . ' running');
        return new Column($rows);
    }
}

it('freezes a finished row inline: it goes above the region as it showed, and the region shrinks', function () {
    \fiber\run(function () {
        $t = FakeTerminal::inline(40, 10);
        $t->surface->write("$ make\r\n");
        $app = (new JobsScreen())->start($t);
        $t->run(function (FakeTerminal $t) use ($app) {
            $app->jobs['build'] = 1.0;
            $app->requestRender();
            $t->settle();
            $shown = explode("\n", $t->region())[1];
            expect($shown)->toEndWith('build 100%');
            $t->freeze($app->bar('build')->surface);      // the row as it shows now
            unset($app->jobs['build']);
            $app->requestRender();
            $t->settle();
            expect($t->frozen())->toBe("$ make\n" . $shown)
                ->and(explode("\n", $t->region()))->toHaveCount(3)
                ->and($t->region())->not->toContain('build')->and($t->region())->toEndWith('2 running');
            // a row by its number: the first one
            $t->freeze(0);
            $app->jobs = ['test' => 0.5];
            $app->requestRender();
            $t->settle();
            expect(explode("\n", $t->frozen()))->toHaveCount(3)->and(explode("\n", $t->frozen())[2])->toEndWith('fetch 0%')
                ->and($t->region())->toContain('test 50%');
        });
        expect(explode("\n", $t->frozen())[1])->toEndWith('build 100%');      // (detached: all the terminal shows)
    });
});

it('keeps frozen rows full screen and writes them after detach, as println() does', function () {
    \fiber\run(function () {
        $t = new FakeTerminal(30, 6);
        $app = (new JobsScreen())->start($t);
        $t->settle();
        $t->freeze(0);
        expect($t->output())->not->toContain("fetch 0%\e[0m\r\n");
        $t->detach();
        expect($t->surface->isAltScreen())->toBeFalse()->and($t->text())->toContain('fetch 0%');
    });
});
