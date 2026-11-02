<?php

namespace phasync\Tui\Internal;

/**
 * @internal A monospaced bitmap font of up to 8 pixels per row, as `BigText` draws it: the
 * glyphs by code point, each `height` rows of one byte, the leftmost pixel the high bit.
 * Read from the library's packed `.font` files (`resources/fonts/`, made by
 * `tools/bdf2font.php`) or straight from a BDF file; each file is parsed once.
 */
final class BitmapFont
{
    private const MAGIC = "TUIFONT1";
    /** @var array<string, BitmapFont> by the file they were read from */
    private static array $loaded = [];

    /** @param array<int, string> $glyphs code point => `height` bytes */
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $glyphs,
    ) {
    }

    /**
     * The bundled font of that name (`'6x13'`, `'5x7'`), or the font in a `.font`, `.bdf`,
     * `.psf` or `.psf.gz` file at that path (a Linux console font, `/usr/share/consolefonts`).
     *
     * @throws \InvalidArgumentException when there is no such font or file
     */
    public static function load(string $font): self
    {
        $path = is_file($font) ? $font : \dirname(__DIR__, 2) . "/resources/fonts/fixed-$font.font";
        if (isset(self::$loaded[$path])) {
            return self::$loaded[$path];
        }
        $data = is_file($path) ? (string) file_get_contents($path) : throw new \InvalidArgumentException("No font '$font': use '6x13', '5x7' or the path of a .bdf, .psf(.gz) or .font file");
        if (str_starts_with($data, "\x1f\x8b")) {
            $data = (string) gzdecode($data);
        }
        return self::$loaded[$path] = match (true) {
            str_starts_with($data, self::MAGIC) => self::unpack($data),
            str_starts_with($data, "\x36\x04"), str_starts_with($data, "\x72\xb5\x4a\x86") => self::fromPsf($data),
            default => self::fromBdf($data),
        };
    }

    /** The glyph of $codePoint: `height` bytes, or null when the font has none. */
    public function glyph(int $codePoint): ?string
    {
        return $this->glyphs[$codePoint] ?? null;
    }

    /** Parse a BDF font: every glyph must be at most 8 wide and is placed in the font's bounding box. */
    public static function fromBdf(string $bdf): self
    {
        if (preg_match('/^FONTBOUNDINGBOX (\d+) (\d+) (-?\d+) (-?\d+)/m', $bdf, $m) !== 1) {
            throw new \InvalidArgumentException('Not a BDF font: no FONTBOUNDINGBOX');
        }
        [$width, $height, $fx, $fy] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        if ($width > 8) {
            throw new \InvalidArgumentException("The font is $width pixels wide; BigText takes fonts up to 8");
        }
        $glyphs = [];
        preg_match_all('/^ENCODING (\d+)\n(?:.*\n)*?BBX (\d+) (\d+) (-?\d+) (-?\d+)\nBITMAP\n((?:[0-9A-Fa-f]+\n)*)ENDCHAR/m', $bdf, $all, \PREG_SET_ORDER);
        foreach ($all as [, $cp, $w, $h, $x, $y, $rows]) {
            // the glyph's box inside the font's: $x right of the left edge, $y above the baseline's offset
            [$w, $h, $x, $y] = [(int) $w, (int) $h, (int) $x - $fx, (int) $y - $fy];
            $bits = array_fill(0, $height, 0);
            $top = $height - $h - $y;
            foreach (explode("\n", trim($rows)) as $i => $hex) {
                $row = $top + $i;
                if ($row >= 0 && $row < $height && $hex !== '') {
                    $bits[$row] = (hexdec(substr($hex, 0, 2)) >> $x) & 0xff;
                }
            }
            $glyphs[(int) $cp] = pack('C*', ...$bits);
        }
        return new self($width, $height, $glyphs);
    }

    /**
     * Parse a PC Screen Font (PSF1 or PSF2, what the Linux console uses) with its Unicode table;
     * a glyph is listed under every code point the table gives it. At most 8 pixels wide.
     */
    public static function fromPsf(string $psf): self
    {
        if (str_starts_with($psf, "\x36\x04")) {
            // PSF1: 256 or 512 glyphs of 8 x charsize, then a UCS-2 table (0xFFFE starts a sequence, 0xFFFF ends a glyph's entries)
            $mode = \ord($psf[2]);
            [$n, $height, $width, $at] = [$mode & 1 ? 512 : 256, \ord($psf[3]), 8, 4];
            $table = $mode & 6 ? substr($psf, 4 + $n * $height) : null;
            $next = static function (string $t, int &$i): ?int {
                if ($i + 1 >= \strlen($t)) {
                    return null;
                }
                $v = unpack('v', $t, $i)[1];
                $i += 2;
                return $v;
            };
        } else {
            ['hs' => $hs, 'flags' => $flags, 'n' => $n, 'size' => $size, 'h' => $height, 'w' => $width] = unpack('Vhs/Vflags/Vn/Vsize/Vh/Vw', $psf, 8);
            if ($size !== $height || $width > 8) {
                throw new \InvalidArgumentException("The console font is $width pixels wide; BigText takes fonts up to 8");
            }
            $at = $hs;
            $table = $flags & 1 ? substr($psf, $hs + $n * $height) : null;
            $next = static function (string $t, int &$i): ?int {
                // UTF-8 (0xFE starts a sequence, 0xFF ends a glyph's entries)
                if ($i >= \strlen($t)) {
                    return null;
                }
                $b = \ord($t[$i]);
                if ($b >= 0xfe) {
                    $i++;
                    return 0xff00 | $b;
                }
                $len = $b < 0x80 ? 1 : ($b < 0xe0 ? 2 : ($b < 0xf0 ? 3 : 4));
                $cp = mb_ord(substr($t, $i, $len), 'UTF-8');
                $i += $len;
                return $cp === false ? 0xfffd : $cp;
            };
        }
        [$end, $seq] = [0xffff, 0xfffe];
        $glyphs = [];
        $i = 0;
        for ($g = 0; $g < $n; $g++) {
            $rows = substr($psf, $at + $g * $height, $height);
            if ($table === null) {
                $glyphs[$g] = $rows;
                continue;
            }
            $inSequence = false;
            while (($cp = $next($table, $i)) !== null && $cp !== $end) {
                if ($cp === $seq) {
                    $inSequence = true;      // (a glyph for a sequence of code points: not a character of its own)
                } elseif (!$inSequence && !isset($glyphs[$cp])) {
                    $glyphs[$cp] = $rows;
                }
            }
        }
        ksort($glyphs);
        return new self($width, $height, $glyphs);
    }

    /** The font as a `.font` file. */
    public function pack(): string
    {
        $out = self::MAGIC . pack('CCN', $this->width, $this->height, \count($this->glyphs));
        foreach ($this->glyphs as $cp => $rows) {
            $out .= pack('N', $cp) . $rows;
        }
        return $out;
    }

    private static function unpack(string $data): self
    {
        ['w' => $width, 'h' => $height, 'n' => $n] = unpack('Cw/Ch/Nn', $data, \strlen(self::MAGIC));
        $glyphs = [];
        $at = \strlen(self::MAGIC) + 6;
        for ($i = 0; $i < $n; $i++) {
            $glyphs[unpack('N', $data, $at)[1]] = substr($data, $at + 4, $height);
            $at += 4 + $height;
        }
        return new self($width, $height, $glyphs);
    }
}
