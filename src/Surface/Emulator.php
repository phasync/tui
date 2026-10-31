<?php

namespace phasync\Tui\Surface;

use phasync\Tui\Color;
use phasync\Tui\Style;
use phasync\Tui\Text;

/**
 * @internal The terminal state machine behind a Surface: the cells, cursor, modes and scrollback,
 * and the actions the generated AnsiParser calls for each complete item of the byte stream.
 * Behaviour follows xterm (TERM=xterm-256color); where xterm and the DEC manuals differ, xterm wins.
 */
final class Emulator
{
    /** DEC Special Graphics: the line drawing set selected with ESC ( 0. */
    private const DEC_GRAPHICS = [
        '`' => '◆', 'a' => '▒', 'b' => '␉', 'c' => '␌', 'd' => '␍', 'e' => '␊', 'f' => '°', 'g' => '±',
        'h' => '␤', 'i' => '␋', 'j' => '┘', 'k' => '┐', 'l' => '┌', 'm' => '└', 'n' => '┼', 'o' => '⎺',
        'p' => '⎻', 'q' => '─', 'r' => '⎼', 's' => '⎽', 't' => '├', 'u' => '┤', 'v' => '┴', 'w' => '┬',
        'x' => '│', 'y' => '≤', 'z' => '≥', '{' => 'π', '|' => '≠', '}' => '£', '~' => '·', '_' => ' ',
    ];

    /** How the terminal identifies itself to XTVERSION. */
    public const NAME = 'phasync-tui(0.0.1)';

    /** Largest pending tail kept for an unterminated sequence before it is dropped. */
    private const MAX_PENDING = 1 << 20;

    public int $cols;
    public int $rows;
    /** @var list<Line> the rows of the active buffer */
    public array $lines = [];
    /** @var list<Line>|null the main buffer's rows while the alternate screen is active */
    public ?array $mainLines = null;
    /** @var array<int, Line> scrollback lines by absolute number, oldest first */
    public array $scrollback = [];
    /** Absolute number of the oldest line kept in scrollback. */
    public int $scrollbackStart = 0;
    /** Absolute number the next line pushed to scrollback gets; also lines ever scrolled out. */
    public int $scrollbackEnd = 0;
    public int $scrollbackLimit;

    public int $x = 0;
    public int $y = 0;
    /** Autowrap is pending: the cursor is in the last column and the next character wraps. */
    public bool $wrapPending = false;
    public Style $pen;
    /** Scroll region, inclusive. */
    public int $top = 0;
    public int $bottom;
    /** @var array<int, true> */
    public array $tabs = [];

    /** @var array<int, bool> DEC private modes by number */
    public array $modes = [];
    public bool $insertMode = false;
    public bool $newlineMode = false;
    public bool $keypadApplication = false;
    public string $cursorShape = 'block';
    public bool $cursorBlink = true;

    /** @var array{0:string,1:string,2:string,3:string} designated character sets, 'B' ASCII, '0' DEC graphics */
    public array $charsets = ['B', 'B', 'B', 'B'];
    public int $gl = 0;

    /** @var array<string, mixed>|null cursor saved with DECSC, per buffer */
    public ?array $savedMain = null;
    public ?array $savedAlt = null;

    public string $title = '';
    public string $iconName = '';
    public string $pending = '';
    public ?string $lastChar = null;
    public int $lastX = -1;
    public int $lastY = -1;

    /** @var array{0: list<int>, 1: list<int>} kitty keyboard flag stacks of the main and alternate screens */
    public array $keyboardStack = [[], []];
    /** xterm modifyOtherKeys level, 0-2. */
    public int $modifyOtherKeys = 0;
    /** When the current synchronized update (mode 2026) began. */
    public float $syncStarted = 0.0;
    /** @var list<Line>|null the rows as they were when the synchronized update began (what a host shows meanwhile) */
    public ?array $synced = null;
    /** @var array{int,int} default foreground and background, as reported to OSC 10/11 queries */
    public array $colors;

    public int $version = 0;
    /** @var array<int, int> version at which each row last changed */
    public array $rowVersion = [];

    /** @var \Closure(string $type, mixed $data): void */
    private \Closure $emit;

    /** @param \Closure(string $type, mixed $data): void $emit receives 'reply', 'title' and 'bell' */
    public function __construct(int $cols, int $rows, int $scrollbackLimit, \Closure $emit)
    {
        $this->cols = max(1, $cols);
        $this->rows = max(1, $rows);
        $this->scrollbackLimit = max(0, $scrollbackLimit);
        $this->emit = $emit;
        $this->pen = Style::plain();
        $this->colors = [Color::rgb(229, 229, 229), Color::rgb(0, 0, 0)];
        $this->reset();
    }

    /** The kitty keyboard protocol flags in effect for the active screen. */
    public function keyboardFlags(): int
    {
        $stack = $this->keyboardStack[$this->mainLines === null ? 0 : 1];
        return $stack === [] ? 0 : $stack[\count($stack) - 1];
    }

    /** Full reset (RIS): everything but the scrollback and the title. */
    public function reset(): void
    {
        $this->pen = Style::plain();
        $this->mainLines = null;
        $this->lines = [];
        for ($i = 0; $i < $this->rows; $i++) {
            $this->lines[] = new Line($this->cols, $this->pen);
        }
        $this->x = $this->y = 0;
        $this->wrapPending = false;
        $this->top = 0;
        $this->bottom = $this->rows - 1;
        $this->resetTabs(0);
        $mouse = isset($this->modes[9]) || isset($this->modes[1000]) || isset($this->modes[1002]) || isset($this->modes[1003]);
        $this->modes = [7 => true, 25 => true];
        $this->synced = null;
        if ($mouse) {
            ($this->emit)('mouse', null);
        }
        $this->insertMode = $this->newlineMode = $this->keypadApplication = false;
        $this->cursorShape = 'block';
        $this->cursorBlink = true;
        $this->charsets = ['B', 'B', 'B', 'B'];
        $this->gl = 0;
        $this->savedMain = $this->savedAlt = null;
        $this->keyboardStack = [[], []];
        $this->modifyOtherKeys = 0;
        $this->lastChar = null;
        $this->lastX = $this->lastY = -1;
        $this->touchAll();
    }

    private function resetTabs(int $from): void
    {
        if ($from === 0) {
            $this->tabs = [];
        }
        for ($i = $from - ($from % 8) + 8; $i < $this->cols; $i += 8) {
            if ($i >= $from) {
                $this->tabs[$i] = true;
            }
        }
    }

    // ------------------------------------------------------------------------------------------
    // Damage

    public function touch(int $y): void
    {
        $this->rowVersion[$y] = $this->version;
    }

    public function touchRange(int $from, int $to): void
    {
        for ($y = $from; $y <= $to; $y++) {
            $this->rowVersion[$y] = $this->version;
        }
    }

    public function touchAll(): void
    {
        $this->rowVersion = array_fill(0, $this->rows, $this->version);
    }

    // ------------------------------------------------------------------------------------------
    // Parser actions

    /** @var array<string, int> the width of each grapheme cluster printed so far */
    private static array $widths = [];
    /** @var array<string, list<string>> the grapheme clusters of short texts printed so far (a bar's runs of blocks, a border) */
    private static array $clusterMemo = [];
    /** @var array<string, array{int, int, int, Style}> SGR parameter strings that start with a reset: the colours and attributes they set (and that style without a hyperlink) */
    private static array $resetSgr = [];

    /** Text::graphemes() of $text, remembered for short texts (the same runs come again in every frame). */
    private static function clusters(string $text): array
    {
        if (\strlen($text) > 512) {
            return Text::graphemes($text);
        }
        if (!isset(self::$clusterMemo[$text]) && \count(self::$clusterMemo) >= 4096) {
            self::$clusterMemo = [];
        }
        return self::$clusterMemo[$text] ??= Text::graphemes($text);
    }

    /** Print a run of printable characters at the cursor. */
    public function print(string $text): void
    {
        if ($this->charsets[$this->gl] === '0') {
            $text = strtr($text, self::DEC_GRAPHICS);
        }
        if (!$this->insertMode && !preg_match('/[^\x20-\x7e]/', $text)) {
            $this->printCells(str_split($text));
            return;
        }
        // grapheme clusters by Unicode rules (\X): combining marks, ZWJ sequences, variation
        // selectors, flags and Hangul syllables each become one cell or two
        $clusters = self::clusters($text);
        if ($this->extendsLast($clusters[0])) {
            array_shift($clusters);
        }
        $widths = [];
        $simple = !$this->insertMode;
        if (\count(self::$widths) > 20000) {
            self::$widths = [];
        }
        foreach ($clusters as $g) {
            $w = self::$widths[$g] ??= Text::clusterWidth($g);
            $widths[] = $w;
            $simple = $simple && $w === 1;
        }
        if ($simple) {
            // (one cell each, as box drawing, blocks and most scripts: stored a row's share at a time)
            if ($clusters !== []) {
                $this->printCells($clusters);
            }
            return;
        }
        foreach ($clusters as $i => $g) {
            if ($widths[$i] === 0) {
                // a cluster of only combining characters with nothing to attach to
                continue;
            }
            $this->put($g, $widths[$i]);
        }
    }

    /**
     * print() of characters one cell wide each, outside insert mode: each row's share is stored
     * in one step, with the same result as put() per character.
     *
     * @param list<string> $cells
     */
    private function printCells(array $cells): void
    {
        $len = \count($cells);
        $cols = $this->cols;
        $autowrap = $this->modes[7] ?? false;
        $i = 0;
        while ($i < $len) {
            if ($this->wrapPending && $autowrap) {
                $this->lines[$this->y]->wrapped = true;
                $this->x = 0;
                $this->lineFeed();
            }
            $this->wrapPending = false;
            $x = $this->x;
            $n = min($len - $i, $cols - $x);
            $from = $i;
            $last = null;
            if (!$autowrap && $n < $len - $i) {
                // without autowrap everything that does not fit lands in the last column
                $n = max(1, $n);
                $last = $cells[$len - 1];
                $i = $len;
            } else {
                $i += $n;
            }
            $line = $this->lines[$this->y];
            if ($line->chars[$x] === '' && $x > 0) {
                $line->chars[$x - 1] = ' ';
            }
            $end = $x + $n;
            if ($end < $cols && $line->chars[$end] === '') {
                $line->chars[$end] = ' ';
            }
            // (cell by cell into the row as it is: O(the text), not O(the row) as a splice is)
            $chars = &$line->chars;
            $styles = &$line->styles;
            $pen = $this->pen;
            for ($k = 0; $k < $n; $k++) {
                $chars[$x + $k] = $cells[$from + $k];
                $styles[$x + $k] = $pen;
            }
            if ($last !== null) {
                $chars[$end - 1] = $last;
            }
            unset($chars, $styles);
            $this->touch($this->y);
            $this->lastChar = $line->chars[$end - 1];
            $this->lastX = $end - 1;
            $this->lastY = $this->y;
            if ($end >= $cols) {
                $this->x = $cols - 1;
                $this->wrapPending = $autowrap;
            } else {
                $this->x = $end;
            }
        }
    }

    /** Place one character of width 1 or 2 at the cursor and advance. */
    private function put(string $ch, int $w): void
    {
        $cols = $this->cols;
        $autowrap = $this->modes[7] ?? false;
        if ($this->wrapPending && $autowrap) {
            $this->lines[$this->y]->wrapped = true;
            $this->x = 0;
            $this->lineFeed();
        }
        $this->wrapPending = false;
        if ($w === 2 && $this->x === $cols - 1) {
            if ($cols < 2) {
                return;
            }
            if ($autowrap) {
                $line = $this->lines[$this->y];
                $line->erase($this->x, $this->x, $this->blank());
                $line->wrapped = true;
                $this->touch($this->y);
                $this->x = 0;
                $this->lineFeed();
            } else {
                $this->x = $cols - 2;
            }
        }
        $x = $this->x;
        $line = $this->lines[$this->y];
        if ($this->insertMode) {
            $this->insertCells($line, $x, $w);
        }
        $chars = &$line->chars;
        // repair a wide character we overwrite half of
        if ($chars[$x] === '' && $x > 0) {
            $chars[$x - 1] = ' ';
        }
        $end = $x + $w;
        if ($end < $cols && $chars[$end] === '') {
            $chars[$end] = ' ';
        }
        $chars[$x] = $ch;
        $line->styles[$x] = $this->pen;
        if ($w === 2) {
            $chars[$x + 1] = '';
            $line->styles[$x + 1] = $this->pen;
        }
        unset($chars);
        $this->touch($this->y);
        $this->lastChar = $ch;
        $this->lastX = $x;
        $this->lastY = $this->y;
        if ($end >= $cols) {
            $this->x = $cols - 1;
            $this->wrapPending = $autowrap;
        } else {
            $this->x = $end;
        }
    }

    /**
     * Continue the cluster in the last printed cell with $g if the cursor is still right after
     * it and Unicode joins them into one cluster (a combining mark, ZWJ and the next emoji, a
     * variation selector, the second regional indicator of a flag), even across writes. U+FE0F
     * widens a one-cell emoji to two cells when there is room.
     */
    private function extendsLast(string $g): bool
    {
        $x = $this->lastX;
        $y = $this->lastY;
        if ($x < 0 || $y !== $this->y || $y >= $this->rows || $x >= $this->cols) {
            return false;
        }
        $line = $this->lines[$y];
        $prev = $line->chars[$x];
        if ($prev === '' || $prev === ' ') {
            return false;
        }
        $width = ($line->chars[$x + 1] ?? null) === '' ? 2 : 1;
        $after = $x + $width;
        $adjacent = $this->wrapPending ? $after === $this->cols && $this->x === $this->cols - 1 : $this->x === $after;
        if (!$adjacent || \count(self::clusters($prev . $g)) !== 1) {
            return false;
        }
        $line->chars[$x] = $prev . $g;
        $this->lastChar = $prev . $g;
        $this->touch($y);
        if ($width === 1 && str_contains($g, "\u{FE0F}") && Width::isEmoji(mb_ord($prev, 'UTF-8')) && $x + 1 < $this->cols) {
            if (($line->chars[$x + 2] ?? null) === '') {
                $line->chars[$x + 2] = ' ';
            }
            $line->chars[$x + 1] = '';
            $line->styles[$x + 1] = $line->styles[$x];
            if ($x + 2 >= $this->cols) {
                $this->x = $this->cols - 1;
                $this->wrapPending = $this->modes[7] ?? false;
            } else {
                $this->x = $x + 2;
            }
        }
        return true;
    }

    /** Execute a C0 control. */
    public function execute(string $c): void
    {
        switch ($c) {
            case "\x07":
                ($this->emit)('bell', null);
                break;
            case "\x08":
                if ($this->x > 0) {
                    $this->x--;
                }
                $this->wrapPending = false;
                break;
            case "\x09":
                $this->tab(1);
                break;
            case "\x0a":
            case "\x0b":
            case "\x0c":
                $this->lineFeed();
                if ($this->newlineMode) {
                    $this->x = 0;
                }
                break;
            case "\x0d":
                $this->x = 0;
                $this->wrapPending = false;
                break;
            case "\x0e":
                $this->gl = 1;
                break;
            case "\x0f":
                $this->gl = 0;
                break;
        }
    }

    /** Execute C0 controls embedded in a sequence (DEL is ignored). @param list<string> $chars */
    private function executeAll(array $chars): void
    {
        foreach ($chars as $c) {
            if ($c !== "\x7f") {
                $this->execute($c);
            }
        }
    }

    /**
     * A CSI sequence. $pre holds controls embedded between ESC and '[', $body the collected
     * characters in order, $final the final character or '' when cancelled or aborted.
     *
     * @param list<string> $pre
     * @param list<string> $body
     */
    public function csi(array $pre, array $body, string $final): void
    {
        $this->executeAll($pre);
        $s = '';
        $valid = true;
        foreach ($body as $piece) {
            $o = \ord($piece[0]);
            if ($o < 0x20 || $o === 0x7f) {
                if (\strlen($piece) === 1) {
                    if ($o !== 0x7f) {
                        $this->execute($piece);
                    }
                    continue;
                }
            }
            if ($o >= 0x80) {
                $valid = false;
                continue;
            }
            $s .= $piece;
        }
        if ($final === '' || !$valid) {
            return;
        }
        if (!preg_match('/^([<=>?]?)([0-9:;]*)([\x20-\x2f]*)$/D', $s, $m)) {
            return;
        }
        $this->dispatchCsi($m[1], $m[2], $m[3], $final);
    }

    /**
     * A well-formed CSI sequence with nothing embedded, as it stands (`ESC [ 1 ; 2 H`): the same
     * as `csi()` with its pieces, without collecting them.
     */
    public function csiSequence(string $seq): void
    {
        $end = \strlen($seq) - 1;
        $final = $seq[$end];
        if (($final === 'm' || $final === 'H') && strspn($seq, '0123456789;', 2) === $end - 2) {
            // (what compositing and widgets send most: SGR and CUP, no marker, no intermediates,
            // no sub-parameters; the same as dispatchCsi() does with them)
            $raw = substr($seq, 2, $end - 2);
            if ($final === 'm') {
                $this->sgr($raw);
                return;
            }
            $p = explode(';', $raw, 3);
            $this->cursorPosition(max(1, min((int) $p[0], 65535)), max(1, min((int) ($p[1] ?? 1), 65535)));
            return;
        }
        $i = 2;
        $prefix = '';
        if (strspn($seq, '<=>?', 2, 1) === 1) {
            $prefix = $seq[2];
            $i = 3;
        }
        $n = strspn($seq, '0123456789:;', $i, $end - $i);
        $this->dispatchCsi($prefix, substr($seq, $i, $n), substr($seq, $i + $n, $end - $i - $n), $seq[$end]);
    }

    /**
     * Main parameter values; an omitted parameter is null. Sub-parameters after ':' are dropped
     * here (SGR reads the raw string).
     *
     * @return list<int|null>
     */
    private static function params(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $out = [];
        $colons = str_contains($raw, ':');
        foreach (explode(';', $raw) as $p) {
            if ($colons && ($colon = strpos($p, ':')) !== false) {
                $p = substr($p, 0, $colon);
            }
            $out[] = $p === '' ? null : min((int) $p, 65535);
        }
        return $out;
    }

    private function dispatchCsi(string $prefix, string $raw, string $inter, string $final): void
    {
        if ($final === 'm' && $prefix === '' && $inter === '') {
            $this->sgr($raw);       // (the most frequent sequence: SGR reads the raw string itself)
            return;
        }
        $p = self::params($raw);
        $n = max(1, $p[0] ?? 1);   // count parameter: 0 and omitted mean 1
        if ($inter !== '') {
            if ($inter === ' ' && $final === 'q' && $prefix === '') {
                $this->cursorStyle($p[0] ?? 0);
            } elseif ($inter === '!' && $final === 'p' && $prefix === '') {
                $this->softReset();
            } elseif ($inter === '$' && $final === 'p') {
                $this->reportMode($prefix, $p[0] ?? 0);
            }
            return;
        }
        if ($prefix === '?') {
            match ($final) {
                'h' => $this->setPrivateModes($p, true),
                'l' => $this->setPrivateModes($p, false),
                'J' => $this->eraseDisplay($p[0] ?? 0),
                'K' => $this->eraseLine($p[0] ?? 0),
                'n' => ($p[0] ?? 0) === 6 ? $this->reply(sprintf("\e[?%d;%dR", $this->reportedRow(), $this->x + 1)) : null,
                'u' => $this->reply(sprintf("\e[?%du", $this->keyboardFlags())),
                default => null,
            };
            return;
        }
        if ($prefix === '>') {
            if ($final === 'c' && ($p[0] ?? 0) === 0) {
                $this->reply("\e[>1;10;0c");
            } elseif ($final === 'q' && ($p[0] ?? 0) === 0) {
                $this->reply("\eP>|" . self::NAME . "\e\\");
            } elseif ($final === 'u') {
                // kitty keyboard protocol: push flags
                $stack = &$this->keyboardStack[$this->mainLines === null ? 0 : 1];
                $stack[] = ($p[0] ?? 0) & 31;
                if (\count($stack) > 16) {
                    array_shift($stack);
                }
            } elseif ($final === 'm' && ($p[0] ?? null) === 4) {
                $this->modifyOtherKeys = min(2, $p[1] ?? 0);
            } elseif ($final === 'n' && ($p[0] ?? null) === 4) {
                $this->modifyOtherKeys = 0;
            }
            return;
        }
        if ($prefix === '<' && $final === 'u') {
            // kitty keyboard protocol: pop entries
            $stack = &$this->keyboardStack[$this->mainLines === null ? 0 : 1];
            array_splice($stack, max(0, \count($stack) - max(1, $p[0] ?? 1)));
            return;
        }
        if ($prefix === '=' && $final === 'u') {
            // kitty keyboard protocol: set the current flags (1 replace, 2 add, 3 remove)
            $stack = &$this->keyboardStack[$this->mainLines === null ? 0 : 1];
            $flags = ($p[0] ?? 0) & 31;
            $current = $stack === [] ? 0 : $stack[\count($stack) - 1];
            $new = match ($p[1] ?? 1) {
                2 => $current | $flags,
                3 => $current & ~$flags,
                default => $flags,
            };
            if ($stack === []) {
                $stack[] = $new;
            } else {
                $stack[\count($stack) - 1] = $new;
            }
            return;
        }
        if ($prefix !== '') {
            return;
        }
        switch ($final) {
            case '@':
                $this->insertCells($this->lines[$this->y], $this->x, min($n, $this->cols));
                $this->touch($this->y);
                $this->wrapPending = false;
                break;
            case 'A':
                $this->cursorUp($n);
                break;
            case 'B':
            case 'e':
                $this->cursorDown($n);
                break;
            case 'C':
            case 'a':
                $this->moveTo($this->x + $n, $this->y);
                break;
            case 'D':
                $this->moveTo($this->x - $n, $this->y);
                break;
            case 'E':
                $this->cursorDown($n);
                $this->x = 0;
                break;
            case 'F':
                $this->cursorUp($n);
                $this->x = 0;
                break;
            case 'G':
            case '`':
                $this->moveTo($n - 1, $this->y);
                break;
            case 'H':
            case 'f':
                $this->cursorPosition(max(1, $p[0] ?? 1), max(1, $p[1] ?? 1));
                break;
            case 'I':
                $this->tab($n);
                break;
            case 'J':
                $this->eraseDisplay($p[0] ?? 0);
                break;
            case 'K':
                $this->eraseLine($p[0] ?? 0);
                break;
            case 'L':
                $this->insertLines($n);
                break;
            case 'M':
                $this->deleteLines($n);
                break;
            case 'P':
                $this->deleteCells($n);
                break;
            case 'S':
                $this->scrollUp($n, false);
                break;
            case 'T':
                if (\count($p) <= 1) {
                    $this->scrollDown($n);
                }
                break;
            case 'X':
                $this->lines[$this->y]->erase($this->x, $this->x + $n - 1, $this->blank());
                $this->touch($this->y);
                $this->wrapPending = false;
                break;
            case 'Z':
                $this->backTab($n);
                break;
            case 'b':
                if ($this->lastChar !== null) {
                    $count = min($n, $this->cols * $this->rows);
                    $ch = $this->lastChar;
                    $w = Text::clusterWidth($ch);
                    for ($i = 0; $i < $count; $i++) {
                        $this->put($ch, max(1, $w));
                    }
                }
                break;
            case 'c':
                if (($p[0] ?? 0) === 0) {
                    $this->reply("\e[?62;22c");
                }
                break;
            case 'd':
                $this->cursorPosition($n, $this->x + 1);
                break;
            case 'g':
                $mode = $p[0] ?? 0;
                if ($mode === 0) {
                    unset($this->tabs[$this->x]);
                } elseif ($mode === 3) {
                    $this->tabs = [];
                }
                break;
            case 'h':
            case 'l':
                foreach ($p as $mode) {
                    if ($mode === 4) {
                        $this->insertMode = $final === 'h';
                    } elseif ($mode === 20) {
                        $this->newlineMode = $final === 'h';
                    }
                }
                break;
            case 'm':
                $this->sgr($raw);
                break;
            case 'n':
                if (($p[0] ?? 0) === 5) {
                    $this->reply("\e[0n");
                } elseif (($p[0] ?? 0) === 6) {
                    $this->reply(sprintf("\e[%d;%dR", $this->reportedRow(), $this->x + 1));
                }
                break;
            case 'r':
                $top = max(1, $p[0] ?? 1);
                $bottom = $p[1] ?? $this->rows;
                if ($bottom === 0 || $bottom > $this->rows) {
                    $bottom = $this->rows;
                }
                if ($top < $bottom) {
                    $this->top = $top - 1;
                    $this->bottom = $bottom - 1;
                    $this->cursorPosition(1, 1);
                }
                break;
            case 's':
                if ($raw === '') {
                    $this->saveCursor();
                }
                break;
            case 't':
                if (($p[0] ?? 0) === 18) {
                    $this->reply(sprintf("\e[8;%d;%dt", $this->rows, $this->cols));
                }
                break;
            case 'u':
                if ($raw === '') {
                    $this->restoreCursor();
                }
                break;
        }
    }

    /**
     * An escape sequence other than CSI and the strings. $pre holds controls embedded before the
     * intermediates, $inter the intermediate runs (with embedded controls) in order, $final the
     * final character or '' when cancelled or aborted.
     *
     * @param list<string> $pre
     * @param list<mixed> $inter
     */
    public function esc(array $pre, array $inter, string $final): void
    {
        $this->executeAll($pre);
        $chars = '';
        array_walk_recursive($inter, function (string $piece) use (&$chars): void {
            if (\ord($piece[0]) < 0x20 || $piece === "\x7f") {
                if ($piece !== "\x7f") {
                    $this->execute($piece);
                }
            } else {
                $chars .= $piece;
            }
        });
        if ($final !== '') {
            $this->dispatchEsc($chars, $final);
        }
    }

    /**
     * A well-formed escape sequence with nothing embedded, as programs send them: ESC, the
     * intermediates, the final character (the grammar's fast path; the same as esc()).
     */
    public function escSequence(string $seq): void
    {
        $end = \strlen($seq) - 1;
        $this->dispatchEsc(substr($seq, 1, $end - 1), $seq[$end]);
    }

    private function dispatchEsc(string $inter, string $final): void
    {
        if ($inter === '') {
            switch ($final) {
                case '7':
                    $this->saveCursor();
                    break;
                case '8':
                    $this->restoreCursor();
                    break;
                case 'D':
                    $this->lineFeed();
                    break;
                case 'E':
                    $this->lineFeed();
                    $this->x = 0;
                    break;
                case 'H':
                    $this->tabs[$this->x] = true;
                    break;
                case 'M':
                    $this->reverseIndex();
                    break;
                case 'c':
                    $this->reset();
                    break;
                case '=':
                    $this->keypadApplication = true;
                    break;
                case '>':
                    $this->keypadApplication = false;
                    break;
                case 'Z':
                    $this->reply("\e[?62;22c");
                    break;
            }
            return;
        }
        $g = strpos('()*+', $inter[0]);
        if ($g !== false && \strlen($inter) === 1) {
            $this->charsets[$g] = $final === '0' ? '0' : 'B';
        } elseif ($inter === '#' && $final === '8') {
            $this->alignmentTest();
        }
    }

    /** An OSC string; $end is the terminator or '' when cancelled. @param list<string> $pre */
    public function osc(array $pre, string $text, string $end): void
    {
        $this->executeAll($pre);
        if ($end === '') {
            return;
        }
        $text = preg_replace('/[\x00-\x1f]/', '', $text);
        $semi = strpos($text, ';');
        $code = $semi === false ? $text : substr($text, 0, $semi);
        $value = $semi === false ? '' : substr($text, $semi + 1);
        if ($code === '0' || $code === '2') {
            $this->title = $value;
            ($this->emit)('title', $value);
        }
        if ($code === '0' || $code === '1') {
            $this->iconName = $value;
        }
        if ($code === '10' || $code === '11' || $code === '12') {
            // color queries, possibly chained: "10;?;?" asks for 10 and 11
            $term = $end === "\x07" ? "\x07" : "\e\\";
            $n = (int) $code;
            foreach (explode(';', $value) as $item) {
                if ($item === '?' && $n <= 12) {
                    [$r, $g, $b] = Color::toRgb($n === 11 ? $this->colors[1] : $this->colors[0]);
                    $this->reply(sprintf("\e]%d;rgb:%02x%02x/%02x%02x/%02x%02x%s", $n, $r, $r, $g, $g, $b, $b, $term));
                }
                $n++;
            }
        }
        if ($code === '8') {
            // a hyperlink: "8;params;URI" starts one (params "id=x:key=value"), "8;;" ends it
            $parts = explode(';', $value, 2);
            $id = preg_match('/(?:^|:)id=([^:]*)/', $parts[0], $m) ? $m[1] : '';
            $this->pen = $this->pen->withLink($parts[1] ?? '', $id);
        }
        if ($code === '52') {
            // clipboard write: "52;<selections>;<base64>"; reading the clipboard is not offered
            $parts = explode(';', $value, 2);
            $data = $parts[1] ?? '';
            if ($data !== '?') {
                $decoded = base64_decode($data, true);
                if ($decoded !== false) {
                    ($this->emit)('clipboard', $decoded);
                }
            }
        }
    }

    /** DCS, SOS, PM or APC: ignored apart from controls embedded before the introducer. */
    public function ignoredString(array $pre): void
    {
        $this->executeAll($pre);
    }

    /** A C1 control in the ground state (not one that introduces a sequence). */
    public function c1(string $c): void
    {
        match (mb_ord($c, 'UTF-8')) {
            0x84 => $this->lineFeed(),
            0x85 => $this->nextLine(),
            0x88 => $this->tabs[$this->x] = true,
            0x8D => $this->reverseIndex(),
            default => null,
        };
    }

    private function nextLine(): void
    {
        $this->lineFeed();
        $this->x = 0;
    }

    /** The chunk ended inside a sequence: keep the tail for the next write(). */
    public function incomplete(string $tail): void
    {
        $this->pending = \strlen($tail) > self::MAX_PENDING ? '' : $tail;
    }

    // ------------------------------------------------------------------------------------------
    // Operations

    /** The style erased cells get: the current background color (xterm's bce), nothing else. */
    public function blank(): Style
    {
        return $this->pen->bg === Color::DEFAULT ? Style::plain() : Style::of(Color::DEFAULT, $this->pen->bg);
    }

    private function reply(string $bytes): void
    {
        ($this->emit)('reply', $bytes);
    }

    private function reportedRow(): int
    {
        return ($this->modes[6] ?? false) ? $this->y - $this->top + 1 : $this->y + 1;
    }

    /** Move within the screen, clamped, clearing a pending wrap. */
    private function moveTo(int $x, int $y): void
    {
        $this->x = max(0, min($this->cols - 1, $x));
        $this->y = max(0, min($this->rows - 1, $y));
        $this->wrapPending = false;
    }

    /** CUP: 1-based row and column, relative to the scroll region in origin mode. */
    private function cursorPosition(int $row, int $col): void
    {
        if ($this->modes[6] ?? false) {
            $y = min($this->bottom, $this->top + $row - 1);
        } else {
            $y = $row - 1;
        }
        $this->moveTo($col - 1, $y);
    }

    private function cursorUp(int $n): void
    {
        $limit = $this->y >= $this->top ? $this->top : 0;
        $this->moveTo($this->x, max($limit, $this->y - $n));
    }

    private function cursorDown(int $n): void
    {
        $limit = $this->y <= $this->bottom ? $this->bottom : $this->rows - 1;
        $this->moveTo($this->x, min($limit, $this->y + $n));
    }

    private function tab(int $n): void
    {
        for ($i = 0; $i < $n && $this->x < $this->cols - 1; $i++) {
            $x = $this->x + 1;
            while ($x < $this->cols - 1 && !isset($this->tabs[$x])) {
                $x++;
            }
            $this->x = $x;
        }
        $this->wrapPending = false;
    }

    private function backTab(int $n): void
    {
        for ($i = 0; $i < $n && $this->x > 0; $i++) {
            $x = $this->x - 1;
            while ($x > 0 && !isset($this->tabs[$x])) {
                $x--;
            }
            $this->x = $x;
        }
        $this->wrapPending = false;
    }

    /** IND / LF: down one row, scrolling the region at its bottom margin. */
    public function lineFeed(): void
    {
        if ($this->y === $this->bottom) {
            $this->scrollUp(1, true);
        } elseif ($this->y < $this->rows - 1) {
            $this->y++;
        }
        $this->wrapPending = false;
    }

    private function reverseIndex(): void
    {
        if ($this->y === $this->top) {
            $this->scrollDown(1);
        } elseif ($this->y > 0) {
            $this->y--;
        }
        $this->wrapPending = false;
    }

    /**
     * Scroll the region up by $n. Lines leaving the top of a region that starts at the first row
     * go to scrollback when $toScrollback (line feeds on the main screen do; SU does not).
     */
    public function scrollUp(int $n, bool $toScrollback): void
    {
        $height = $this->bottom - $this->top + 1;
        $n = min($n, $height);
        $keep = $toScrollback && $this->top === 0 && $this->mainLines === null && $this->scrollbackLimit > 0;
        $removed = array_splice($this->lines, $this->top, $n);
        $fill = [];
        $blank = $this->blank();
        for ($i = 0; $i < $n; $i++) {
            $fill[] = new Line($this->cols, $blank);
        }
        array_splice($this->lines, $this->bottom - $n + 1, 0, $fill);
        if ($keep) {
            foreach ($removed as $line) {
                $this->scrollback[$this->scrollbackEnd++] = $line;
            }
            while ($this->scrollbackEnd - $this->scrollbackStart > $this->scrollbackLimit) {
                unset($this->scrollback[$this->scrollbackStart++]);
            }
        }
        $this->touchRange($this->top, $this->bottom);
    }

    private function scrollDown(int $n): void
    {
        $height = $this->bottom - $this->top + 1;
        $n = min($n, $height);
        array_splice($this->lines, $this->bottom - $n + 1, $n);
        $fill = [];
        $blank = $this->blank();
        for ($i = 0; $i < $n; $i++) {
            $fill[] = new Line($this->cols, $blank);
        }
        array_splice($this->lines, $this->top, 0, $fill);
        $this->touchRange($this->top, $this->bottom);
    }

    private function insertLines(int $n): void
    {
        if ($this->y < $this->top || $this->y > $this->bottom) {
            return;
        }
        $savedTop = $this->top;
        $this->top = $this->y;
        $this->scrollDown($n);
        $this->top = $savedTop;
        $this->x = 0;
        $this->wrapPending = false;
    }

    private function deleteLines(int $n): void
    {
        if ($this->y < $this->top || $this->y > $this->bottom) {
            return;
        }
        $savedTop = $this->top;
        $this->top = $this->y;
        $this->scrollUp($n, false);
        $this->top = $savedTop;
        $this->x = 0;
        $this->wrapPending = false;
    }

    /** Shift cells right from $x by $n, losing what falls off the right edge. */
    private function insertCells(Line $line, int $x, int $n): void
    {
        $cols = $this->cols;
        $n = min($n, $cols - $x);
        if ($n <= 0) {
            return;
        }
        if ($line->chars[$x] === '' && $x > 0) {
            $line->chars[$x - 1] = ' ';
            $line->chars[$x] = ' ';
        }
        $blank = $this->blank();
        array_splice($line->chars, $x, 0, array_fill(0, $n, ' '));
        array_splice($line->styles, $x, 0, array_fill(0, $n, $blank));
        $cutWide = $line->chars[$cols] === '';
        array_splice($line->chars, $cols);
        array_splice($line->styles, $cols);
        if ($cutWide) {
            $line->chars[$cols - 1] = ' ';
        }
    }

    private function deleteCells(int $n): void
    {
        $line = $this->lines[$this->y];
        $x = $this->x;
        $cols = $this->cols;
        $n = min($n, $cols - $x);
        if ($line->chars[$x] === '' && $x > 0) {
            $line->chars[$x - 1] = ' ';
        }
        array_splice($line->chars, $x, $n);
        array_splice($line->styles, $x, $n);
        if (isset($line->chars[$x]) && $line->chars[$x] === '') {
            $line->chars[$x] = ' ';
        }
        $blank = $this->blank();
        for ($i = 0; $i < $n; $i++) {
            $line->chars[] = ' ';
            $line->styles[] = $blank;
        }
        $this->touch($this->y);
        $this->wrapPending = false;
    }

    private function eraseDisplay(int $mode): void
    {
        $blank = $this->blank();
        switch ($mode) {
            case 0:
                $this->lines[$this->y]->erase($this->x, $this->cols - 1, $blank);
                $this->lines[$this->y]->wrapped = false;
                for ($y = $this->y + 1; $y < $this->rows; $y++) {
                    $this->lines[$y] = new Line($this->cols, $blank);
                }
                $this->touchRange($this->y, $this->rows - 1);
                break;
            case 1:
                for ($y = 0; $y < $this->y; $y++) {
                    $this->lines[$y] = new Line($this->cols, $blank);
                }
                $this->lines[$this->y]->erase(0, $this->x, $blank);
                $this->touchRange(0, $this->y);
                break;
            case 2:
                for ($y = 0; $y < $this->rows; $y++) {
                    $this->lines[$y] = new Line($this->cols, $blank);
                }
                $this->touchAll();
                break;
            case 3:
                $this->scrollback = [];
                $this->scrollbackStart = $this->scrollbackEnd;
                break;
        }
    }

    private function eraseLine(int $mode): void
    {
        $line = $this->lines[$this->y];
        $blank = $this->blank();
        match ($mode) {
            0 => $line->erase($this->x, $this->cols - 1, $blank),
            1 => $line->erase(0, $this->x, $blank),
            2 => $line->erase(0, $this->cols - 1, $blank),
            default => null,
        };
        if ($mode === 0 || $mode === 2) {
            $line->wrapped = false;
        }
        $this->touch($this->y);
    }

    private function alignmentTest(): void
    {
        $style = Style::plain();
        for ($y = 0; $y < $this->rows; $y++) {
            $line = new Line($this->cols, $style);
            $line->chars = array_fill(0, $this->cols, 'E');
            $this->lines[$y] = $line;
        }
        $this->top = 0;
        $this->bottom = $this->rows - 1;
        $this->modes[6] = false;
        $this->moveTo(0, 0);
        $this->touchAll();
    }

    /** @param list<int|null> $modes */
    private function setPrivateModes(array $modes, bool $on): void
    {
        foreach ($modes as $mode) {
            if ($mode === null) {
                continue;
            }
            switch ($mode) {
                case 6:
                    $this->modes[6] = $on;
                    $this->cursorPosition(1, 1);
                    break;
                case 47:
                case 1047:
                    $this->switchScreen($on);
                    break;
                case 2026:
                    // a synchronized update begins (a host shows it when it ends); BSU twice keeps the start
                    if ($on && !($this->modes[2026] ?? false)) {
                        $this->syncStarted = microtime(true);
                        $this->synced = array_map(fn(Line $line) => clone $line, $this->lines);
                    } elseif (!$on) {
                        $this->synced = null;
                    }
                    $this->modes[2026] = $on;
                    break;
                case 1048:
                    $on ? $this->saveCursor() : $this->restoreCursor();
                    break;
                case 1049:
                    if ($on) {
                        $this->saveCursor();
                        $this->switchScreen(true);
                        $this->eraseDisplay(2);
                    } else {
                        $this->switchScreen(false);
                        $this->restoreCursor();
                    }
                    break;
                case 1000:
                case 1002:
                case 1003:
                case 9:
                    // the mouse tracking modes replace each other
                    unset($this->modes[9], $this->modes[1000], $this->modes[1002], $this->modes[1003]);
                    $this->modes[$mode] = $on;
                    ($this->emit)('mouse', null);
                    break;
                default:
                    $this->modes[$mode] = $on;
            }
        }
    }

    /** Switch buffers; the alternate screen starts blank every time it is entered. */
    private function switchScreen(bool $alt): void
    {
        if ($alt && $this->mainLines === null) {
            $this->mainLines = $this->lines;
            $this->lines = [];
            for ($i = 0; $i < $this->rows; $i++) {
                $this->lines[] = new Line($this->cols, Style::plain());
            }
            $this->modes[1049] = true;
        } elseif (!$alt && $this->mainLines !== null) {
            $this->lines = $this->mainLines;
            $this->mainLines = null;
            $this->modes[1049] = false;
        }
        $this->wrapPending = false;
        $this->touchAll();
    }

    private function saveCursor(): void
    {
        $state = [
            'x' => $this->x, 'y' => $this->y, 'pen' => $this->pen, 'wrap' => $this->wrapPending,
            'origin' => $this->modes[6] ?? false, 'charsets' => $this->charsets, 'gl' => $this->gl,
        ];
        if ($this->mainLines === null) {
            $this->savedMain = $state;
        } else {
            $this->savedAlt = $state;
        }
    }

    private function restoreCursor(): void
    {
        $state = $this->mainLines === null ? $this->savedMain : $this->savedAlt;
        if ($state === null) {
            $this->pen = Style::plain();
            $this->modes[6] = false;
            $this->charsets = ['B', 'B', 'B', 'B'];
            $this->gl = 0;
            $this->moveTo(0, 0);
            return;
        }
        $this->moveTo($state['x'], $state['y']);
        $this->pen = $state['pen'];
        $this->wrapPending = $state['wrap'] && $this->x === $this->cols - 1;
        $this->modes[6] = $state['origin'];
        $this->charsets = $state['charsets'];
        $this->gl = $state['gl'];
    }

    private function softReset(): void
    {
        $this->modes[25] = true;
        $this->modes[6] = false;
        $this->modes[7] = true;
        $this->modes[1] = false;
        $this->insertMode = false;
        $this->keypadApplication = false;
        $this->pen = Style::plain();
        $this->top = 0;
        $this->bottom = $this->rows - 1;
        $this->charsets = ['B', 'B', 'B', 'B'];
        $this->gl = 0;
        $this->savedMain = $this->savedAlt = null;
        $this->wrapPending = false;
    }

    private function cursorStyle(int $n): void
    {
        $this->cursorShape = match ($n) {
            3, 4 => 'underline',
            5, 6 => 'bar',
            default => 'block',
        };
        $this->cursorBlink = $n === 0 || $n % 2 === 1;
    }

    private function reportMode(string $prefix, int $mode): void
    {
        if ($prefix === '?') {
            $known = [1, 6, 7, 9, 12, 25, 47, 66, 1000, 1002, 1003, 1004, 1006, 1047, 1049, 2004, 2026];
            $state = \in_array($mode, $known, true) ? (($this->modes[$mode] ?? false) ? 1 : 2) : 0;
            $this->reply(sprintf("\e[?%d;%d\$y", $mode, $state));
        } elseif ($prefix === '') {
            $state = match ($mode) {
                4 => $this->insertMode ? 1 : 2,
                20 => $this->newlineMode ? 1 : 2,
                default => 0,
            };
            $this->reply(sprintf("\e[%d;%d\$y", $mode, $state));
        }
    }

    /** SGR: the raw parameter string, with ';' between parameters and ':' between sub-parameters. */
    private function sgr(string $raw): void
    {
        // a sequence that starts with a reset (all a compositor sends: "0;1;38;2;r;g;b") sets
        // the same colours and attributes whatever the pen was: worked out once per sequence
        $reset = $raw === '' || $raw === '0' || str_starts_with($raw, '0;');
        if ($reset && isset(self::$resetSgr[$raw])) {
            [$fg, $bg, $attrs, $style] = self::$resetSgr[$raw];
            $this->pen = $this->pen->link === '' ? $style : Style::of($fg, $bg, $attrs, $this->pen->link, $this->pen->linkId);
            return;
        }
        $fg = $this->pen->fg;
        $bg = $this->pen->bg;
        $attrs = $this->pen->attrs;
        $parts = $raw === '' ? [''] : explode(';', $raw);
        $count = \count($parts);
        for ($i = 0; $i < $count; $i++) {
            $part = $parts[$i];
            $sub = null;
            if (str_contains($part, ':')) {
                $sub = explode(':', $part);
                $code = (int) $sub[0];
            } else {
                $code = $part === '' ? 0 : (int) $part;
            }
            switch (true) {
                case $code === 0:
                    $fg = $bg = Color::DEFAULT;
                    $attrs = 0;
                    break;
                case $code === 1:
                    $attrs |= Style::BOLD;
                    break;
                case $code === 2:
                    $attrs |= Style::DIM;
                    break;
                case $code === 3:
                    $attrs |= Style::ITALIC;
                    break;
                case $code === 4:
                    if ($sub !== null && ($sub[1] ?? '') === '0') {
                        $attrs &= ~Style::UNDERLINE;
                    } else {
                        $attrs |= Style::UNDERLINE;
                    }
                    break;
                case $code === 5 || $code === 6:
                    $attrs |= Style::BLINK;
                    break;
                case $code === 7:
                    $attrs |= Style::INVERSE;
                    break;
                case $code === 8:
                    $attrs |= Style::HIDDEN;
                    break;
                case $code === 9:
                    $attrs |= Style::STRIKE;
                    break;
                case $code === 21:
                    $attrs |= Style::UNDERLINE;
                    break;
                case $code === 22:
                    $attrs &= ~(Style::BOLD | Style::DIM);
                    break;
                case $code === 23:
                    $attrs &= ~Style::ITALIC;
                    break;
                case $code === 24:
                    $attrs &= ~Style::UNDERLINE;
                    break;
                case $code === 25:
                    $attrs &= ~Style::BLINK;
                    break;
                case $code === 27:
                    $attrs &= ~Style::INVERSE;
                    break;
                case $code === 28:
                    $attrs &= ~Style::HIDDEN;
                    break;
                case $code === 29:
                    $attrs &= ~Style::STRIKE;
                    break;
                case $code >= 30 && $code <= 37:
                    $fg = $code - 30;
                    break;
                case $code === 39:
                    $fg = Color::DEFAULT;
                    break;
                case $code >= 40 && $code <= 47:
                    $bg = $code - 40;
                    break;
                case $code === 49:
                    $bg = Color::DEFAULT;
                    break;
                case $code >= 90 && $code <= 97:
                    $fg = $code - 90 + 8;
                    break;
                case $code >= 100 && $code <= 107:
                    $bg = $code - 100 + 8;
                    break;
                case $code === 38 || $code === 48 || $code === 58:
                    if ($sub !== null) {
                        $color = self::extendedColor($sub, 1);
                    } else {
                        $rest = \array_slice($parts, $i + 1);
                        $color = self::extendedColor($rest, 0, $used);
                        $i += $used;
                    }
                    if ($color !== null && $code === 38) {
                        $fg = $color;
                    } elseif ($color !== null && $code === 48) {
                        $bg = $color;
                    }
                    break;
            }
        }
        $this->pen = Style::of($fg, $bg, $attrs, $this->pen->link, $this->pen->linkId);     // (SGR leaves the hyperlink)
        if ($reset) {
            if (\count(self::$resetSgr) > 4096) {
                self::$resetSgr = [];
            }
            self::$resetSgr[$raw] = [$fg, $bg, $attrs, Style::of($fg, $bg, $attrs)];
        }
    }

    /**
     * Decode "5;n" or "2;r;g;b" (or with ':' the forms "5:n", "2::r:g:b" and "2:r:g:b") starting
     * at $at in $p. $used receives how many elements were consumed.
     *
     * @param list<string> $p
     */
    private static function extendedColor(array $p, int $at, ?int &$used = 0): ?int
    {
        $kind = $p[$at] ?? '';
        if ($kind === '5') {
            $used = 2;
            $n = $p[$at + 1] ?? '';
            return $n === '' || (int) $n > 255 ? null : (int) $n;
        }
        if ($kind === '2') {
            if ($at === 1 && \count($p) >= 6) {
                // colon form with a color space id: 38:2:<id>:r:g:b
                $rgb = \array_slice($p, 3, 3);
            } else {
                $rgb = \array_slice($p, $at + 1, 3);
            }
            $used = 4;
            if (\count($rgb) < 3) {
                return null;
            }
            return Color::rgb((int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
        }
        $used = 1;
        return null;
    }

    // ------------------------------------------------------------------------------------------
    // Resize

    public function resize(int $cols, int $rows): void
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);
        if ($cols === $this->cols && $rows === $this->rows) {
            return;
        }
        $blank = Style::plain();
        // height first, on the active buffer: shrink from the bottom while the rows below the
        // cursor are blank, then move rows off the top (into scrollback on the main screen)
        $this->lines = $this->resizeBuffer($this->lines, $cols, $rows, $this->mainLines === null, true);
        if ($this->mainLines !== null) {
            $this->mainLines = $this->resizeBuffer($this->mainLines, $cols, $rows, true, false);
        }
        foreach ($this->scrollback as $line) {
            if (\count($line->chars) > $cols) {
                $line->resize($cols, $blank);
            }
        }
        $oldCols = $this->cols;
        $this->cols = $cols;
        $this->rows = $rows;
        $this->top = 0;
        $this->bottom = $rows - 1;
        if ($cols > $oldCols) {
            $this->resetTabs($oldCols);
        } else {
            foreach (array_keys($this->tabs) as $t) {
                if ($t >= $cols) {
                    unset($this->tabs[$t]);
                }
            }
        }
        $this->x = min($this->x, $cols - 1);
        $this->y = max(0, min($this->y, $rows - 1));
        $this->wrapPending = false;
        foreach (['savedMain', 'savedAlt'] as $k) {
            if ($this->$k !== null) {
                $this->$k['x'] = min($this->$k['x'], $cols - 1);
                $this->$k['y'] = min($this->$k['y'], $rows - 1);
            }
        }
        $this->lastX = $this->lastY = -1;
        $this->touchAll();
    }

    /**
     * @param list<Line> $lines
     * @return list<Line>
     */
    private function resizeBuffer(array $lines, int $cols, int $rows, bool $toScrollback, bool $active): array
    {
        $blank = Style::plain();
        foreach ($lines as $line) {
            $line->resize($cols, $blank);
        }
        $count = \count($lines);
        while ($count > $rows) {
            $last = $lines[$count - 1];
            $cursorBelow = $active && $this->y >= $count - 1;
            if (!$cursorBelow && trim(implode('', $last->chars)) === '') {
                array_pop($lines);
                $count--;
                continue;
            }
            $line = array_shift($lines);
            $count--;
            if ($toScrollback && $this->scrollbackLimit > 0) {
                $this->scrollback[$this->scrollbackEnd++] = $line;
                if ($this->scrollbackEnd - $this->scrollbackStart > $this->scrollbackLimit) {
                    unset($this->scrollback[$this->scrollbackStart++]);
                }
            }
            if ($active) {
                $this->y--;
            }
        }
        while ($count < $rows) {
            $lines[] = new Line($cols, $blank);
            $count++;
        }
        return $lines;
    }
}
