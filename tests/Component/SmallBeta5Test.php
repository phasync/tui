<?php

/*
 * TODO-0.0.2-beta5 item 4: a component reaches its terminal device (println(), freeze()) also
 * when Tui made the device; freeze() takes the final render; a method of the component named
 * like one the library uses privately (key()) is bound like any other; the library's own
 * methods are final, so a clash fails where it is declared.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Tui;

/** Copies files in its coroutine: logs each above the region and freezes its bar when done. */
final class CopyJobs extends Component
{
    /** @var array<string, float> */
    public array $done = ['a.txt' => 0.0, 'b.txt' => 0.0];

    public function bar(string $file): ProgressBar
    {
        return $this->child("bar:$file", fn() => new ProgressBar(label: $file));
    }

    protected function render(): Element
    {
        $rows = [];
        foreach ($this->done as $file => $value) {
            $bar = $this->bar($file);
            $bar->setValue($value);
            $rows[] = $bar;
        }
        return new Column([...$rows, new Text(\count($this->done) . ' left')]);
    }

    protected function run(): void
    {
        foreach (array_keys($this->done) as $file) {
            for ($i = 1; $i <= 4; $i++) {
                $this->sleep(0.01);
                $this->done[$file] = $i / 4;
                $this->requestRender();
            }
            $this->device()?->println("copied $file");
            $this->device()?->freeze($this->bar($file)->surface);     // at once: the final row
            unset($this->done[$file]);
            $this->requestRender();
        }
        $this->dispose();
    }
}

it('lets a component reach its device: println() and freeze() of the final row, with Tui::headless() too', function () {
    \fiber\run(function () {
        $t = FakeTerminal::inline(40, 8);
        $app = (new CopyJobs())->start($t);
        expect($app->device())->toBe($t);
        $t->run(function (FakeTerminal $t) use ($app) {
            $t->waitFor(fn() => !$app->isMounted(), 5);
        });
        $frozen = explode("\n", $t->frozen());
        expect($frozen[0])->toBe('copied a.txt')
            ->and($frozen[1])->toContain('a.txt 100%')          // not 75%: what it set last
            ->and($frozen[2])->toBe('copied b.txt')
            ->and($frozen[3])->toContain('b.txt 100%');
        expect($app->device())->toBeNull();                     // ended: no device
    });
    \fiber\run(function () {
        $t = Tui::headless(fn() => new CopyJobs(), 40, 8);
        expect($t->waitFor(fn() => !$t->isAttached(), 5))->toBeTrue();
        expect($t->text())->toContain('a.txt 100%')->and($t->text())->toContain('copied b.txt');
    });
});

it('binds a public method named like one the library keeps for itself', function () {
    $app = new class () extends Component {
        public int $hits = 0;

        protected function bindings(): array
        {
            return ['k' => 'key', 'x' => 'end'];
        }

        public function key(): void       // Component has a private key() of its own
        {
            $this->hits++;
        }

        public function end(): void       // and a private end()
        {
            $this->hits += 10;
        }

        protected function render(): Element
        {
            return new Text("hits {$this->hits}");
        }
    };
    mountOn($app, 20, 2, function (FakeTerminal $t, Component $app) {
        $t->press('k', 'x', 'k');
        $t->settle();
        expect($app->hits)->toBe(12)->and($t->text())->toStartWith('hits 12')->and($app->isMounted())->toBeTrue();
    });
});

it('fails where a component declares a method the library relies on, naming it', function () {
    $file = tempnam(sys_get_temp_dir(), 'clash') . '.php';
    file_put_contents($file, '<?php require ' . var_export(__DIR__ . '/../../vendor/autoload.php', true) . ';'
        . ' final class Clash extends phasync\Tui\Component { public function children(): array { return []; } }');
    exec(PHP_BINARY . ' ' . escapeshellarg($file) . ' 2>&1', $out, $status);
    unlink($file);
    expect($status)->not->toBe(0)
        ->and(implode("\n", $out))->toContain('Cannot override final method phasync\Tui\Component::children()');
});
