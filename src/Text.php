<?php

namespace phasync\Tui;

use phasync\Tui\Surface\Width;

/**
 * Measuring and breaking text the way a Surface lays it out: by grapheme clusters, wide
 * characters and emoji taking two columns, combining marks none, escape sequences none.
 *
 * Use it to align output you write into a surface (pad a column, truncate a title, place a
 * cursor) and to lay out text for a width (`wrap()`): `Text::width('中文')` is 4,
 * `Text::width("e\u{301}")` is 1, `Text::width("\e[31mred\e[0m")` is 3.
 *
 * Escape sequences (SGR colours and the like) and control characters in the text take no
 * columns and are kept where they are, so styled text can be measured, cut and wrapped too.
 */
final class Text
{
    /** An escape sequence (CSI, OSC, DCS/APC/PM/SOS, other ESC) or a control character other than "\n". */
    private const CONTROL = '/(\e(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\e]*(?:\x07|\e\\\\)?|[P_^X][^\e]*(?:\e\\\\)?|[ -\/]*[0-~])?|[\x00-\x09\x0b-\x1a\x1c-\x1f\x7f])/';

    /** Columns $text occupies when printed (escape sequences and control characters count as 0). */
    public static function width(string $text): int
    {
        if (!preg_match('/[^\x20-\x7e]/', $text)) {
            return \strlen($text);
        }
        $w = 0;
        foreach (self::tokens($text) as [, $tw]) {
            $w += $tw;
        }
        return $w;
    }

    /**
     * The grapheme clusters of $text with the columns each occupies (0, 1 or 2).
     *
     * @return list<array{string,int}>
     */
    public static function clusters(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        $out = [];
        foreach (self::graphemes($text) as $g) {
            $out[] = [$g, self::clusterWidth($g)];
        }
        return $out;
    }

    /**
     * $text (valid UTF-8) split into grapheme clusters by the Unicode rules (UAX #29), as the
     * Surface lays text out: a letter with its combining marks, an emoji with its modifiers, a
     * ZWJ sequence or a flag is one cluster.
     *
     * @return list<string>
     */
    public static function graphemes(string $text): array
    {
        preg_match_all('/\X/u', $text, $m);
        $out = [];
        foreach ($m[0] as $g) {
            // PCRE2 before 10.43 does not break between two pictographs (GB999): "👍👍" would be
            // one cluster. Break before every pictograph that does not follow a ZWJ (GB11).
            if (\strlen($g) > 4 && ($parts = @preg_split('/(?<!\x{200D})(?=\p{Extended_Pictographic})/u', $g, -1, \PREG_SPLIT_NO_EMPTY)) !== false) {
                array_push($out, ...$parts);
            } else {
                $out[] = $g;
            }
        }
        return $out;
    }

    /** Columns one grapheme cluster occupies: 0, 1 or 2. */
    public static function clusterWidth(string $cluster): int
    {
        $cp = mb_ord($cluster, 'UTF-8');
        if ($cp === false || $cp < 0x20 || ($cp >= 0x7f && $cp < 0xa0)) {
            return 0;
        }
        $w = Width::of($cp);
        if ($w === 1 && str_contains($cluster, "\u{FE0F}") && Width::isEmoji($cp)) {
            return 2;
        }
        return $w;
    }

    /**
     * $text cut to at most $width columns; when cut, the last column holds $ellipsis (pass ''
     * for a hard cut). Never splits a cluster; escape sequences after the cut are kept (so a
     * closing `ESC [0m` still resets the style).
     */
    public static function truncate(string $text, int $width, string $ellipsis = '…'): string
    {
        if (self::width($text) <= $width) {
            return $text;
        }
        $room = $width - self::width($ellipsis);
        if ($room < 0) {
            return '';
        }
        $out = '';
        $tail = '';
        $w = 0;
        $cut = false;
        foreach (self::tokens($text) as [$t, $tw, $control]) {
            if ($control) {
                $cut ? $tail .= $t : $out .= $t;
            } elseif (!$cut && $w + $tw <= $room) {
                $out .= $t;
                $w += $tw;
            } else {
                $cut = true;
            }
        }
        return $out . $ellipsis . $tail;
    }

    /** $text padded with spaces (or cut) to exactly $width columns; $align is 'left', 'right' or 'center'. */
    public static function pad(string $text, int $width, string $align = 'left'): string
    {
        $text = self::truncate($text, $width, '');
        $gap = $width - self::width($text);
        return match ($align) {
            'right' => str_repeat(' ', $gap) . $text,
            'center' => str_repeat(' ', intdiv($gap, 2)) . $text . str_repeat(' ', $gap - intdiv($gap, 2)),
            default => $text . str_repeat(' ', $gap),
        };
    }

    /**
     * $text laid out in rows of at most $width columns, as a terminal $width columns wide shows
     * it: by display width and grapheme cluster, a wide character that does not fit at the end
     * of a row moving to the next. Every "\n" starts a new row ("\r\n" counts as one).
     *
     * With $words, rows break after a space where they can: the spaces at a break are dropped,
     * and only a word longer than a row is broken inside. Escape sequences stay in the row where
     * they occur, at no width, so writing the rows one after another (joined with "\r\n") into a
     * surface shows the same styles as the text.
     *
     * Use it to lay out text for a width that may change: keep the text, and wrap it again for
     * the new width (as `Component\Log` does).
     *
     * @return list<string> at least one row (an empty text is one empty row)
     */
    public static function wrap(string $text, int $width, bool $words = false): array
    {
        $width = max(1, $width);
        $rows = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            if ($line === '' || (\strlen($line) <= $width && !preg_match('/[^\x20-\x7e]/', $line))) {
                $rows[] = $line;
                continue;
            }
            array_push($rows, ...self::wrapLine(self::tokens($line), $width, $words));
        }
        return $rows;
    }

    /**
     * @param list<array{string,int,bool}> $tokens
     * @return list<string>
     */
    private static function wrapLine(array $tokens, int $width, bool $words): array
    {
        $rows = [];
        $row = [];         // tokens of the row being filled
        $w = 0;            // its width
        $break = null;     // index in $row just after the last space, where a word break can go
        $soft = false;     // the row starts where a longer one was broken
        foreach ($tokens as $token) {
            [$t, $tw, $control] = $token;
            if ($words && $soft && $w === 0 && $t === ' ') {
                continue;  // spaces at a break are dropped
            }
            if ($control || $w + $tw <= $width) {
                $row[] = $token;
                $w += $tw;
                if ($words && $t === ' ') {
                    $break = \count($row);
                }
                continue;
            }
            // $token does not fit
            $soft = true;
            if ($words && $t === ' ') {
                $rows[] = self::joinRow($row, true);
                [$row, $w, $break] = [[], 0, null];
                continue;       // the space at the break is dropped
            }
            if ($words && $break !== null) {
                $carry = \array_slice($row, $break);
                $rows[] = self::joinRow(\array_slice($row, 0, $break), true);
                $row = self::dropLeadingSpaces($carry);
                $w = 0;
                foreach ($row as [, $cw]) {
                    $w += $cw;
                }
                $break = null;
                if ($w + $tw <= $width) {
                    $row[] = $token;
                    $w += $tw;
                    continue;
                }
            }
            if ($row !== [] && $w > 0) {
                $rows[] = self::joinRow($row, false);
                [$row, $w, $break] = [[], 0, null];
            }
            if ($words && $t === ' ') {
                continue;
            }
            $row[] = $token;
            $w += $tw;
        }
        $rows[] = self::joinRow($row, false);
        return $rows;
    }

    /** @param list<array{string,int,bool}> $row */
    private static function joinRow(array $row, bool $trimSpaces): string
    {
        if ($trimSpaces) {
            for ($i = \count($row) - 1; $i >= 0 && ($row[$i][0] === ' ' || $row[$i][2]); $i--) {
                if ($row[$i][0] === ' ') {
                    array_splice($row, $i, 1);
                }
            }
        }
        return implode('', array_column($row, 0));
    }

    /**
     * @param list<array{string,int,bool}> $row
     * @return list<array{string,int,bool}>
     */
    private static function dropLeadingSpaces(array $row): array
    {
        $out = [];
        $leading = true;
        foreach ($row as $token) {
            if ($leading && $token[0] === ' ') {
                continue;
            }
            if (!$token[2]) {
                $leading = false;
            }
            $out[] = $token;
        }
        return $out;
    }

    /**
     * $text as a sequence of [piece, columns, isControl]: grapheme clusters, and escape sequences
     * and control characters (at 0 columns).
     *
     * @return list<array{string,int,bool}>
     */
    private static function tokens(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        $out = [];
        $parts = preg_split(self::CONTROL, $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            if ($i % 2 === 1) {
                $out[] = [$part, 0, true];
                continue;
            }
            foreach (self::graphemes($part) as $g) {
                $out[] = [$g, self::clusterWidth($g), false];
            }
        }
        return $out;
    }
}
