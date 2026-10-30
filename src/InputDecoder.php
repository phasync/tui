<?php

namespace phasync\Tui;

/**
 * Turns the bytes a terminal sends into events: `Key`, `Mouse`, `Paste` and `Focus`.
 *
 * Understands legacy xterm keys (control characters, `ESC [` and `ESC O` sequences with
 * modifiers), Alt as an ESC prefix, UTF-8 text, the kitty keyboard protocol (`CSI ... u`),
 * xterm's modifyOtherKeys (`CSI 27 ; m ; code ~`), SGR (1006) and X10 mouse reports, bracketed
 * paste and focus reports. Replies to terminal queries (cursor position, device attributes,
 * colors, versions) are consumed and dropped, never mistaken for keys.
 *
 * The Screen uses it for the real terminal. Use it yourself in a coroutine producer that reads
 * its `Surface::$input` and wants events instead of bytes:
 *
 *     $decoder = new InputDecoder();
 *     while (($bytes = $surface->input->read(timeout: $decoder->hasPending() ? 0.05 : PHP_FLOAT_MAX)) !== '' || $decoder->hasPending()) {
 *         foreach ($bytes === '' ? $decoder->flush() : $decoder->feed($bytes) as $event) { ... }
 *     }
 *
 * A lone ESC cannot be told apart from the start of a sequence until more bytes arrive or a
 * short time passes: call `flush()` when no more bytes came for about 50 ms (`hasPending()`
 * says whether that is needed).
 */
final class InputDecoder
{
    private const TILDE = [1 => 'home', 2 => 'insert', 3 => 'delete', 4 => 'end', 5 => 'pageup', 6 => 'pagedown',
        7 => 'home', 8 => 'end', 11 => 'f1', 12 => 'f2', 13 => 'f3', 14 => 'f4', 15 => 'f5', 17 => 'f6', 18 => 'f7',
        19 => 'f8', 20 => 'f9', 21 => 'f10', 23 => 'f11', 24 => 'f12'];
    private const LETTER = ['A' => 'up', 'B' => 'down', 'C' => 'right', 'D' => 'left', 'H' => 'home', 'F' => 'end',
        'P' => 'f1', 'Q' => 'f2', 'R' => 'f3', 'S' => 'f4', 'E' => 'begin'];
    private const CODES = [13 => 'enter', 9 => 'tab', 27 => 'escape', 127 => 'backspace', 8 => 'backspace', 32 => 'space'];

    private string $buffer = '';
    private ?string $paste = null;

    /**
     * Decode bytes; sequences cut off at the end are kept for the next call.
     *
     * @return list<Event>
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        return $this->decode(false);
    }

    /**
     * Decode what is still pending as if no more bytes will follow: a lone ESC becomes the Escape
     * key, ESC and a character become Alt+character, other incomplete sequences are dropped. An
     * unfinished bracketed paste is kept.
     *
     * @return list<Event>
     */
    public function flush(): array
    {
        return $this->decode(true);
    }

    /** True when bytes are waiting that `flush()` would decide about. */
    public function hasPending(): bool
    {
        return $this->buffer !== '' && $this->paste === null;
    }

    /** @return list<Event> */
    private function decode(bool $final): array
    {
        $events = [];
        $buf = $this->buffer;
        $len = \strlen($buf);
        $i = 0;
        while ($i < $len) {
            if ($this->paste !== null) {
                $end = strpos($buf, "\e[201~", $i);
                if ($end === false) {
                    // keep a possible partial end marker in the buffer
                    $keep = min(5, $len - $i);
                    $this->paste .= substr($buf, $i, $len - $i - $keep);
                    $i = $len - $keep;
                    break;
                }
                $text = $this->paste . substr($buf, $i, $end - $i);
                $events[] = new Paste(str_replace(["\r\n", "\r"], "\n", $text));
                $this->paste = null;
                $i = $end + 6;
                continue;
            }
            $c = $buf[$i];
            $o = \ord($c);
            if ($c === "\e") {
                $n = $this->escape($buf, $i, $len, $final, $events);
                if ($n === 0) {
                    break; // incomplete
                }
                $i += $n;
                continue;
            }
            if ($o < 0x20 || $o === 0x7f) {
                $events[] = self::control($o, false);
                $i++;
                continue;
            }
            $n = self::utf8Length($o);
            if ($i + $n > $len) {
                if ($final) {
                    $i = $len;
                }
                break;
            }
            $ch = substr($buf, $i, $n);
            $i += $n;
            if (!mb_check_encoding($ch, 'UTF-8')) {
                continue;
            }
            $events[] = $ch === ' ' ? new Key('space') : new Key($ch);
        }
        $this->buffer = (string) substr($buf, $i);
        if ($final && $this->paste === null) {
            $this->buffer = '';
        }
        return $events;
    }

    private static function utf8Length(int $o): int
    {
        return match (true) {
            $o >= 0xF0 => 4,
            $o >= 0xE0 => 3,
            $o >= 0xC0 => 2,
            default => 1,
        };
    }

    /** A control character as a key. */
    private static function control(int $o, bool $alt): Key
    {
        return match (true) {
            $o === 0x0d, $o === 0x0a => new Key('enter', alt: $alt),
            $o === 0x09 => new Key('tab', alt: $alt),
            $o === 0x7f => new Key('backspace', alt: $alt),
            $o === 0x08 => new Key('backspace', ctrl: true, alt: $alt),
            $o === 0x00 => new Key('space', ctrl: true, alt: $alt),
            $o === 0x1b => new Key('escape', alt: $alt),
            $o <= 0x1a => new Key(\chr($o + 0x60), ctrl: true, alt: $alt),
            default => new Key(\chr($o + 0x40), ctrl: true, alt: $alt),
        };
    }

    /**
     * Decode the sequence starting with ESC at $i; returns the bytes consumed, 0 if incomplete.
     *
     * @param list<Event> $events
     */
    private function escape(string $buf, int $i, int $len, bool $final, array &$events): int
    {
        if ($i + 1 >= $len) {
            if ($final) {
                $events[] = new Key('escape');
                return 1;
            }
            return 0;
        }
        $next = $buf[$i + 1];
        if ($next === '[') {
            return $this->csi($buf, $i, $len, $final, $events);
        }
        if ($next === 'O') {
            if ($i + 2 >= $len) {
                if ($final) {
                    $events[] = new Key('O', alt: true);
                    return 2;
                }
                return 0;
            }
            $f = $buf[$i + 2];
            if (isset(self::LETTER[$f])) {
                if (self::LETTER[$f] !== 'begin') {
                    $events[] = new Key(self::LETTER[$f]);
                }
            } elseif ($f === 'M') {
                $events[] = new Key('enter');
            } elseif ($f >= 'j' && $f <= 'y') {
                // application keypad: * + , - . / 0-9
                $events[] = new Key(\chr(\ord($f) - 0x40));
            }
            return 3;
        }
        if ($i + 2 >= $len && ($next === ']' || $next === 'P') && !$final) {
            return 0;
        }
        if (($next === ']' && ctype_digit($buf[$i + 2] ?? '')) || ($next === 'P' && strpbrk($buf[$i + 2] ?? '', '>0123456789') !== false)) {
            // a reply string (OSC colors, DCS XTVERSION or DECRQSS): consume up to BEL or ST, drop.
            // ESC ] and ESC P followed by anything else are Alt+] and Alt+P.
            $bel = $next === ']' ? strpos($buf, "\x07", $i + 2) : false;
            $st = strpos($buf, "\e\\", $i + 2);
            $end = $bel !== false && ($st === false || $bel < $st) ? $bel + 1 : ($st !== false ? $st + 2 : false);
            if ($end === false) {
                if ($final) {
                    return $len - $i;
                }
                return 0;
            }
            return $end - $i;
        }
        if ($next === "\e") {
            $events[] = new Key('escape');
            return 1;
        }
        // Alt + key
        $o = \ord($next);
        if ($o < 0x20 || $o === 0x7f) {
            $events[] = self::control($o, true);
            return 2;
        }
        $n = self::utf8Length($o);
        if ($i + 1 + $n > $len) {
            if ($final) {
                $events[] = new Key('escape');
                return 1;
            }
            return 0;
        }
        $ch = substr($buf, $i + 1, $n);
        $events[] = $ch === ' ' ? new Key('space', alt: true) : new Key(mb_strtolower($ch) === $ch ? $ch : mb_strtolower($ch), alt: true, shift: mb_strtolower($ch) !== $ch);
        return 1 + $n;
    }

    /** @param list<Event> $events */
    private function csi(string $buf, int $i, int $len, bool $final, array &$events): int
    {
        $j = $i + 2;
        if ($j < $len && $buf[$j] === 'M') {
            // X10 mouse: ESC [ M Cb Cx Cy (a terminal sends no other CSI M)
            if ($len - $j < 4) {
                return $final ? $len - $i : 0;
            }
            $events[] = self::mouse(\ord($buf[$j + 1]) - 32, \ord($buf[$j + 2]) - 33, \ord($buf[$j + 3]) - 33, true, true);
            return 6;
        }
        while ($j < $len && ($o = \ord($buf[$j])) >= 0x20 && $o <= 0x3f) {
            $j++;
        }
        $params = substr($buf, $i + 2, $j - $i - 2);
        while ($j < $len && ($o = \ord($buf[$j])) >= 0x20 && $o <= 0x2f) {
            $j++;
        }
        if ($j >= $len) {
            if ($final) {
                if ($params === '') {
                    $events[] = new Key('[', alt: true);
                    return 2;
                }
                return $len - $i;
            }
            return 0;
        }
        $f = $buf[$j];
        $consumed = $j - $i + 1;
        $o = \ord($f);
        if ($o < 0x40 || $o > 0x7e) {
            // not a valid sequence: drop the introducer
            return 2;
        }
        $prefix = $params !== '' && strpbrk($params[0], '<=>?') !== false ? $params[0] : '';
        $body = $prefix === '' ? $params : substr($params, 1);
        $p = $body === '' ? [] : explode(';', $body);

        if ($prefix === '<' && ($f === 'M' || $f === 'm')) {
            if (\count($p) >= 3) {
                $events[] = self::mouse((int) $p[0], (int) $p[1] - 1, (int) $p[2] - 1, $f === 'M', false);
            }
            return $consumed;
        }
        if ($prefix !== '') {
            return $consumed; // replies: DA, DECRQM, kitty flags, cursor reports
        }
        $mods = isset($p[1]) ? (int) explode(':', $p[1])[0] : 1;
        if ($f === '~') {
            $n = (int) ($p[0] ?? 0);
            if ($n === 200) {
                $this->paste = '';
                return $consumed;
            }
            if ($n === 27 && isset($p[2])) {
                $events[] = self::codeKey((int) $p[2], (int) $p[1]);
                return $consumed;
            }
            if (isset(self::TILDE[$n])) {
                $events[] = self::withMods(self::TILDE[$n], $mods);
            }
            return $consumed;
        }
        if ($f === 'u') {
            $code = (int) explode(':', $p[0] ?? '0')[0];
            $event = isset($p[1]) ? (int) (explode(':', $p[1])[1] ?? 1) : 1;
            if ($event !== 3 && $code > 0) {
                $events[] = self::codeKey($code, $mods);
            }
            return $consumed;
        }
        if ($f === 'I' && $params === '') {
            $events[] = new Focus(true);
            return $consumed;
        }
        if ($f === 'O' && $params === '') {
            $events[] = new Focus(false);
            return $consumed;
        }
        if ($f === 'Z') {
            $events[] = new Key('tab', shift: true);
            return $consumed;
        }
        if (isset(self::LETTER[$f])) {
            // "CSI row ; col R" is a cursor position report, not F3
            if ($f === 'R' && \count($p) === 2 && (int) $p[0] !== 1) {
                return $consumed;
            }
            if (self::LETTER[$f] !== 'begin') {
                $events[] = self::withMods(self::LETTER[$f], $mods);
            }
        }
        return $consumed;
    }

    private static function withMods(string $name, int $mods): Key
    {
        $m = max(0, $mods - 1);
        return new Key($name, ctrl: ($m & 4) !== 0, alt: ($m & 2) !== 0, shift: ($m & 1) !== 0);
    }

    /** A key from a Unicode code point and an xterm/kitty modifier value. */
    private static function codeKey(int $code, int $mods): Key
    {
        $m = max(0, $mods - 1);
        $ctrl = ($m & 4) !== 0;
        $alt = ($m & 2) !== 0;
        $shift = ($m & 1) !== 0;
        if (isset(self::CODES[$code])) {
            return new Key(self::CODES[$code], $ctrl, $alt, $shift);
        }
        $ch = mb_chr($code, 'UTF-8');
        if ($ch === false || $code >= 57344 && $code <= 63743) {
            return new Key('escape'); // private-use functional keys (keypad, media): not mapped
        }
        if ($shift && !$ctrl && !$alt) {
            // Shift alone types the shifted character
            $upper = mb_strtoupper($ch);
            return $upper !== $ch || mb_strtolower($ch) !== $ch ? new Key($upper) : new Key($ch, shift: true);
        }
        return new Key($ch, $ctrl, $alt, $shift);
    }

    private static function mouse(int $b, int $x, int $y, bool $press, bool $x10): Mouse
    {
        $shift = ($b & 4) !== 0;
        $alt = ($b & 8) !== 0;
        $ctrl = ($b & 16) !== 0;
        $button = $b & 3;
        if ($b & 64) {
            $action = [Mouse::WHEEL_UP, Mouse::WHEEL_DOWN, Mouse::WHEEL_LEFT, Mouse::WHEEL_RIGHT][$button];
            return new Mouse($action, $x, $y, null, $ctrl, $alt, $shift);
        }
        if ($b & 32) {
            return new Mouse($button === 3 ? Mouse::MOVE : Mouse::DRAG, $x, $y, $button === 3 ? null : $button, $ctrl, $alt, $shift);
        }
        if ($x10 && $button === 3) {
            return new Mouse(Mouse::RELEASE, $x, $y, null, $ctrl, $alt, $shift);
        }
        return new Mouse($press ? Mouse::PRESS : Mouse::RELEASE, $x, $y, $button === 3 ? null : $button, $ctrl, $alt, $shift);
    }
}
