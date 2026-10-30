<?php

namespace phasync\Tui;

/**
 * Builds escape sequences: the producer side of the protocol a Surface parses.
 *
 * Everything that draws (widgets, the compositor, your own code) writes text and these
 * sequences into a Surface with `write()`. Coordinates are 0-based like everywhere in the library;
 * the 1-based numbers of the wire format are handled here.
 *
 *     $surface->write(Ansi::moveTo(2, 1) . Ansi::style(Style::of(Color::GREEN)) . 'ok' . Ansi::reset());
 */
final class Ansi
{
    /** Move the cursor to column $x, row $y (CUP). */
    public static function moveTo(int $x, int $y): string
    {
        return "\e[" . ($y + 1) . ';' . ($x + 1) . 'H';
    }

    /** Move the cursor up $n rows (CUU), stopping at the top margin. */
    public static function up(int $n = 1): string
    {
        return $n > 0 ? "\e[{$n}A" : '';
    }

    /** Move the cursor down $n rows (CUD), stopping at the bottom margin. */
    public static function down(int $n = 1): string
    {
        return $n > 0 ? "\e[{$n}B" : '';
    }

    /** Move the cursor right $n columns (CUF). */
    public static function right(int $n = 1): string
    {
        return $n > 0 ? "\e[{$n}C" : '';
    }

    /** Move the cursor left $n columns (CUB). */
    public static function left(int $n = 1): string
    {
        return $n > 0 ? "\e[{$n}D" : '';
    }

    /** Move the cursor to column $x of the current row (CHA). */
    public static function column(int $x): string
    {
        return "\e[" . ($x + 1) . 'G';
    }

    /**
     * Select a style (SGR): resets, then sets the attributes and colors of $style, so the result
     * does not depend on what was set before.
     */
    public static function style(Style $style): string
    {
        $codes = ['0'];
        foreach ([Style::BOLD => '1', Style::DIM => '2', Style::ITALIC => '3', Style::UNDERLINE => '4',
            Style::BLINK => '5', Style::INVERSE => '7', Style::HIDDEN => '8', Style::STRIKE => '9'] as $bit => $code) {
            if ($style->attrs & $bit) {
                $codes[] = $code;
            }
        }
        if ($style->fg !== Color::DEFAULT) {
            $codes[] = self::colorCode($style->fg, false);
        }
        if ($style->bg !== Color::DEFAULT) {
            $codes[] = self::colorCode($style->bg, true);
        }
        return "\e[" . implode(';', $codes) . 'm';
    }

    /** Set the foreground color only (see `Color`). */
    public static function fg(int $color): string
    {
        return "\e[" . self::colorCode($color, false) . 'm';
    }

    /** Set the background color only (see `Color`). */
    public static function bg(int $color): string
    {
        return "\e[" . self::colorCode($color, true) . 'm';
    }

    /** Reset colors and attributes to the default (SGR 0). */
    public static function reset(): string
    {
        return "\e[0m";
    }

    /** $text in $style, followed by a reset. */
    public static function styled(string $text, Style $style): string
    {
        return self::style($style) . $text . "\e[0m";
    }

    /** Erase the whole screen (ED 2); the cursor does not move. Erased cells get the current background. */
    public static function clearScreen(): string
    {
        return "\e[2J";
    }

    /**
     * Erase the whole screen and put the cursor at the top-left corner (ED 2, CUP): the start of
     * a drawing that repaints everything. Erased cells get the current background, so select a
     * style first (`Ansi::style()`) to fill the screen with a colour, or `reset()` for the default.
     */
    public static function clearHome(): string
    {
        return "\e[2J\e[H";
    }

    /** Erase the whole current row (EL 2). */
    public static function clearLine(): string
    {
        return "\e[2K";
    }

    /** Erase from the cursor to the end of the row (EL 0). */
    public static function clearToEndOfLine(): string
    {
        return "\e[K";
    }

    /** Erase from the cursor to the end of the screen (ED 0). */
    public static function clearToEndOfScreen(): string
    {
        return "\e[J";
    }

    /** Hide the cursor (DECTCEM). */
    public static function hideCursor(): string
    {
        return "\e[?25l";
    }

    /** Show the cursor (DECTCEM). */
    public static function showCursor(): string
    {
        return "\e[?25h";
    }

    /** Save the cursor position and style (DECSC). */
    public static function saveCursor(): string
    {
        return "\e7";
    }

    /** Restore what `saveCursor()` saved (DECRC). */
    public static function restoreCursor(): string
    {
        return "\e8";
    }

    /** Limit scrolling to rows $top to $bottom inclusive (DECSTBM); also homes the cursor. */
    public static function scrollRegion(int $top, int $bottom): string
    {
        return "\e[" . ($top + 1) . ';' . ($bottom + 1) . 'r';
    }

    /** Set the window title (OSC 2). */
    public static function title(string $title): string
    {
        return "\e]2;" . str_replace(["\x07", "\e"], '', $title) . "\x07";
    }

    /**
     * Ask the terminal to put $text on the system clipboard (OSC 52). A Surface passes it up to
     * its host and the terminal device passes it to the real terminal.
     */
    public static function clipboard(string $text): string
    {
        return "\e]52;c;" . base64_encode($text) . "\x07";
    }

    /** Switch to the alternate screen, saving the cursor (mode 1049), or back with false. */
    public static function altScreen(bool $on = true): string
    {
        return $on ? "\e[?1049h" : "\e[?1049l";
    }

    private static function colorCode(int $color, bool $bg): string
    {
        if ($color < 0) {
            return $bg ? '49' : '39';
        }
        if (Color::isRgb($color)) {
            return ($bg ? '48;2;' : '38;2;') . (($color >> 16) & 0xFF) . ';' . (($color >> 8) & 0xFF) . ';' . ($color & 0xFF);
        }
        if ($color < 8) {
            return (string) (($bg ? 40 : 30) + $color);
        }
        if ($color < 16) {
            return (string) (($bg ? 100 : 90) + $color - 8);
        }
        return ($bg ? '48;5;' : '38;5;') . $color;
    }
}
