<?php

/*
 * TODO-0.0.3-beta3 item 4, small things from use: headless runs without editing, completion
 * and dropdown lists outside the Box border, Table keeps its place when the current row goes,
 * a Dialog as wide as its content (at most 80%), the cookbook's plural.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Form;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Table;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;
use phasync\Tui\Tui;

/** Run a PHP file with arguments and environment; [exit status, stdout, stderr]. */
function runPhp(string $file, array $args = [], array $env = []): array
{
    $cmd = array_merge([PHP_BINARY, $file], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [...getenv(), ...$env]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
}

it('runs examples/dashboard.php headless as written, by argument or environment', function () {
    $file = dirname(__DIR__, 2) . '/examples/dashboard.php';
    [$status, $out, $err] = runPhp($file, ['--headless=100x24@0.4']);
    expect($status)->toBe(0)->and($err)->toBe('')
        ->and($out)->toContain('deploying')->toContain('╭ build')->toContain('release notes')->toContain('ctrl+q quit')
        ->and(count(explode("\n", rtrim($out))))->toBeLessThanOrEqual(24)
        ->and($out)->not->toContain("\e[");                    // the screen as text, not escape sequences
    [$status, $out] = runPhp($file, ['--inline'], ['TUI_HEADLESS' => '60x12@0.3']);
    expect($status)->toBe(0)->and($out)->toContain('╭ build')->toContain('╭ deploy')->not->toContain('release notes');
    [$status, , $err] = runPhp($file, ['--headless=wide']);
    expect($status)->not->toBe(0)->and($err)->toContain("'wide' is not COLSxROWS");
});

it('opens a completion list under the Box of an Editor that is its only content, never over its border', function () {
    $editor = new Editor(maxLines: 1, complete: fn(string $word) => array_values(array_filter(['alpha', 'also', 'beta'], fn($w) => str_starts_with($w, $word))));
    $app = new class ($editor) extends Component {
        public function __construct(private Editor $editor)
        {
        }

        protected function render(): Element
        {
            return new Column([new Box($this->child('e', fn() => $this->editor), 'word'), new Text("one\ntwo\nthree\nfour\nfive\nsix")]);
        }
    };
    mountOn($app, 30, 10, function (FakeTerminal $t) use ($editor) {
        $editor->focus();
        $t->type('al');
        $t->press('tab');
        $t->settle();
        $rows = explode("\n", $t->text());
        expect($rows[0])->toStartWith('╭ word')->and($rows[1])->toStartWith('│al')
            ->and($rows[2])->toStartWith('╰─')                       // the border stays
            ->and($rows[3])->toContain('alpha')->and($rows[4])->toContain('also');
    });
});

it('opens a dropdown under the Box of a Select that is its only content', function () {
    $select = new Select(['a' => 'Apple', 'b' => 'Banana', 'c' => 'Cherry'], dropdown: true);
    $app = new class ($select) extends Component {
        public function __construct(private Select $select)
        {
        }

        protected function render(): Element
        {
            return new Column([new Box($this->child('s', fn() => $this->select), 'fruit'), new Text("one\ntwo\nthree\nfour\nfive")]);
        }
    };
    mountOn($app, 30, 10, function (FakeTerminal $t) use ($select) {
        $select->focus();
        $t->press('down');
        $t->settle();
        expect($select->isOpen())->toBeTrue();
        $rows = explode("\n", $t->text());
        expect($rows[2])->toStartWith('╰─')->and($rows[3])->toContain('Apple')->and($rows[4])->toContain('Banana');
    });
});

it('keeps the place in a Table when the current row is deleted, clamped at the end', function () {
    fiber\run(function () {
        $s = new State(['rows' => array_map(fn($i) => ["row $i"], range(0, 29))]);
        $table = new Table(['Name' => 'auto'], []);
        $t = Tui::headless(function () use ($s, $table) {
            $table->rows = $s->rows;
            $table->requestRender();
            return $table;
        }, 30, 8);
        $t->run(function (FakeTerminal $t) use ($s, $table) {
            $t->settle();
            $table->setCurrent(20);
            $t->settle();
            expect($table->current())->toBe(20)->and($t->text())->toContain('row 20');
            $rows = $s->rows;
            unset($rows[20]);
            $s->rows = $rows;                               // the current row goes: the one after it takes its place
            $t->settle();
            expect($table->current())->toBe(21)->and($t->text())->toContain('row 21')->not->toContain('row 0');
            $s->rows = array_slice($s->rows, 0, 10, true);  // fewer rows than the place: the last one
            $t->settle();
            expect($table->current())->toBe(9)->and($t->text())->toContain('row 9');
        });
    });
});

it('makes a Dialog as wide as its content, at most 80% of the screen', function () {
    fiber\run(function () {
        $t = Tui::headless(new Text('screen'), 100, 20);
        $t->run(function (FakeTerminal $t) {
            $t->settle();
            $width = function () use ($t): int {
                foreach (explode("\n", $t->text()) as $line) {
                    if (preg_match('/╭─*╮/u', $line, $m)) {
                        return mb_strlen($m[0]);
                    }
                }
                return 0;
            };
            $answer = Dialog::open(new Dialog('Short', 'Hi.'));
            $t->settle();
            expect($width())->toBeLessThan(20);
            $t->press('enter');
            $t->settle();
            expect(fiber\await($answer))->toBeTrue();
            Dialog::open(new Dialog('Rename', new Form((new Editor(maxLines: 1))->with(id: 'name'))));
            $t->settle();
            expect($width())->toBeLessThanOrEqual(80)->toBeGreaterThan(60);      // a field that fills: 80%
            $t->press('escape');
        });
    });
});

it('says "1 sample" in cookbook recipe 9', function () {
    $cookbook = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/cookbook.md');
    expect($cookbook)->toContain("' sample' : ' samples'");
});
