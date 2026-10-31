<?php

/**
 * A Markdown viewer fed by a stream: a document "arrives" in chunks that end anywhere, as from a
 * language model, and the view follows the end until you scroll back.
 *
 *     php examples/markdown.php [file.md]
 *
 * Up/Down (k/j), Page Up/Page Down, Home/End and the mouse wheel scroll; End follows the stream
 * again. Ctrl-R streams the document again, Ctrl-Q quits. With a file argument that file is
 * shown instead of the built-in sample.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Component\Markdown;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Tty;

final class Viewer extends Component
{
    public function __construct(private readonly string $document)
    {
    }

    protected function bindings(): array
    {
        return [
            'ctrl+r' => ['restart', 'again'],
            'ctrl+q' => ['quit', 'quit', 'always' => true],
        ];
    }

    private function view(): Markdown
    {
        return $this->child('view', fn() => new Markdown(tail: true));
    }

    protected function render(): Element
    {
        return new Column([$this->view(), new Help(show: 'application')]);
    }

    protected function run(): void
    {
        $this->stream();
    }

    private function stream(): void
    {
        $at = 0;
        while ($at < \strlen($this->document) && $this->isMounted()) {
            $n = random_int(1, 24);
            $this->view()->append(substr($this->document, $at, $n));
            $at += $n;
            if (!$this->sleep(0.02)) {
                return;
            }
        }
    }

    public function restart(): void
    {
        $this->view()->set('');
        $this->go($this->stream(...));
    }

    public function quit(): void
    {
        $this->dispose();
    }
}

$sample = <<<'MD'
# Streaming Markdown

This text arrives in **small chunks** that end anywhere: in the middle of a word, of an
*emphasis*, of a [link](https://phasync.dev/tui) or of a code block. What shows is the best the
text so far allows, and it settles as the rest comes in.

## Lists and quotes

- Headings, paragraphs and `inline code`
- Lists, nested
  - like this one
  - with **strong** and ~~strike~~
- Block quotes and tables

1. Numbered lists
2. keep their numbers aligned

> A block quote can hold anything a document can,
> - even a list.

## Code

```php
<?php
// highlighted by a very small tokenizer
function greet(string $name): string {
    return "Hello, $name!";
}
```

```sh
export PATH="$HOME/bin:$PATH" # json and js work too
```

## A table

| Widget   | Keys           | Mouse  |
|:---------|:--------------:|-------:|
| Markdown | Up/Down, Pg    | wheel  |
| Table    | Up/Down        | click  |

---

Wide characters and emoji take two columns: 中文字符 😀. That is all.
MD;

$tty = new Tty();
if (!$tty->isTerminal()) {
    fwrite(STDERR, "markdown needs a terminal\n");
    exit(1);
}
fiber\run(fn() => (new Viewer(isset($argv[1]) ? file_get_contents($argv[1]) : $sample))->start($tty));
