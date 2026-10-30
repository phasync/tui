<?php

namespace phasync\Tui;

use phasync\Tui\Surface\Width;

/**
 * Measuring text the way a Surface lays it out: by grapheme clusters, wide characters and emoji
 * taking two columns, combining marks none.
 *
 * Use it to align output you write into a surface (pad a column, truncate a title, place a
 * cursor): `Text::width('中文')` is 4, `Text::width("e\u{301}")` is 1.
 */
final class Text
{
    /** Columns $text occupies when printed (control characters count as 0). */
    public static function width(string $text): int
    {
        if (!preg_match('/[^\x20-\x7e]/', $text)) {
            return \strlen($text);
        }
        $w = 0;
        foreach (self::clusters($text) as [, $cw]) {
            $w += $cw;
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
     * for a hard cut). Never splits a cluster.
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
        $w = 0;
        foreach (self::clusters($text) as [$g, $gw]) {
            if ($w + $gw > $room) {
                break;
            }
            $out .= $g;
            $w += $gw;
        }
        return $out . $ellipsis;
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
}
