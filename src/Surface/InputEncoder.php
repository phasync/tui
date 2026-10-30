<?php

namespace phasync\Tui\Surface;

use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Surface;

/**
 * @internal Encodes keys, mouse events and pastes into the bytes a program expects from an
 * xterm, according to the modes it set on its Surface: application cursor keys (DECCKM), the
 * kitty keyboard flags, modifyOtherKeys, the mouse tracking mode and encoding, bracketed paste.
 * InputDecoder is the inverse.
 */
final class InputEncoder
{
    private const CSI_TILDE = ['insert' => 2, 'delete' => 3, 'pageup' => 5, 'pagedown' => 6,
        'f5' => 15, 'f6' => 17, 'f7' => 18, 'f8' => 19, 'f9' => 20, 'f10' => 21, 'f11' => 23, 'f12' => 24];
    private const CSI_LETTER = ['up' => 'A', 'down' => 'B', 'right' => 'C', 'left' => 'D', 'home' => 'H', 'end' => 'F'];
    private const SS3 = ['f1' => 'P', 'f2' => 'Q', 'f3' => 'R', 'f4' => 'S'];
    private const CODES = ['enter' => 13, 'tab' => 9, 'backspace' => 127, 'escape' => 27, 'space' => 32];

    public static function key(Key $key, Surface $s): string
    {
        $mod = 1 + ($key->shift ? 1 : 0) + ($key->alt ? 2 : 0) + ($key->ctrl ? 4 : 0);
        $name = $key->name;
        $flags = $s->keyboardFlags();
        $all = ($flags & 8) !== 0;

        if (isset(self::CSI_LETTER[$name])) {
            $f = self::CSI_LETTER[$name];
            if ($mod > 1) {
                return "\e[1;{$mod}{$f}";
            }
            return ($s->mode(1) && !$all ? "\eO" : "\e[") . $f;
        }
        if (isset(self::SS3[$name])) {
            return $mod > 1 ? "\e[1;{$mod}" . self::SS3[$name] : "\eO" . self::SS3[$name];
        }
        if (isset(self::CSI_TILDE[$name])) {
            $n = self::CSI_TILDE[$name];
            return $mod > 1 ? "\e[$n;{$mod}~" : "\e[$n~";
        }

        $code = self::CODES[$name] ?? mb_ord($name, 'UTF-8');
        if ($code === false) {
            return '';
        }
        // the kitty keyboard protocol
        if ($flags & 9) {   // disambiguate (1), or report all keys as escape codes (8)
            $plainText = $mod === 1 && $name !== 'escape';
            $legacyKey = \in_array($name, ['enter', 'tab', 'backspace'], true) && $mod === 1;
            if ($all || !($plainText || $legacyKey || ($mod === 2 && $key->text !== ''))) {
                $base = $key->shift && mb_strlen($name) === 1 ? mb_ord(mb_strtolower($name), 'UTF-8') : $code;
                return $mod > 1 ? "\e[$base;{$mod}u" : "\e[{$base}u";
            }
        }
        // xterm modifyOtherKeys
        $other = $s->modifyOtherKeys();
        if ($other > 0 && ($key->ctrl || $key->alt || ($key->shift && \in_array($name, ['enter', 'tab', 'backspace', 'space'], true)))) {
            $ambiguous = $key->shift || ($key->ctrl && !preg_match('/^[a-z@\[\\\\\]^_ ]$/', $name === 'space' ? ' ' : $name));
            if ($other === 2 || $ambiguous) {
                return "\e[27;{$mod};{$code}~";
            }
        }

        switch ($name) {
            case 'enter':
                $bytes = "\r";
                break;
            case 'tab':
                return $key->shift ? "\e[Z" : ($key->alt ? "\e\t" : "\t");
            case 'backspace':
                $bytes = $key->ctrl ? "\x08" : "\x7f";
                break;
            case 'escape':
                $bytes = "\e";
                break;
            case 'space':
                $bytes = $key->ctrl ? "\0" : ' ';
                break;
            default:
                $bytes = $name;
                if ($key->ctrl) {
                    $c = \strlen($name) === 1 ? \ord(strtolower($name)) : 0;
                    if ($c >= 0x61 && $c <= 0x7a) {
                        $bytes = \chr($c - 0x60);
                    } elseif ($c >= 0x40 && $c <= 0x5f) {
                        $bytes = \chr($c - 0x40);
                    } elseif ($name === '?') {
                        $bytes = "\x7f";
                    } elseif ($name === '/') {
                        $bytes = "\x1f";
                    } elseif ($name === '2') {
                        $bytes = "\0";
                    } elseif ($name >= '3' && $name <= '7') {
                        $bytes = \chr(\ord($name) - \ord('3') + 0x1b);
                    }
                } elseif ($key->shift && $key->text === '' && mb_strlen($name) === 1) {
                    $bytes = mb_strtoupper($name);
                }
        }
        return ($key->alt ? "\e" : '') . $bytes;
    }

    public static function mouse(Mouse $m, Surface $s): string
    {
        $any = $s->mode(1003);
        $drag = $any || $s->mode(1002);
        $basic = $drag || $s->mode(1000);
        $x10 = $s->mode(9);
        if (!$basic && !$x10) {
            return '';
        }
        $isRelease = $m->action === Mouse::RELEASE;
        switch ($m->action) {
            case Mouse::PRESS:
            case Mouse::RELEASE:
                $b = $m->button ?? 0;
                break;
            case Mouse::DRAG:
                if (!$drag) {
                    return '';
                }
                $b = 32 + ($m->button ?? 0);
                break;
            case Mouse::MOVE:
                if (!$any) {
                    return '';
                }
                $b = 32 + 3;
                break;
            default:
                $b = 64 + match ($m->action) {
                    Mouse::WHEEL_UP => 0,
                    Mouse::WHEEL_DOWN => 1,
                    Mouse::WHEEL_LEFT => 2,
                    default => 3,
                };
        }
        if ($x10 && !$basic && ($m->action !== Mouse::PRESS)) {
            return '';
        }
        if (!$x10) {
            $b |= ($m->shift ? 4 : 0) | ($m->alt ? 8 : 0) | ($m->ctrl ? 16 : 0);
        }
        if ($s->mode(1006)) {
            return sprintf("\e[<%d;%d;%d%s", $b, $m->x + 1, $m->y + 1, $isRelease ? 'm' : 'M');
        }
        if ($isRelease) {
            $b = ($b & ~3) | 3;
        }
        if ($m->x > 222 || $m->y > 222) {
            return '';
        }
        return "\e[M" . \chr(32 + $b) . \chr(33 + $m->x) . \chr(33 + $m->y);
    }

    public static function paste(string $text, Surface $s): string
    {
        // a terminal sends line breaks as CR, like the Enter key
        $text = str_replace(["\r\n", "\n"], "\r", $text);
        if ($s->mode(2004)) {
            return "\e[200~" . str_replace("\e[201~", '', $text) . "\e[201~";
        }
        return $text;
    }
}
