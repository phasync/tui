<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Internal\MarkdownBlocks;
use phasync\Tui\Mouse;
use phasync\Tui\Style;
use phasync\Tui\Text;

/**
 * Markdown (CommonMark and GFM tables) shown in the terminal, scrolling, and fed by a stream.
 *
 *     $md = $this->child('answer', fn() => new Markdown(tail: true));
 *     foreach ($tokens as $chunk) {
 *         $md->append($chunk);        // may end in the middle of a fence, a link, an emphasis
 *     }
 *
 * **What it renders**: headings (ATX and setext), paragraphs wrapped between words at the width
 * it has, `*emphasis*`, `**strong**`, `~~strike~~`, `` `code` `` spans, links (the text, and
 * the address dimmed after it, or with `hyperlinks: true` an OSC 8 hyperlink and no address),
 * bullet and numbered lists nested to any depth, block quotes, fenced and indented code blocks
 * (with a simple highlighter for php, js, sh and json), tables and thematic breaks. Text is
 * measured by display width: wide characters and emoji take two cells. Escape sequences and
 * control characters in the text are dropped, so text from a model or a file can not drive the
 * terminal. Not supported: raw HTML (shown as text), reference links, footnotes, task boxes.
 *
 * **Streaming.** `append()` takes text that ends anywhere; the whole text is read again as it
 * is (an open fence is a code block that runs to the end, an emphasis with no end yet is its
 * asterisk), so what shows is the best the text so far allows and becomes the final rendering
 * as the rest arrives. The work is kept small: the rows of every block that did not change are
 * remembered (only the last block, the tail, is drawn anew), and only the screen rows whose
 * contents changed are written to the terminal. `set()` replaces the text.
 *
 * **Scrolling.** Up/Down (k/j), Page Up/Page Down (Space), Home/End and the mouse wheel; the
 * keys go on to the parent when it cannot scroll further. With `tail: true` the view follows
 * the end as text arrives, until the user scrolls up; scrolling back to the end (End) follows
 * again, and so does `follow()`. Event `scroll`: the rows in view or `isFollowing()` changed.
 *
 * **Style** parts, each a `Markdown::part` rule in the stylesheet (the default theme sets
 * them): `paragraph`, `h1` to `h6`, `strong`, `emphasis`, `strike`, `code` (a span),
 * `codeblock` (the block and its fill), `keyword`, `string`, `comment`, `number`, `variable`
 * and `key` (highlighting), `link`, `url`, `quote` (the text and the bar), `bullet`, `rule`,
 * `th` and `td` (table cells) and `border` (the lines of a table). Styles of the parts that
 * apply together (`strong` inside `h2`, `link` inside `quote`) are combined, the innermost one
 * winning a colour.
 */
class Markdown extends Component
{
    private const PUNCT = '!"#$%&\'()*+,-./:;<=>?@[\]^_`{|}~';

    private const KEYWORDS = [
        'php' => 'abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|do|echo|else|elseif|enum|extends|false|final|finally|fn|for|foreach|function|global|if|implements|include|instanceof|interface|isset|match|namespace|new|null|or|private|protected|public|readonly|require|return|static|switch|this|throw|trait|true|try|use|var|while|yield',
        'js' => 'async|await|break|case|catch|class|const|continue|default|delete|do|else|export|extends|false|finally|for|from|function|if|import|in|instanceof|interface|let|new|null|of|return|static|super|switch|this|throw|true|try|type|typeof|undefined|var|void|while|yield',
        'sh' => 'case|do|done|elif|else|esac|exit|export|fi|for|function|if|in|local|return|select|then|until|while',
        'json' => 'true|false|null',
    ];

    private const LANGUAGES = [
        'php' => 'php', 'js' => 'js', 'javascript' => 'js', 'ts' => 'js', 'typescript' => 'js', 'jsx' => 'js', 'tsx' => 'js', 'mjs' => 'js',
        'sh' => 'sh', 'bash' => 'sh', 'shell' => 'sh', 'zsh' => 'sh', 'console' => 'sh', 'json' => 'json', 'jsonc' => 'json',
    ];

    private const PARTS = [
        'paragraph', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'emphasis', 'strike', 'code', 'codeblock', 'keyword',
        'string', 'comment', 'number', 'variable', 'key', 'link', 'url', 'quote', 'bullet', 'rule', 'th', 'td', 'border',
    ];

    private string $text = '';
    /** @var list<string> every row of the document at the width it was laid out for, styled */
    private array $rows = [];
    /** @var array<string, list<string>> the rows of the blocks of the last layout, by width and source */
    private array $cache = [];
    private bool $stale = true;
    private int $laidCols = 0;
    private string $laidStyles = '';
    /** @var array<string, Style> */
    private array $styles = [];
    /** @var array<string, string> */
    private array $sgrs = [];
    private int $top = 0;
    private bool $stuck;
    /** @var array<int, string> what is on the screen, by row */
    private array $painted = [];
    /** @var array{int,int}|null */
    private ?array $paintedSize = null;
    /** @var array{int,int,bool}|null */
    private ?array $inView = null;

    /**
     * @param string $text       the initial text
     * @param bool   $tail       follow the end while text arrives (a prop)
     * @param bool   $hyperlinks show links as OSC 8 hyperlinks (the text only, clickable) instead of the text
     *                           with its address after it; for terminals that support them (a prop)
     */
    public function __construct(string $text = '', public bool $tail = false, public bool $hyperlinks = false)
    {
        $this->text = $text;
        $this->stuck = $tail;
    }

    protected function propsChanged(): void
    {
        $this->stale = true;
        $this->stuck = $this->tail && ($this->stuck || $this->top >= $this->maxTop());
    }

    // ------------------------------------------------------------------------------------------
    // The text

    /** Replace all the text. */
    public function set(string $text): void
    {
        if ($text !== $this->text) {
            $this->text = $text;
            $this->stale = true;
            $this->requestRender();
        }
    }

    /** Add text at the end; it may end in the middle of anything. */
    public function append(string $chunk): void
    {
        if ($chunk !== '') {
            $this->text .= $chunk;
            $this->stale = true;
            $this->requestRender();
        }
    }

    /** The Markdown text, as given. */
    public function text(): string
    {
        return $this->text;
    }

    // ------------------------------------------------------------------------------------------
    // Scrolling

    protected function bindings(): array
    {
        return [
            'up' => 'lineUp', 'k' => 'lineUp', 'down' => 'lineDown', 'j' => 'lineDown',
            'pageup' => 'pageUp', 'pagedown' => 'pageDown', 'space' => 'pageDown', 'home' => 'toTop', 'end' => 'toBottom',
        ];
    }

    /** The row of the document at the top of the view. */
    public function topRow(): int
    {
        return $this->top;
    }

    /** How many rows the whole document takes at the width it has. */
    public function rowCount(): int
    {
        $this->layoutIfStale();
        return \count($this->rows);
    }

    /** True while the view follows the end (`tail` mode, and not scrolled back). */
    public function isFollowing(): bool
    {
        return $this->stuck;
    }

    /** Follow the end again (does nothing without `tail`). */
    public function follow(): void
    {
        if ($this->tail && !$this->stuck) {
            $this->stuck = true;
            $this->requestRender();
        }
    }

    /** Show document row $row at the top (clamped). */
    public function scrollTo(int $row): void
    {
        $max = $this->maxTop();
        $row = max(0, min($row, $max));
        $this->stuck = $this->tail && $row >= $max;
        $this->top = $row;
        $this->requestRender();
    }

    /** Scroll by $rows (negative: up); false when it could not move. */
    public function scrollBy(int $rows): bool
    {
        $before = $this->top;
        $this->scrollTo(($this->stuck ? $this->maxTop() : $this->top) + $rows);
        return $this->top !== $before;
    }

    public function lineUp(): bool
    {
        return $this->scrollBy(-1);
    }

    public function lineDown(): bool
    {
        return $this->scrollBy(1);
    }

    public function pageUp(): bool
    {
        return $this->scrollBy(-max(1, $this->surface->rows() - 1));
    }

    public function pageDown(): bool
    {
        return $this->scrollBy(max(1, $this->surface->rows() - 1));
    }

    public function toTop(): bool
    {
        return $this->scrollBy(-$this->top);
    }

    public function toBottom(): bool
    {
        $moved = $this->scrollBy($this->maxTop() - $this->top);
        return $moved;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($mouse->action === Mouse::WHEEL_UP || $mouse->action === Mouse::WHEEL_DOWN) {
            $this->scrollBy($mouse->action === Mouse::WHEEL_UP ? -3 : 3);
            return true;
        }
        return false;
    }

    private function maxTop(): int
    {
        $this->layoutIfStale();
        return max(0, \count($this->rows) - $this->surface->rows());
    }

    // ------------------------------------------------------------------------------------------
    // Drawing

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $height = $this->surface->rows();
        $this->layoutIfStale();
        $max = max(0, \count($this->rows) - $height);
        $this->top = $this->stuck ? $max : max(0, min($this->top, $max));
        $out = '';
        if ($this->paintedSize !== [$cols, $height]) {
            $this->paintedSize = [$cols, $height];
            $this->painted = [];
            $out = "\e[0m\e[2J";
        }
        for ($y = 0; $y < $height; $y++) {
            $row = $this->rows[$this->top + $y] ?? '';
            if (($this->painted[$y] ?? null) !== $row) {
                $this->painted[$y] = $row;
                $out .= Ansi::moveTo(0, $y) . "\e[0m\e[2K" . $row . "\e[0m";
            }
        }
        if ($out !== '') {
            $this->surface->write("\e[?7l\e[?25l" . $out . "\e[?7h");
        }
        $place = [$this->top, $this->top + $height - 1, $this->stuck];
        if ($place !== $this->inView) {
            $this->inView = $place;
            $this->emit('scroll', $this);
        }
        return null;
    }

    /** Lay the document out again if the text, the width or the styles changed. */
    private function layoutIfStale(): void
    {
        $cols = max(1, $this->surface->cols());
        $this->styles = [];
        foreach (self::PARTS as $part) {
            $this->styles[$part] = $this->style($part)->text;
        }
        $sig = md5(implode('|', array_map(Ansi::style(...), $this->styles)));
        if (!$this->stale && $cols === $this->laidCols && $sig === $this->laidStyles) {
            return;
        }
        if ($sig !== $this->laidStyles || $cols !== $this->laidCols) {
            $this->cache = [];
        }
        [$this->stale, $this->laidCols, $this->laidStyles, $this->sgrs] = [false, $cols, $sig, []];
        $rows = [];
        $kept = [];
        foreach (MarkdownBlocks::parse($this->text) as $k => $block) {
            $key = $block['src'];
            $kept[$key] = $this->cache[$key] ?? $this->block($block, $cols, [], 0);
            if ($k > 0) {
                $rows[] = '';
            }
            array_push($rows, ...$kept[$key]);
        }
        $this->cache = $kept;
        $this->rows = $rows;
    }

    /**
     * @param array<string, mixed> $b
     * @param list<string> $base parts the text of the block is in, outermost first
     * @return list<string> rows, each starting in the state it needs
     */
    private function block(array $b, int $w, array $base, int $depth): array
    {
        $w = max(1, $w);
        switch ($b['t']) {
            case 'heading':
                return $this->wrapped($this->inline($b['text'], [...$base, 'h' . $b['level']]), $w);
            case 'para':
                return $this->wrapped($this->inline($b['text'], [...$base, 'paragraph']), $w);
            case 'rule':
                return [$this->sgr([...$base, 'rule']) . str_repeat('─', $w) . "\e[0m"];
            case 'code':
                return $this->codeBlock($b['info'], $b['lines'], $w);
            case 'quote':
                $inner = $this->blocks($b['children'], $w - 2, [...$base, 'quote'], $depth, true);
                $bar = $this->sgr([...$base, 'quote']) . '│ ' . "\e[0m";
                return array_map(fn($r) => $bar . $r, $inner ?: ['']);
            case 'list':
                return $this->listRows($b, $w, $base, $depth);
            case 'table':
                return $this->table($b, $w, $base);
        }
        return [];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $base
     * @return list<string>
     */
    private function blocks(array $blocks, int $w, array $base, int $depth, bool $spaced): array
    {
        $rows = [];
        foreach ($blocks as $k => $child) {
            if ($k > 0 && $spaced) {
                $rows[] = '';
            }
            array_push($rows, ...$this->block($child, $w, $base, $depth));
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $b
     * @param list<string> $base
     * @return list<string>
     */
    private function listRows(array $b, int $w, array $base, int $depth): array
    {
        $rows = [];
        $last = $b['start'] + \count($b['items']) - 1;
        $numberWidth = \strlen((string) $last) + 2;
        foreach ($b['items'] as $k => $item) {
            if ($k > 0 && $b['loose']) {
                $rows[] = '';
            }
            $marker = $b['ordered'] ? str_pad(($b['start'] + $k) . '.', $numberWidth - 1, ' ', \STR_PAD_LEFT) . ' ' : ['•', '◦', '▪'][min($depth, 2)] . ' ';
            $pad = str_repeat(' ', Text::width($marker));
            $inner = $this->blocks($item, $w - Text::width($marker), $base, $depth + 1, $b['loose']) ?: [''];
            foreach ($inner as $r => $row) {
                $rows[] = ($r === 0 ? $this->sgr([...$base, 'bullet']) . $marker . "\e[0m" : $pad) . $row;
            }
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $b
     * @param list<string> $base
     * @return list<string>
     */
    private function table(array $b, int $w, array $base): array
    {
        $cols = \count($b['head']);
        $cell = fn(string $text, string $part): string => $this->inline(str_replace("\n", ' ', $text), [...$base, $part]);
        $grid = [array_map(fn($c) => $cell($c, 'th'), $b['head'])];
        foreach ($b['rows'] as $row) {
            $grid[] = array_map(fn($c) => $cell($c, 'td'), $row);
        }
        $widths = array_fill(0, $cols, 1);
        foreach ($grid as $row) {
            foreach ($row as $i => $c) {
                $widths[$i] = max($widths[$i], Text::width($c));
            }
        }
        // the borders take cols + 1 cells and a space each side of a cell
        $avail = $w - ($cols + 1) - 2 * $cols;
        while (array_sum($widths) > max($avail, $cols)) {
            $widest = array_search(max($widths), $widths, true);
            if ($widths[$widest] <= 1) {
                break;
            }
            $widths[$widest]--;
        }
        $line = fn(string $l, string $m, string $r): string => $this->sgr([...$base, 'border']) . $l . implode($m, array_map(fn($x) => str_repeat('─', $x + 2), $widths)) . $r . "\e[0m";
        $bar = $this->sgr([...$base, 'border']) . '│' . "\e[0m";
        $rows = [$line('┌', '┬', '┐')];
        foreach ($grid as $r => $row) {
            $cells = [];
            foreach ($row as $i => $c) {
                $cells[$i] = $this->wrapped($c, $widths[$i]);
            }
            for ($y = 0; $y < max(array_map('count', $cells)); $y++) {
                $text = $bar;
                foreach ($cells as $i => $lines) {
                    $text .= ' ' . Text::pad($lines[$y] ?? '', $widths[$i], $b['align'][$i]) . "\e[0m " . $bar;
                }
                $rows[] = $text;
            }
            if ($r === 0) {
                $rows[] = $line('├', '┼', '┤');
            }
        }
        $rows[] = $line('└', '┴', '┘');
        return $rows;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function codeBlock(string $info, array $lines, int $w): array
    {
        $language = self::LANGUAGES[strtolower(explode(' ', trim($info))[0])] ?? null;
        $code = implode("\n", $lines);
        $styled = $language === null ? $this->sgr(['codeblock']) . $code : $this->highlight($code, $language);
        $fill = $this->sgr(['codeblock']);
        $inner = max(1, $w - 2);
        $rows = [];
        foreach (self::carry(explode("\n", $styled)) as $line) {
            foreach (self::carry(Text::wrap($line, $inner)) as $row) {
                $rows[] = $fill . ' ' . $row . $fill . str_repeat(' ', max(0, $inner - Text::width($row)) + 1) . "\e[0m";
            }
        }
        return $rows ?: [$fill . str_repeat(' ', $w) . "\e[0m"];
    }

    /** $code with the tokens of $language in the styles of their parts, "\n" kept. */
    private function highlight(string $code, string $language): string
    {
        $q = fn(string $c) => $c . '(?:\\\\.|[^' . $c . '\\\\])*(?:' . $c . '|\z)';
        $strings = $q("'") . '|' . $q('"');
        $number = '\b\d[\d_]*(?:\.\d+)?(?:e[+-]?\d+)?\b|\b0x[0-9a-f]+\b';
        $words = '\b(?:' . self::KEYWORDS[$language] . ')\b';
        $patterns = match ($language) {
            'php' => ['comment' => '//[^\n]*|#(?!\[)[^\n]*|/\*.*?(?:\*/|\z)', 'string' => $strings, 'variable' => '\$\w+', 'number' => $number, 'keyword' => '<\?php|\?>|' . $words],
            'js' => ['comment' => '//[^\n]*|/\*.*?(?:\*/|\z)', 'string' => $strings . '|' . $q('`'), 'number' => $number, 'keyword' => $words],
            'sh' => ['comment' => '(?<!\S)#[^\n]*', 'string' => $strings, 'variable' => '\$(?:\{[^}\n]*\}?|\w+|[?#@*$!])', 'number' => '(?<!\S)--?[A-Za-z][\w-]*', 'keyword' => $words],
            'json' => ['key' => '"(?:\\\\.|[^"\\\\])*"(?=\s*:)', 'string' => $q('"'), 'number' => '-?\b\d+(?:\.\d+)?(?:e[+-]?\d+)?\b', 'keyword' => $words],
        };
        $regex = '~' . implode('|', array_map(fn($name, $p) => "(?<$name>$p)", array_keys($patterns), $patterns)) . '~isu';
        $out = '';
        $at = 0;
        preg_match_all($regex, $code, $all, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($all as $m) {
            foreach (array_keys($patterns) as $name) {
                if (isset($m[$name]) && $m[$name][1] >= 0 && $m[$name][0] !== '') {
                    $out .= $this->sgr(['codeblock']) . substr($code, $at, $m[0][1] - $at) . $this->sgr(['codeblock', $name]) . $m[0][0];
                    $at = $m[0][1] + \strlen($m[0][0]);
                    break;
                }
            }
        }
        return $out . $this->sgr(['codeblock']) . substr($code, $at);
    }

    // ------------------------------------------------------------------------------------------
    // Inline text

    /** @param list<string> $parts @return string the SGR sequence that sets the combined style of $parts */
    private function sgr(array $parts): string
    {
        return $this->sgrs[$key = implode(' ', $parts)] ??= $this->combine($parts);
    }

    /** @param list<string> $parts */
    private function combine(array $parts): string
    {
        $fg = $bg = Color::DEFAULT;
        $attrs = 0;
        foreach ($parts as $part) {
            $s = $this->styles[$part] ?? Style::plain();
            $fg = $s->fg !== Color::DEFAULT ? $s->fg : $fg;
            $bg = $s->bg !== Color::DEFAULT ? $s->bg : $bg;
            $attrs |= $s->attrs;
        }
        return Ansi::style(Style::of($fg, $bg, $attrs));
    }

    /**
     * Inline Markdown as a styled text; "\n" is a hard line break.
     *
     * @param list<string> $parts
     */
    private function inline(string $s, array $parts, bool $inLink = false): string
    {
        $out = $buf = '';
        $len = \strlen($s);
        $flush = function () use (&$out, &$buf, $parts): void {
            if ($buf !== '') {
                $out .= $this->sgr($parts) . html_entity_decode($buf, \ENT_QUOTES | \ENT_HTML5);
                $buf = '';
            }
        };
        for ($i = 0; $i < $len;) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len && str_contains(self::PUNCT, $s[$i + 1])) {
                $buf .= $s[$i + 1];
                $i += 2;
            } elseif ($c === '`') {
                $n = strspn($s, '`', $i);
                if (preg_match('/(?<!`)`{' . $n . '}(?!`)/', $s, $m, \PREG_OFFSET_CAPTURE, $i + $n)) {
                    $flush();
                    $code = str_replace("\n", ' ', substr($s, $i + $n, $m[0][1] - $i - $n));
                    if (strlen($code) > 2 && $code[0] === ' ' && str_ends_with($code, ' ') && trim($code) !== '') {
                        $code = substr($code, 1, -1);
                    }
                    $out .= $this->sgr([...$parts, 'code']) . $code;
                    $i = $m[0][1] + $n;
                } else {
                    $buf .= str_repeat('`', $n);
                    $i += $n;
                }
            } elseif (($c === '[' || ($c === '!' && ($s[$i + 1] ?? '') === '[')) && !$inLink && ($link = $this->link($s, $i + ($c === '!' ? 1 : 0)))) {
                $flush();
                [$end, $text, $url] = $link;
                $out .= $this->linked($this->inline($text, [...$parts, 'link'], true), $text, $url, $parts);
                $i = $end;
            } elseif ($c === '<' && !$inLink && preg_match('/<([A-Za-z][A-Za-z0-9+.-]{1,31}:[^\s<>]*)>/A', $s, $m, 0, $i)) {
                $flush();
                $out .= $this->linked($this->sgr([...$parts, 'link']) . $m[1], $m[1], $m[1], $parts);
                $i += \strlen($m[0]);
            } elseif ($c === '*' || $c === '_' || ($c === '~' && ($s[$i + 1] ?? '') === '~')) {
                $n = strspn($s, $c, $i);
                $marker = $c === '~' ? '~~' : str_repeat($c, min($n, 3) === 3 ? 3 : min($n, 2));
                $names = ['~~' => ['strike'], '*' => ['emphasis'], '_' => ['emphasis'], '**' => ['strong'], '__' => ['strong'], '***' => ['strong', 'emphasis'], '___' => ['strong', 'emphasis']][$marker];
                $close = $this->closer($s, $marker, $i + \strlen($marker), $i, $c);
                if ($close !== null) {
                    $flush();
                    $out .= $this->inline(substr($s, $i + \strlen($marker), $close - $i - \strlen($marker)), [...$parts, ...$names], $inLink);
                    $i = $close + \strlen($marker);
                } else {
                    $buf .= str_repeat($c, $n);
                    $i += $n;
                }
            } else {
                $k = max(1, strcspn($s, '\\`[*_~<!', $i));
                $buf .= substr($s, $i, $k);
                $i += $k;
            }
        }
        $flush();
        return $out;
    }

    /** Where the delimiter $marker that opened at $open closes, searching from $from; null if it does not. */
    private function closer(string $s, string $marker, int $from, int $open, string $c): ?int
    {
        $m = \strlen($marker);
        $before = $open > 0 ? $s[$open - 1] : ' ';
        $after = $s[$from] ?? ' ';
        if (ctype_space($after) || ($c === '_' && ctype_alnum($before))) {
            return null;
        }
        for ($p = $from; ($p = strpos($s, $c, $p)) !== false;) {
            $run = strspn($s, $c, $p);
            $ok = $p > $from && !ctype_space($s[$p - 1]) && ($m === 1 ? $run === 1 : $run >= $m)
                && ($c !== '_' || !ctype_alnum($s[$p + $run] ?? ' '));
            if ($ok) {
                return $p;
            }
            $p += $run;
        }
        return null;
    }

    /**
     * @return array{int, string, string}|null the end, the text and the address of the link whose `[` is at $i
     */
    private function link(string $s, int $i): ?array
    {
        $depth = 0;
        for ($j = $i; $j < \strlen($s); $j++) {
            if ($s[$j] === '\\') {
                $j++;
            } elseif ($s[$j] === '[') {
                $depth++;
            } elseif ($s[$j] === ']' && --$depth === 0) {
                break;
            }
        }
        if ($j >= \strlen($s) || !preg_match('/\(\s*(<[^>\n]*>|[^\s()]*(?:\([^\s()]*\)[^\s()]*)*)(?:\s+(?:"[^"]*"|\'[^\']*\'))?\s*\)/A', $s, $m, 0, $j + 1)) {
            return null;
        }
        return [$j + 1 + \strlen($m[0]), substr($s, $i + 1, $j - $i - 1), trim($m[1], '<>')];
    }

    /**
     * A link as it is shown: the text, then its address dimmed, or a hyperlink around the text.
     *
     * @param list<string> $parts
     */
    private function linked(string $styled, string $text, string $url, array $parts): string
    {
        if ($url === '') {
            return $styled;
        }
        if ($this->hyperlinks) {
            return "\e]8;;" . $url . "\e\\" . $styled . "\e]8;;\e\\";
        }
        return $text === $url ? $styled : $styled . $this->sgr([...$parts, 'url']) . ' (' . $url . ')';
    }

    // ------------------------------------------------------------------------------------------
    // Rows

    /** @return list<string> $styled wrapped between words to $w, each row starting in the state it needs */
    private function wrapped(string $styled, int $w): array
    {
        return self::carry(Text::wrap($styled, $w, true));
    }

    /**
     * Make each row stand alone: it starts with the style and hyperlink the rows before it left
     * on, and a hyperlink still open at its end is closed.
     *
     * @param list<string> $rows
     * @return list<string>
     */
    private static function carry(array $rows): array
    {
        $sgr = '';
        $link = null;
        foreach ($rows as &$row) {
            $start = $sgr . ($link === null ? '' : "\e]8;;" . $link . "\e\\");
            if (preg_match_all('/\e\[[0-9;:]*m|\e\]8;;([^\e\x07]*)(?:\e\\\\|\x07)/', $row, $found, \PREG_SET_ORDER)) {
                foreach ($found as $t) {
                    if ($t[0][1] === '[') {
                        $sgr = $t[0] === "\e[0m" || $t[0] === "\e[m" ? '' : $t[0];
                    } else {
                        $link = ($t[1] ?? '') === '' ? null : $t[1];
                    }
                }
            }
            $row = $start . $row . ($link === null ? '' : "\e]8;;\e\\");
        }
        return $rows;
    }
}
