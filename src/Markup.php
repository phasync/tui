<?php

namespace phasync\Tui;

use phasync\Tui\Element\Span;

/**
 * Markup in text, as symfony/console spells it: `Text`, `Span`, `Box` titles, `Button` labels,
 * `Table` and `Select` cells, `Help`, `Log` lines and the text of `Markdown` take it.
 *
 *     new Text('<b>3</b> files, <fg=red>1 failed</>, see <span class="text-sky-400 underline">the log</span>')
 *
 * Tags:
 *
 * - `<b>` (or `<bold>`), `<i>` (`<italic>`), `<u>` (`<underline>`), `<s>` (`<strike>`), `<dim>`;
 * - `<fg=red>`, `<bg=blue>`, `<fg=#ff5500>`, `<fg=sky-400>` (any colour `Color::parse()` reads),
 *   `<options=bold,underscore>` (bold, underscore, underline, italic, dim, reverse, strike),
 *   `<href=https://example.com>` (a hyperlink), several in one tag with `;`
 *   (`<fg=black;bg=yellow;options=bold>`);
 * - `<span class="text-sky-400 bold">`: utility classes, and the classes of your stylesheet's
 *   rules for `Span` (`'Span.key' => [...]`);
 * - symfony/console's own: `<info>` (green), `<comment>` (yellow), `<question>` (black on cyan),
 *   `<error>` (white on red).
 *
 * A tag is closed by `</>` or by its own name (`</b>`, `</fg>`, `</span>`), which also closes
 * the tags opened inside it. Tags nest: the inner ones inherit from the outer ones, so the
 * attributes add up and the innermost colour wins. `\<` is a literal `<`; `Markup::escape()`
 * makes a string from outside (a file name, a user's input) safe to put in markup, and
 * `Text::raw()` shows a text with no markup at all. Malformed markup is shown as written, never
 * an error: a tag that is not one of these (`<foo>`, `<fg=nocolour>`), a tag never closed and
 * a closing tag with nothing open stay text.
 *
 * Markup becomes escape sequences (SGR) before anything measures the text: the width of a
 * marked-up string is that of its visible text, so wrapping, truncating and sizing see no
 * tags, and it goes onto a surface through `write()` like every other output. A string is
 * parsed once (they are kept by string).
 */
final class Markup
{
    private const TAGS = [
        'b' => ['bold' => true], 'bold' => ['bold' => true], 'i' => ['italic' => true], 'italic' => ['italic' => true],
        'u' => ['underline' => true], 'underline' => ['underline' => true], 's' => ['strike' => true], 'strike' => ['strike' => true],
        'dim' => ['dim' => true],
        'info' => ['color' => 'green'], 'comment' => ['color' => 'yellow'],
        'question' => ['color' => 'black', 'background' => 'cyan'], 'error' => ['color' => 'white', 'background' => 'red'],
    ];
    private const OPTIONS = ['bold' => 'bold', 'underscore' => 'underline', 'underline' => 'underline', 'italic' => 'italic',
        'dim' => 'dim', 'reverse' => 'inverse', 'inverse' => 'inverse', 'strike' => 'strike'];
    private const KEEP = 4096;

    /** @var array<string, list<array>> parsed strings: tokens by text */
    private static array $parsed = [];
    /** @var array<string, string> toAnsi() answers by sheet, base and text */
    private static array $ansi = [];
    /** @var array<int, ComputedStyle> the style to inherit from, for a base Style */
    private static array $bases = [];

    /**
     * $text with its markup turned into escape sequences, for text that starts in the style
     * $base (what the text around it is drawn in; the plain style by default): each tag sets the
     * style it names over the one around it, and closing it sets that one again. The text ends
     * in $base. `<span class>` resolves its classes with $sheet (the default theme when null).
     * Text without markup comes back as it is.
     */
    public static function toAnsi(string $text, Style|ComputedStyle|null $base = null, ?Stylesheet $sheet = null): string
    {
        if (!str_contains($text, '<')) {
            return $text;
        }
        $base ??= Style::plain();
        $style = $base instanceof ComputedStyle ? $base->text : $base;
        $sheet ??= Stylesheet::default();
        $key = spl_object_id($sheet) . ':' . spl_object_id($style) . ':' . $text;
        if (isset(self::$ansi[$key])) {
            return self::$ansi[$key];
        }
        if (\count(self::$ansi) >= self::KEEP) {
            self::$ansi = [];
        }
        return self::$ansi[$key] = self::render(self::parse($text), $style, $sheet);
    }

    /** The visible text of $text: its tags taken out and `\<` made `<` (escape sequences in it stay). */
    public static function plain(string $text): string
    {
        if (!str_contains($text, '<')) {
            return $text;
        }
        $out = '';
        foreach (self::parse($text) as $token) {
            if ($token[0] === 't') {
                $out .= $token[1];
            }
        }
        return $out;
    }

    /** $text with every `<` escaped (`\<`), so that markup shows it as it is: for strings from outside. */
    public static function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }

    // ------------------------------------------------------------------------------------------

    /**
     * $text as tokens: ['t', text], ['o', properties, name, link] for an opening tag and ['c', n]
     * for a closing one that closes the n innermost open tags; what is no tag (or a tag without
     * its match) is text.
     *
     * @return list<array>
     */
    private static function parse(string $text): array
    {
        if (isset(self::$parsed[$text])) {
            return self::$parsed[$text];
        }
        if (\count(self::$parsed) >= self::KEEP) {
            self::$parsed = [];
        }
        // pass 1: the tags and the text between them, character by character
        $tokens = [];
        $buf = '';
        $len = \strlen($text);
        for ($i = 0; $i < $len;) {
            $c = $text[$i];
            if ($c === '\\' && ($text[$i + 1] ?? '') === '<') {
                $buf .= '<';
                $i += 2;
                continue;
            }
            if ($c === '<' && ($tag = self::tag($text, $i)) !== null) {
                if ($buf !== '') {
                    $tokens[] = ['t', $buf];
                    $buf = '';
                }
                $tokens[] = $tag;
                $i += \strlen($tag[1]);
                continue;
            }
            $n = strcspn($text, '\\<', $i + 1) + 1;
            $buf .= substr($text, $i, $n);
            $i += $n;
        }
        if ($buf !== '') {
            $tokens[] = ['t', $buf];
        }
        // pass 2: match closing tags with opening ones; the unmatched ones are text as written
        $open = [];
        foreach ($tokens as $k => $token) {
            if ($token[0] === 'o') {
                $open[] = $k;
            } elseif ($token[0] === 'c') {
                $name = $token[2];
                $at = null;
                for ($j = \count($open) - 1; $j >= 0; $j--) {
                    if ($name === null || $tokens[$open[$j]][3] === $name) {
                        $at = $j;
                        break;
                    }
                }
                if ($at === null) {
                    $tokens[$k] = ['t', $token[1]];
                    continue;
                }
                $tokens[$k] = ['c', \count($open) - $at];
                array_splice($open, $at);
            }
        }
        foreach ($open as $k) {
            $tokens[$k] = ['t', $tokens[$k][1]];
        }
        // (text tokens next to each other joined, tags as [kind, ...])
        $out = [];
        foreach ($tokens as $token) {
            if ($token[0] === 't' && $out !== [] && $out[\count($out) - 1][0] === 't') {
                $out[\count($out) - 1][1] .= $token[1];
            } elseif ($token[0] === 'o') {
                $out[] = ['o', $token[2], $token[4]];
            } else {
                $out[] = $token;
            }
        }
        return self::$parsed[$text] = $out;
    }

    /**
     * The tag at $i: ['o', written, properties, name, link] or ['c', written, name|null];
     * null when what is there is no tag this markup knows.
     */
    private static function tag(string $text, int $i): ?array
    {
        if (!preg_match('/<(\/?)([^<>]*)>/A', $text, $m, 0, $i)) {
            return null;
        }
        [$written, $slash, $inside] = $m;
        if ($slash === '/') {
            if ($inside === '') {
                return ['c', $written, null];
            }
            $name = strtolower(trim($inside));
            return isset(self::TAGS[$name]) || \in_array($name, ['span', 'fg', 'bg', 'options', 'href'], true) ? ['c', $written, $name] : null;
        }
        $name = strtolower($inside);
        if (isset(self::TAGS[$name])) {
            return ['o', $written, ['style' => self::TAGS[$name]], $name, null];
        }
        if (preg_match('/^span\s+class\s*=\s*(?:"([^"]*)"|\'([^\']*)\')\s*$/i', $inside, $s)) {
            return ['o', $written, ['class' => ($s[1] ?? '') !== '' ? $s[1] : ($s[2] ?? '')], 'span', null];
        }
        // fg=...;bg=...;options=...;href=...
        $style = [];
        $link = null;
        $first = null;
        foreach (explode(';', $inside) as $pair) {
            if (!preg_match('/^\s*(fg|bg|options|href)\s*=\s*(.+?)\s*$/i', $pair, $p)) {
                return null;
            }
            $key = strtolower($p[1]);
            $first ??= $key;
            $value = $p[2];
            switch ($key) {
                case 'fg':
                case 'bg':
                    try {
                        Color::parse($value);
                    } catch (\InvalidArgumentException) {
                        return null;
                    }
                    $style[$key === 'fg' ? 'color' : 'background'] = $value;
                    break;
                case 'options':
                    foreach (explode(',', strtolower($value)) as $option) {
                        $option = trim($option);
                        if (!isset(self::OPTIONS[$option])) {
                            return null;
                        }
                        $style[self::OPTIONS[$option]] = true;
                    }
                    break;
                default:
                    $link = $value;
            }
        }
        return ['o', $written, ['style' => $style], $first, $link];
    }

    /** @param list<array> $tokens */
    private static function render(array $tokens, Style $base, Stylesheet $sheet): string
    {
        $outer = self::$bases[spl_object_id($base)] ??= new ComputedStyle($base);
        $stack = [[$outer, '']];
        $pen = $base;
        $link = '';
        $out = '';
        foreach ($tokens as $token) {
            switch ($token[0]) {
                case 't':
                    [$style, $uri] = $stack[\count($stack) - 1];
                    if ($style->text !== $pen) {
                        $out .= Ansi::style($style->text);
                        $pen = $style->text;
                    }
                    if ($uri !== $link) {
                        $out .= Ansi::link($uri);
                        $link = $uri;
                    }
                    $out .= $token[1];
                    break;
                case 'o':
                    [$around, $uri] = $stack[\count($stack) - 1];
                    $span = new Span('', class: $token[1]['class'] ?? null, style: $token[1]['style'] ?? null);
                    $stack[] = [$sheet->compute($span, [], $around), $token[2] ?? $uri];
                    break;
                default:
                    array_splice($stack, -$token[1]);
            }
        }
        if ($pen !== $base) {
            $out .= Ansi::style($base);
        }
        if ($link !== '') {
            $out .= Ansi::link();
        }
        return $out;
    }
}
