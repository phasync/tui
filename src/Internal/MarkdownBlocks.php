<?php

namespace phasync\Tui\Internal;

/**
 * @internal The block-level CommonMark parser behind `Component\Markdown`: lines in, a list of
 * blocks out. It covers headings (ATX and setext), paragraphs, fenced and indented code, block
 * quotes, nested bullet and numbered lists, GFM tables and thematic breaks; it never fails, and
 * takes unterminated constructs (an open fence, a quote with no end) as running to the end of the
 * text, which is what a streamed tail needs. Every block carries `src`, the lines it was made
 * from, so that a renderer can cache what it drew for a block that did not change.
 *
 * Block shapes (`t` is the type):
 *   heading {level, text}, para {text}, code {info, lines}, rule, quote {children},
 *   list {ordered, start, loose, items: list<list<block>>}, table {head, align, rows}.
 */
final class MarkdownBlocks
{
    private const FENCE = '/^ {0,3}(`{3,}|~{3,})[ \t]*([^`]*?)[ \t]*$/';
    private const HEADING = '/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/';
    private const RULE = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';
    private const ITEM = '/^( {0,3})([-+*]|\d{1,9}[.)])( +|$)(.*)$/';
    private const SETEXT = '/^ {0,3}(=+|-+)[ \t]*$/';
    private const DELIMITER = '/^[ \t]*\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/';

    /**
     * @return list<array<string, mixed>>
     */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // escape sequences and other control characters in the text never reach the terminal
        $text = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', mb_scrub($text, 'UTF-8'));
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            while (preg_match('/^( *)\t/', $line)) {
                $line = preg_replace_callback('/^( *)\t/', fn($m) => $m[1] . str_repeat(' ', 4 - \strlen($m[1]) % 4), $line);
            }
        }
        return self::blocks($lines);
    }

    /**
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $lines): array
    {
        $out = [];
        $n = \count($lines);
        for ($i = 0; $i < $n;) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            $start = $i;
            if (preg_match(self::FENCE, $line, $m)) {
                $fence = $m[1];
                $body = [];
                for ($i++; $i < $n && !preg_match('/^ {0,3}' . $fence[0] . '{' . \strlen($fence) . ',}[ \t]*$/', $lines[$i]); $i++) {
                    $body[] = preg_replace('/^ {0,' . (\strlen($line) - \strlen(ltrim($line))) . '}/', '', $lines[$i]);
                }
                $i++;       // the closing fence
                $block = ['t' => 'code', 'info' => $m[2], 'lines' => $body];
            } elseif (preg_match(self::HEADING, $line, $m)) {
                $i++;
                $block = ['t' => 'heading', 'level' => \strlen($m[1]), 'text' => trim($m[2] ?? '')];
            } elseif (preg_match(self::RULE, $line)) {
                $i++;
                $block = ['t' => 'rule'];
            } elseif (preg_match('/^ {0,3}>/', $line)) {
                $inner = [];
                while ($i < $n && trim($lines[$i]) !== '') {
                    if (preg_match('/^ {0,3}> ?(.*)$/', $lines[$i], $m)) {
                        $inner[] = $m[1];
                    } elseif ($inner !== [] && !self::startsBlock($lines[$i]) && trim(end($inner)) !== '') {
                        $inner[] = $lines[$i];      // lazy continuation of a paragraph
                    } else {
                        break;
                    }
                    $i++;
                }
                $block = ['t' => 'quote', 'children' => self::blocks($inner)];
            } elseif (preg_match(self::ITEM, $line)) {
                $block = self::list($lines, $i);
            } elseif (self::isTable($lines, $i)) {
                $head = self::cells($line);
                $align = array_map(fn($c) => str_starts_with($c, ':') ? (str_ends_with($c, ':') ? 'center' : 'left') : (str_ends_with($c, ':') ? 'right' : 'left'), self::cells($lines[$i + 1]));
                $rows = [];
                for ($i += 2; $i < $n && trim($lines[$i]) !== '' && !self::startsBlock($lines[$i]); $i++) {
                    $rows[] = array_pad(\array_slice(self::cells($lines[$i]), 0, \count($head)), \count($head), '');
                }
                $block = ['t' => 'table', 'head' => $head, 'align' => array_pad($align, \count($head), 'left'), 'rows' => $rows];
            } elseif (str_starts_with($line, '    ')) {
                $body = [];
                while ($i < $n && (str_starts_with($lines[$i], '    ') || trim($lines[$i]) === '')) {
                    $body[] = substr($lines[$i], 4);
                    $i++;
                }
                while ($body !== [] && trim(end($body)) === '') {
                    array_pop($body);
                    $i--;
                }
                $block = ['t' => 'code', 'info' => '', 'lines' => $body];
            } else {
                $para = [];
                $block = null;
                while ($i < $n && trim($lines[$i]) !== '') {
                    if ($para !== [] && preg_match(self::SETEXT, $lines[$i], $m)) {
                        $block = ['t' => 'heading', 'level' => $m[1][0] === '=' ? 1 : 2, 'text' => self::join($para)];
                        $i++;
                        break;
                    }
                    if ($para !== [] && (self::startsBlock($lines[$i]) || self::isTable($lines, $i))) {
                        break;
                    }
                    $para[] = $lines[$i];
                    $i++;
                }
                $block ??= ['t' => 'para', 'text' => self::join($para)];
            }
            $block['src'] = implode("\n", \array_slice($lines, $start, $i - $start));
            $out[] = $block;
        }
        return $out;
    }

    /** The lines of a paragraph as one text: "\n" for a hard break (two spaces or a backslash), else a space. */
    private static function join(array $lines): string
    {
        $text = '';
        foreach ($lines as $k => $line) {
            $line = ltrim($line);
            $last = $k === \count($lines) - 1;
            if ($last) {
                $text .= rtrim($line);
            } elseif (preg_match('/( {2,}|\\\\)$/', $line)) {
                $text .= rtrim(preg_replace('/\\\\$/', '', $line)) . "\n";
            } else {
                $text .= rtrim($line) . ' ';
            }
        }
        return $text;
    }

    /** True if $line would interrupt a paragraph. */
    private static function startsBlock(string $line): bool
    {
        if (preg_match(self::FENCE, $line) || preg_match(self::HEADING, $line) || preg_match(self::RULE, $line) || preg_match('/^ {0,3}>/', $line)) {
            return true;
        }
        // a bullet, or a number 1, with something after it, may interrupt a paragraph
        return preg_match('/^ {0,3}(?:[-+*]|1[.)]) +\S/', $line) === 1;
    }

    /** @param list<string> $lines */
    private static function isTable(array $lines, int $i): bool
    {
        return isset($lines[$i + 1]) && str_contains($lines[$i], '|') && str_contains($lines[$i + 1], '-')
            && preg_match(self::DELIMITER, $lines[$i + 1]) === 1
            && \count(self::cells($lines[$i])) === \count(self::cells($lines[$i + 1]));
    }

    /** @return list<string> the cells of a table row */
    private static function cells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line);
        $line = preg_replace('/(?<!\\\\)\|$/', '', $line);
        return array_map(fn($c) => trim(str_replace('\|', '|', $c)), preg_split('/(?<!\\\\)\|/', $line));
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function list(array $lines, int &$i): array
    {
        $n = \count($lines);
        preg_match(self::ITEM, $lines[$i], $first);
        $ordered = ctype_digit($first[2][0]);
        $kind = $ordered ? substr($first[2], -1) : $first[2];
        $items = [];
        $loose = false;
        while ($i < $n && preg_match(self::ITEM, $lines[$i], $m) && ($ordered ? ctype_digit($m[2][0]) && substr($m[2], -1) === $kind : $m[2] === $kind) && !preg_match(self::RULE, $lines[$i])) {
            $gap = \strlen($m[3]) >= 1 && \strlen($m[3]) <= 4 && $m[4] !== '' ? \strlen($m[3]) : 1;
            $indent = \strlen($m[1]) + \strlen($m[2]) + $gap;
            $body = [$m[4] === '' ? '' : substr($lines[$i], $indent)];
            $i++;
            $blank = 0;
            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') {
                    $blank++;
                    $i++;
                    continue;
                }
                if (\strlen($l) - \strlen(ltrim($l)) >= $indent) {
                    array_push($body, ...array_fill(0, $blank, ''));
                    $blank = 0;
                    $body[] = substr($l, $indent);
                } elseif ($blank === 0 && !preg_match(self::ITEM, $l) && !self::startsBlock($l) && trim(end($body)) !== '') {
                    $body[] = ltrim($l);        // lazy continuation
                } else {
                    break;
                }
                $i++;
            }
            $items[] = $body;
            if ($blank > 0 && $i < $n && preg_match(self::ITEM, $lines[$i], $next) && ($ordered ? ctype_digit($next[2][0]) : $next[2] === $kind)) {
                $loose = true;
            }
        }
        return [
            't' => 'list',
            'ordered' => $ordered,
            'start' => $ordered ? (int) $first[2] : 1,
            'loose' => $loose,
            'items' => array_map(self::blocks(...), $items),
        ];
    }
}
