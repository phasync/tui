<?php

namespace phasync\Tui\Surface;

use phasync\Tui\Image;
use phasync\Tui\ImageFragment;

/**
 * @internal The image protocols of the emulator: Kitty graphics (APC G) and iTerm2 inline
 * images (OSC 1337 File=). A placement marks the cells it covers with `ImageFragment`s in their
 * styles; the images a program gave ids (Kitty) are kept here, by id, until it deletes them.
 *
 * Kitty, as kitty does it: transmit (`a=t`, in chunks with `m=1` ... `m=0`, PNG `f=100` or raw
 * `f=24`/`f=32`, zlib `o=z`, direct `t=d` only), transmit and display (`a=T`), put (`a=p`),
 * delete (`a=d`: `a`, `i`, `n`, `c`, `p`, `x`, `y`, `r` and their upper-case forms), query
 * (`a=q`), the `OK` and error replies (`q=` quiets them), `i=`/`I=`/`p=` ids, `c=`/`r=` cells,
 * `x=`/`y=`/`w=`/`h=` source rectangles, and the cursor moved after a placement unless `C=1`.
 * Keys it does not know are ignored; the payload is never printed.
 */
final class Graphics
{
    /** The most image bytes kept by id; the oldest go first beyond it (kitty has a quota too). */
    private const QUOTA = 64 << 20;

    /** @var array<int, Image> images by Kitty id, in the order they came */
    private array $images = [];
    /** @var array<int, int> Kitty image numbers (I=) to the id of the newest image with the number */
    private array $numbers = [];
    private int $nextId = 1 << 24;
    /** @var array{keys: array<string, string>, data: string}|null a chunked Kitty transmission in progress */
    private ?array $transfer = null;
    /** @var array{args: array<string, string>, data: string}|null an iTerm2 multipart file in progress */
    private ?array $multipart = null;

    public function __construct(private readonly Emulator $vt)
    {
    }

    /** RIS: every image and transmission forgotten. */
    public function reset(): void
    {
        $this->images = $this->numbers = [];
        $this->transfer = $this->multipart = null;
    }

    // ------------------------------------------------------------------------------------------
    // Kitty

    /** An APC string that starts with 'G': a Kitty graphics command. */
    public function kitty(string $body): void
    {
        $semi = strpos($body, ';');
        $control = $semi === false ? substr($body, 1) : substr($body, 1, $semi - 1);
        $payload = $semi === false ? '' : substr($body, $semi + 1);
        $keys = [];
        foreach (explode(',', $control) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq !== false && $eq > 0) {
                $keys[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
            }
        }
        $more = ($keys['m'] ?? '0') === '1';
        if ($this->transfer !== null) {
            // a chunk of a transmission: its first command had the keys, this one only m (and q)
            $this->transfer['data'] .= $payload;
            if (isset($keys['q'])) {
                $this->transfer['keys']['q'] = $keys['q'];
            }
            if (!$more) {
                ['keys' => $first, 'data' => $data] = $this->transfer;
                $this->transfer = null;
                $this->command($first, $data);
            }
            return;
        }
        $action = $keys['a'] ?? 't';
        if ($more && ($action === 't' || $action === 'T' || $action === 'q')) {
            $this->transfer = ['keys' => $keys, 'data' => $payload];
            return;
        }
        $this->command($keys, $payload);
    }

    /** @param array<string, string> $keys */
    private function command(array $keys, string $payload): void
    {
        $action = $keys['a'] ?? 't';
        switch ($action) {
            case 'q':
                $image = $this->decode($keys, $payload, $error);
                $this->respond($keys, $image === null ? $error : 'OK');
                return;
            case 't':
            case 'T':
                $image = $this->decode($keys, $payload, $error);
                if ($image === null) {
                    $this->respond($keys, $error);
                    return;
                }
                $id = $this->keep($image, $keys);
                if ($id !== null) {
                    $keys['i'] = (string) $id;
                }
                if ($action === 'T') {
                    $this->place($image, $keys);
                }
                $this->respond($keys, 'OK');
                return;
            case 'p':
                $image = $this->find($keys);
                if ($image === null) {
                    $this->respond($keys, 'ENOENT:image not found');
                    return;
                }
                $keys['i'] = (string) $this->idOf($image);
                $this->place($image, $keys);
                $this->respond($keys, 'OK');
                return;
            case 'd':
                $this->delete($keys);
                return;
        }
        // (animation frames, compositions and the like: not offered, and quietly so)
    }

    /**
     * The image a transmission carries, or null with $error set to kitty's error reply.
     *
     * @param array<string, string> $keys
     */
    private function decode(array $keys, string $payload, ?string &$error): ?Image
    {
        $medium = $keys['t'] ?? 'd';
        if ($medium !== 'd') {
            $error = 'EINVAL:only direct transmission (t=d) is supported';
            return null;
        }
        $data = base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $payload) ?? '', true);
        if ($data === false) {
            $error = 'EINVAL:the payload is not base64';
            return null;
        }
        if (($keys['o'] ?? '') === 'z') {
            $data = @gzuncompress($data);
            if ($data === false) {
                $error = 'EINVAL:the payload is not zlib data';
                return null;
            }
        }
        $format = $keys['f'] ?? '32';
        if ($format === '100') {
            $image = Image::of($data, 'png');
            $error = $image === null ? 'EBADPNG:not a PNG image' : null;
            return $image;
        }
        if ($format !== '24' && $format !== '32') {
            $error = 'EINVAL:unknown format';
            return null;
        }
        $image = Image::of($data, $format === '24' ? 'rgb' : 'rgba', (int) ($keys['s'] ?? 0), (int) ($keys['v'] ?? 0));
        $error = $image === null ? 'ENODATA:insufficient image data' : null;
        return $image;
    }

    /**
     * Keep $image under the id (i=) or number (I=) the command gives; return the id, or null
     * for an image without one (shown by its cells only).
     *
     * @param array<string, string> $keys
     */
    private function keep(Image $image, array $keys): ?int
    {
        $id = (int) ($keys['i'] ?? 0);
        $number = (int) ($keys['I'] ?? 0);
        if ($id <= 0 && $number <= 0) {
            return null;
        }
        if ($id <= 0) {
            do {
                $id = $this->nextId++;
            } while (isset($this->images[$id]));
        }
        if ($number > 0) {
            $this->numbers[$number] = $id;
        }
        $old = $this->images[$id] ?? null;
        if ($old !== null && $old !== $image) {
            // a new image under an old id: the old one's placements go with it, as in kitty
            $this->clear(fn(ImageFragment $f) => $f->image === $old);
        }
        unset($this->images[$id]);
        $this->images[$id] = $image;
        $total = 0;
        foreach ($this->images as $kept) {
            $total += \strlen($kept->data);
        }
        foreach ($this->images as $i => $kept) {
            if ($total <= self::QUOTA || $i === $id) {
                break;
            }
            $total -= \strlen($kept->data);
            unset($this->images[$i]);
        }
        return $id;
    }

    /** @param array<string, string> $keys */
    private function find(array $keys): ?Image
    {
        $id = (int) ($keys['i'] ?? 0);
        if ($id <= 0 && isset($keys['I'])) {
            $id = $this->numbers[(int) $keys['I']] ?? 0;
        }
        return $this->images[$id] ?? null;
    }

    private function idOf(Image $image): int
    {
        return (int) array_search($image, $this->images, true);
    }

    /**
     * Place $image at the cursor as a=p does (source rectangle x, y, w, h; c x r cells, either
     * taken from the other and the image's shape when only one is given; placement id p).
     *
     * @param array<string, string> $keys
     */
    private function place(Image $image, array $keys): void
    {
        if (($keys['U'] ?? '') === '1') {
            return;     // (a virtual placement, for Unicode placeholders: not offered)
        }
        $sx = max(0, min($image->width - 1, (int) ($keys['x'] ?? 0)));
        $sy = max(0, min($image->height - 1, (int) ($keys['y'] ?? 0)));
        $sw = (int) ($keys['w'] ?? 0);
        $sh = (int) ($keys['h'] ?? 0);
        $sw = $sw <= 0 ? $image->width - $sx : min($sw, $image->width - $sx);
        $sh = $sh <= 0 ? $image->height - $sy : min($sh, $image->height - $sy);
        [$cw, $ch] = $this->vt->cellSize;
        $cols = (int) ($keys['c'] ?? 0);
        $rows = (int) ($keys['r'] ?? 0);
        if ($cols <= 0 && $rows <= 0) {
            $cols = (int) ceil($sw / $cw);
            $rows = (int) ceil($sh / $ch);
        } elseif ($rows <= 0) {
            $rows = (int) ceil($cols * $cw * $sh / $sw / $ch);
        } elseif ($cols <= 0) {
            $cols = (int) ceil($rows * $ch * $sw / $sh / $cw);
        }
        $placement = (int) ($keys['p'] ?? 0);
        if ($placement > 0) {
            // the same image and placement id again: it moves (the old one goes)
            $this->clear(fn(ImageFragment $f) => $f->image === $image && $f->placement === $placement);
        }
        $this->cells($image, $sx, $sy, $sw, $sh, max(1, $cols), max(1, $rows), $placement, ($keys['C'] ?? '0') !== '1');
    }

    /**
     * Mark the cells of a placement at the cursor: row by row, moving down a line (scrolling at
     * the bottom margin) between rows, then the cursor to the right of its last row, as kitty
     * does; with $move false, the cursor stays and the rows below the screen are cut.
     */
    private function cells(Image $image, int $sx, int $sy, int $sw, int $sh, int $cols, int $rows, int $placement, bool $move): void
    {
        $vt = $this->vt;
        $x0 = $vt->x;
        $y0 = $vt->y;
        // (a cell keeps its colours and attributes under the image, as in kitty, where the image
        // is drawn over the cell; its character is covered)
        for ($r = 0; $r < $rows; $r++) {
            if ($r > 0) {
                if ($move) {
                    $vt->lineFeed();
                } elseif ($vt->y + 1 < $vt->rows) {
                    $vt->y++;
                } else {
                    break;
                }
            }
            $line = $vt->lines[$vt->y];
            $end = min($vt->cols, $x0 + $cols);
            if ($line->chars[$x0] === '' && $x0 > 0) {
                $line->chars[$x0 - 1] = ' ';
            }
            if ($end < $vt->cols && $line->chars[$end] === '') {
                $line->chars[$end] = ' ';
            }
            for ($x = $x0; $x < $end; $x++) {
                $line->chars[$x] = ' ';
                $line->styles[$x] = $line->styles[$x]->withImage(ImageFragment::of($image, $sx, $sy, $sw, $sh, $cols, $rows, $x - $x0, $r, $placement));
            }
            $vt->touch($vt->y);
        }
        $vt->wrapPending = false;
        $vt->lastX = $vt->lastY = -1;
        if (!$move) {
            $vt->x = $x0;
            $vt->y = $y0;
            return;
        }
        $vt->x = $x0 + $cols;
        if ($vt->x >= $vt->cols) {
            $vt->x = 0;
            $vt->lineFeed();
        }
    }

    /**
     * a=d: delete placements (lower case) or placements and the images (upper case) by what d=
     * names: a all, i an id (and p), n a number, c at the cursor, p at cell x,y, x a column, y a
     * row, r an id range x to y.
     *
     * @param array<string, string> $keys
     */
    private function delete(array $keys): void
    {
        $what = $keys['d'] ?? 'a';
        $free = ctype_upper($what);
        $what = strtolower($what);
        $placement = (int) ($keys['p'] ?? 0);
        $match = null;
        $images = [];
        switch ($what) {
            case 'a':
                $match = fn(ImageFragment $f) => true;
                $images = $free ? array_keys($this->images) : [];
                break;
            case 'i':
            case 'n':
                $id = $what === 'i' ? (int) ($keys['i'] ?? 0) : ($this->numbers[(int) ($keys['I'] ?? 0)] ?? 0);
                $image = $this->images[$id] ?? null;
                if ($image === null) {
                    return;
                }
                $match = fn(ImageFragment $f) => $f->image === $image && ($placement === 0 || $f->placement === $placement);
                $images = $free && $placement === 0 ? [$id] : [];
                break;
            case 'c':
            case 'p':
                $x = $what === 'c' ? $this->vt->x : (int) ($keys['x'] ?? 1) - 1;
                $y = $what === 'c' ? $this->vt->y : (int) ($keys['y'] ?? 1) - 1;
                $at = ($this->vt->lines[$y] ?? null)?->styles[$x]?->image ?? null;
                if ($at === null) {
                    return;
                }
                $match = fn(ImageFragment $f) => $f->samePlacement($at);
                $images = $free ? [$this->idOf($at->image)] : [];
                break;
            case 'x':
            case 'y':
                $n = (int) ($keys[$what] ?? 1) - 1;
                $hit = [];
                foreach ($this->vt->lines as $y => $line) {
                    foreach ($line->styles as $x => $style) {
                        if ($style->image !== null && ($what === 'x' ? $x : $y) === $n) {
                            $hit[] = $style->image;
                        }
                    }
                }
                $match = function (ImageFragment $f) use ($hit): bool {
                    foreach ($hit as $h) {
                        if ($f->samePlacement($h)) {
                            return true;
                        }
                    }
                    return false;
                };
                if ($free) {
                    $images = array_map(fn(ImageFragment $f) => $this->idOf($f->image), $hit);
                }
                break;
            case 'r':
                $from = (int) ($keys['x'] ?? 0);
                $to = (int) ($keys['y'] ?? 0);
                $chosen = array_filter($this->images, fn(int $id) => $id >= $from && $id <= $to, ARRAY_FILTER_USE_KEY);
                $match = fn(ImageFragment $f) => \in_array($f->image, $chosen, true);
                $images = $free ? array_keys($chosen) : [];
                break;
            default:
                return;     // (z-index and animation frames: not offered)
        }
        $this->clear($match);
        foreach ($images as $id) {
            unset($this->images[$id]);
        }
        if ($images !== []) {
            $this->numbers = array_filter($this->numbers, fn(int $id) => isset($this->images[$id]));
        }
    }

    /** Blank the cells of the screen whose fragment $match accepts. @param \Closure(ImageFragment): bool $match */
    private function clear(\Closure $match): void
    {
        $vt = $this->vt;
        foreach ($vt->lines as $y => $line) {
            $hit = false;
            foreach ($line->styles as $x => $style) {
                if ($style->image !== null && $match($style->image)) {
                    $line->chars[$x] = ' ';
                    $line->styles[$x] = $style->withImage(null);
                    $hit = true;
                }
            }
            if ($hit) {
                $vt->touch($y);
            }
        }
    }

    /**
     * The reply to a command, when it named an image (i= or I=) and q= allows: OK unless q is 1
     * or 2, errors unless q is 2. A query (a=q) is answered by the same rule.
     *
     * @param array<string, string> $keys
     */
    private function respond(array $keys, string $message): void
    {
        $quiet = (int) ($keys['q'] ?? 0);
        if ((!isset($keys['i']) && !isset($keys['I'])) || $quiet >= 2 || ($message === 'OK' && $quiet === 1)) {
            return;
        }
        $ids = [];
        if (isset($keys['i'])) {
            $ids[] = 'i=' . (int) $keys['i'];
        }
        if (isset($keys['I'])) {
            $ids[] = 'I=' . (int) $keys['I'];
        }
        if (isset($keys['p']) && (int) $keys['p'] > 0) {
            $ids[] = 'p=' . (int) $keys['p'];
        }
        $this->vt->reply("\e_G" . implode(',', $ids) . ";$message\e\\");
    }

    // ------------------------------------------------------------------------------------------
    // iTerm2

    /** The value of an OSC 1337 (after "1337;"): File=, or the MultipartFile= / FilePart= / FileEnd forms. */
    public function iterm(string $value): void
    {
        if (str_starts_with($value, 'File=')) {
            $colon = strpos($value, ':');
            if ($colon === false) {
                return;
            }
            $this->file(self::args(substr($value, 5, $colon - 5)), substr($value, $colon + 1));
        } elseif (str_starts_with($value, 'MultipartFile=')) {
            $this->multipart = ['args' => self::args(substr($value, 14)), 'data' => ''];
        } elseif (str_starts_with($value, 'FilePart=')) {
            if ($this->multipart !== null) {
                $this->multipart['data'] .= substr($value, 9);
            }
        } elseif ($value === 'FileEnd') {
            if ($this->multipart !== null) {
                ['args' => $args, 'data' => $data] = $this->multipart;
                $this->multipart = null;
                $this->file($args, $data);
            }
        }
    }

    /** @return array<string, string> */
    private static function args(string $list): array
    {
        $args = [];
        foreach (explode(';', $list) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $args[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
            }
        }
        return $args;
    }

    /**
     * A file sent inline (inline=1; a download otherwise, which a terminal in memory ignores):
     * placed at the cursor over width x height cells (cells, `Npx`, `N%` of the screen, or
     * `auto` from the image's pixel size and the cell size), the image's shape kept unless
     * preserveAspectRatio=0, narrowed to the screen's width. The cursor ends right of its
     * bottom-right cell, as in iTerm2 (imgcat prints a line feed after it).
     *
     * @param array<string, string> $args
     */
    private function file(array $args, string $payload): void
    {
        if (($args['inline'] ?? '0') !== '1') {
            return;
        }
        $data = base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $payload) ?? '', true);
        if ($data === false) {
            return;
        }
        $image = Image::of($data, '');
        if ($image === null) {
            return;
        }
        $vt = $this->vt;
        [$cw, $ch] = $vt->cellSize;
        $w = self::length($args['width'] ?? 'auto', $cw, $vt->cols);
        $h = self::length($args['height'] ?? 'auto', $ch, $vt->rows);
        $pw = $image->width;
        $ph = $image->height;
        $keep = ($args['preserveAspectRatio'] ?? '1') !== '0';
        if ($w === null && $h === null) {
            [$w, $h] = [$pw, $ph];
        } elseif ($h === null) {
            $h = $w * $ph / $pw;
        } elseif ($w === null) {
            $w = $h * $pw / $ph;
        } elseif ($keep) {
            $scale = min($w / $pw, $h / $ph);
            [$w, $h] = [$pw * $scale, $ph * $scale];
        }
        $room = ($vt->cols - $vt->x) * $cw;
        if ($w > $room) {
            $h = $keep ? $h * $room / $w : $h;
            $w = $room;
        }
        $cols = max(1, (int) ceil($w / $cw - 1e-9));
        $rows = max(1, (int) ceil($h / $ch - 1e-9));
        $this->cells($image, 0, 0, $pw, $ph, $cols, $rows, 0, true);
    }

    /** An iTerm2 width or height in pixels: N cells, Npx, N% of the screen; null for auto. */
    private static function length(string $spec, int $cell, int $cells): ?float
    {
        if ($spec === '' || $spec === 'auto') {
            return null;
        }
        if (str_ends_with($spec, 'px')) {
            return max(1.0, (float) $spec);
        }
        if (str_ends_with($spec, '%')) {
            return max(1.0, (float) $spec * $cells * $cell / 100);
        }
        return max(1.0, (float) $spec * $cell);
    }
}
