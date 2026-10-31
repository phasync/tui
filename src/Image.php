<?php

namespace phasync\Tui;

/**
 * A picture that cells of a Surface show: the bytes a program sent with an image protocol
 * (Kitty graphics, iTerm2 inline images), with their pixel size.
 *
 * Images get onto a Surface the way everything does, by `write()`: a program (or the `Canvas`
 * element) sends an image escape sequence, the Surface keeps the image and marks the cells it
 * covers with an `ImageFragment` in their style (`$surface->cell($x, $y)->style->image`). A
 * terminal device then shows those cells with the protocol the real terminal has, or as
 * quarter blocks when it has none.
 *
 * Images are values: the same bytes give the same object (`Image::of()`), so a program that
 * sends the same picture again makes no new image, and a frame that shows the same picture as
 * the last one shows no change. An image lives as long as a cell (or a Kitty image id) refers
 * to it.
 */
final class Image
{
    /** @var array<string, \WeakReference<Image>> */
    private static array $interned = [];
    private static int $purgeAt = 64;
    private static int $serial = 0;
    /** The PNG made for an image that is not one (see png()). */
    private ?string $png = null;
    private ?string $rgba = null;
    private bool $decoded = false;

    private function __construct(
        /** A number unique to this image in the process. */
        public readonly int $id,
        /**
         * What `data` holds: `'png'`, `'rgb'` or `'rgba'` (raw pixels, 3 or 4 bytes each, rows
         * top to bottom), or another format a program may send (`'jpeg'`, `'gif'`, `'webp'`).
         */
        public readonly string $format,
        /** Width in pixels. */
        public readonly int $width,
        /** Height in pixels. */
        public readonly int $height,
        /** The bytes, in `format`. */
        public readonly string $data,
    ) {
    }

    /**
     * The image of these bytes: PNG (or JPEG, GIF, WebP) with its size read from its header,
     * or raw pixels (`'rgb'`, `'rgba'`) of the given size. Null when the bytes are not an image
     * of that format (a raw image of the wrong length, a PNG without its header).
     */
    public static function of(string $data, string $format = 'png', int $width = 0, int $height = 0): ?Image
    {
        if ($format === 'rgb' || $format === 'rgba') {
            if ($width <= 0 || $height <= 0 || \strlen($data) !== $width * $height * ($format === 'rgb' ? 3 : 4)) {
                return null;
            }
        } else {
            $size = self::sizeOf($data);
            if ($size === null || ($format !== 'png' && $format !== '' && $size[2] !== $format) || ($format === 'png' && $size[2] !== 'png')) {
                return null;
            }
            [$width, $height, $format] = $size;
        }
        $key = hash('xxh128', $data) . ":$format:$width:$height";
        $image = (self::$interned[$key] ?? null)?->get();
        if ($image !== null) {
            return $image;
        }
        if (\count(self::$interned) >= self::$purgeAt) {
            self::$interned = array_filter(self::$interned, fn(\WeakReference $r) => $r->get() !== null);
            self::$purgeAt = max(64, 2 * \count(self::$interned));
        }
        $image = new Image(++self::$serial, $format, $width, $height, $data);
        self::$interned[$key] = \WeakReference::create($image);
        return $image;
    }

    /**
     * [width, height, format] of encoded image bytes, read from their header (no decoding, GD
     * not needed); null when they are not an image PHP knows.
     *
     * @return array{int, int, string}|null
     */
    public static function sizeOf(string $data): ?array
    {
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n") && \strlen($data) >= 24 && substr($data, 12, 4) === 'IHDR') {
            $size = unpack('Nw/Nh', $data, 16);
            return $size['w'] > 0 && $size['h'] > 0 ? [$size['w'], $size['h'], 'png'] : null;
        }
        if (!str_starts_with($data, "\xff\xd8") && !str_starts_with($data, 'GIF8') && !str_starts_with($data, 'BM')
            && !(str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP')) {
            return null;
        }
        $info = @getimagesizefromstring($data);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }
        $format = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_BMP => 'bmp',
            default => null,
        };
        return $format === null ? null : [$info[0], $info[1], $format];
    }

    /**
     * The image as PNG bytes: the data itself for a PNG, raw pixels encoded (in PHP, no GD
     * needed), other formats converted through GD; null when GD is needed and not loaded.
     */
    public function png(): ?string
    {
        if ($this->format === 'png') {
            return $this->data;
        }
        if ($this->png !== null) {
            return $this->png;
        }
        if ($this->format === 'rgb' || $this->format === 'rgba') {
            return $this->png = self::encodePng($this->data, $this->width, $this->height, $this->format === 'rgba');
        }
        $gd = $this->gd();
        if ($gd === null) {
            return null;
        }
        ob_start();
        imagepng($gd);
        return $this->png = (string) ob_get_clean();
    }

    /**
     * The pixels as RGBA bytes (4 per pixel, rows top to bottom): raw images at once, encoded
     * ones decoded through GD; null when that needs GD and it is not loaded.
     */
    public function rgba(): ?string
    {
        if ($this->decoded) {
            return $this->rgba;
        }
        $this->decoded = true;
        if ($this->format === 'rgba') {
            return $this->rgba = $this->data;
        }
        if ($this->format === 'rgb') {
            $out = '';
            foreach (str_split($this->data, 3 * 1024) as $chunk) {
                $out .= preg_replace('/(...)/s', "\$1\xff", $chunk);
            }
            return $this->rgba = $out;
        }
        $gd = $this->gd();
        if ($gd === null) {
            return null;
        }
        $out = '';
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $c = imagecolorat($gd, $x, $y);
                // (GD's alpha is 0 opaque to 127 transparent)
                $out .= \chr(($c >> 16) & 255) . \chr(($c >> 8) & 255) . \chr($c & 255) . \chr(255 - (int) round((($c >> 24) & 127) * 255 / 127));
            }
        }
        return $this->rgba = $out;
    }

    /** @internal The image as a true-colour GD image, or null without GD (or for bytes GD cannot read). */
    public function gd(): ?\GdImage
    {
        if (!\function_exists('imagecreatefromstring')) {
            return null;
        }
        // (libpng reports a broken image as a warning even under @)
        set_error_handler(static fn(): bool => true);
        try {
            $gd = imagecreatefromstring($this->format === 'rgb' || $this->format === 'rgba' ? (string) $this->png() : $this->data);
        } finally {
            restore_error_handler();
        }
        if ($gd === false) {
            return null;
        }
        if (!imageistruecolor($gd)) {
            imagepalettetotruecolor($gd);
        }
        return $gd;
    }

    /** @internal PNG bytes of raw RGB or RGBA pixels (8 bits a channel, no filtering, zlib-compressed). */
    public static function encodePng(string $pixels, int $width, int $height, bool $alpha): string
    {
        $stride = $width * ($alpha ? 4 : 3);
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0" . substr($pixels, $y * $stride, $stride);
        }
        $chunk = static fn(string $type, string $body): string => pack('N', \strlen($body)) . $type . $body . pack('N', crc32($type . $body));
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, $alpha ? 6 : 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw, 6))
            . $chunk('IEND', '');
    }
}
