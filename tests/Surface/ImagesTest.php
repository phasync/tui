<?php

use phasync\Tui\Ansi;
use phasync\Tui\Image;
use phasync\Tui\ImageFragment;
use phasync\Tui\Surface;

/** A PNG of $w x $h pixels in one colour (made without GD). */
function png(int $w, int $h, string $rgb = "\xff\x00\x00"): string
{
    return Image::encodePng(str_repeat($rgb, $w * $h), $w, $h, false);
}

/** A surface that collects its replies in $replies. */
function imaging(int $cols, int $rows, ?string &$replies): Surface
{
    $replies = '';
    $s = new Surface($cols, $rows, 100);
    $s->input->pipe(function (string $b) use (&$replies): void {
        $replies .= $b;
    });
    return $s;
}

/** The image fragment of each cell as "col,row" (or '.' for none), rows joined with '|'. */
function fragments(Surface $s): string
{
    $out = [];
    for ($y = 0; $y < $s->rows(); $y++) {
        $row = [];
        for ($x = 0; $x < $s->cols(); $x++) {
            $f = $s->cell($x, $y)->style->image;
            $row[] = $f === null ? '.' : "$f->col,$f->row";
        }
        $out[] = implode(' ', $row);
    }
    return implode('|', $out);
}

describe('Kitty graphics', function () {
    it('transmits and displays a PNG at the cursor, moving the cursor right of its last row, and answers OK', function () {
        $s = imaging(10, 5, $replies);
        $s->write("ab\e_Ga=T,f=100,i=7;" . base64_encode(png(24, 32)) . "\e\\cd");
        // 24x32 pixels in cells of 8x16: 3 columns, 2 rows
        expect(fragments($s))->toBe('. . 0,0 1,0 2,0 . . . . .|. . 0,1 1,1 2,1 . . . . .|' . implode('|', array_fill(0, 3, implode(' ', array_fill(0, 10, '.')))))
            ->and(rows($s))->toBe('ab        |     cd   |          |          |          ')
            ->and($replies)->toBe("\e_Gi=7;OK\e\\");
        $f = $s->cell(3, 1)->style->image;
        expect($f->image->width)->toBe(24)->and($f->image->format)->toBe('png')->and($f->pixels())->toBe([8, 16, 8, 16]);
    });

    it('answers only commands that name an image, and q=1 keeps the OK back, q=2 the errors too', function () {
        $s = imaging(10, 5, $replies);
        $s->write("\e_Ga=T,f=100;" . base64_encode(png(8, 16)) . "\e\\");
        $s->write("\e_Ga=T,f=100,i=1,q=1;" . base64_encode(png(8, 16)) . "\e\\");
        $s->write("\e_Ga=T,f=100,i=2,q=2;bm90IGEgcG5n\e\\");
        expect($replies)->toBe('');
        $s->write("\e_Ga=T,f=100,i=3,q=1;bm90IGEgcG5n\e\\");
        expect($replies)->toBe("\e_Gi=3;EBADPNG:not a PNG image\e\\");
    });

    it('takes a transmission in chunks (m=1 ... m=0), keyed by the first, and places it later with a=p', function () {
        $s = imaging(10, 5, $replies);
        $b64 = base64_encode(png(16, 16));
        [$a, $b, $c] = str_split($b64, (int) ceil(\strlen($b64) / 3));
        $s->write("\e_Ga=t,f=100,i=5,m=1;$a\e\\\e_Gm=1;$b\e\\");
        expect($replies)->toBe('');
        $s->write("\e_Gm=0;$c\e\\");
        expect($replies)->toBe("\e_Gi=5;OK\e\\")
            ->and(fragments($s))->not->toContain('0,0');      // (transmitted, not shown)
        $s->write("\e[2;3H\e_Ga=p,i=5,q=1\e\\");
        expect($s->cell(2, 1)->style->image?->col)->toBe(0)->and($s->cell(3, 1)->style->image?->col)->toBe(1)
            ->and(cursorAt($s))->toBe([4, 1]);
    });

    it('takes raw RGB and RGBA pixels of the size s x v, zlib-compressed with o=z, and refuses data of the wrong length', function () {
        $s = imaging(10, 5, $replies);
        $s->write("\e_Ga=T,f=24,s=8,v=16,i=1,C=1;" . base64_encode(str_repeat("\x01\x02\x03", 128)) . "\e\\");
        expect($s->cell(0, 0)->style->image->image->format)->toBe('rgb');
        $s->write("\e[1;3H\e_Ga=T,f=32,s=8,v=16,o=z,i=2,C=1;" . base64_encode(gzcompress(str_repeat("\x01\x02\x03\xff", 128))) . "\e\\");
        expect($s->cell(2, 0)->style->image->image->format)->toBe('rgba');
        $s->write("\e_Ga=T,f=24,s=8,v=16,i=3;" . base64_encode('short') . "\e\\");
        expect($replies)->toBe("\e_Gi=1;OK\e\\\e_Gi=2;OK\e\\\e_Gi=3;ENODATA:insufficient image data\e\\");
    });

    it('refuses the media it does not read (files, shared memory) with an error, showing nothing', function () {
        $s = imaging(10, 5, $replies);
        $s->write("\e_Ga=T,t=f,f=100,i=9;" . base64_encode('/tmp/x.png') . "\e\\");
        expect($replies)->toStartWith("\e_Gi=9;EINVAL:")->and(fragments($s))->not->toContain(',');
    });

    it('shows a source rectangle (x, y, w, h) over c x r cells, the rows from the shape when only c is given', function () {
        $s = imaging(12, 6, $replies);
        $s->write("\e_Ga=T,f=100,x=8,y=0,w=16,h=32,c=4,r=2,C=1,q=2;" . base64_encode(png(32, 32)) . "\e\\");
        $f = $s->cell(1, 1)->style->image;
        expect([$f->x, $f->y, $f->width, $f->height, $f->cols, $f->rows])->toBe([8, 0, 16, 32, 4, 2])
            ->and($f->pixels())->toBe([12, 16, 4, 16]);
        // c=4 of a square 32x32 image: 32 pixels wide, so 32 high: 2 rows of 16
        $s->write("\e[4;1H\e_Ga=T,f=100,c=4,C=1,q=2;" . base64_encode(png(32, 32)) . "\e\\");
        expect($s->cell(3, 4)->style->image?->row)->toBe(1)->and($s->cell(0, 5)->style->image)->toBeNull();
    });

    it('keeps the cursor with C=1 and cuts the rows below the screen; without it, scrolls them into view', function () {
        $s = imaging(6, 3, $replies);
        $s->write("top\r\n\r\n\e_Ga=T,f=100,C=1,q=2;" . base64_encode(png(16, 48)) . "\e\\");
        expect(cursorAt($s))->toBe([0, 2])->and(fragments($s))->toBe('. . . . . .|. . . . . .|0,0 1,0 . . . .');
        $s->write("\e[2J\e[H");
        $s->write("top\r\n\r\n\e_Ga=T,f=100,q=2;" . base64_encode(png(16, 48)) . "\e\\");
        // three rows from the bottom row: the screen scrolled two, the image's last row at the bottom
        expect(fragments($s))->toBe('0,0 1,0 . . . .|0,1 1,1 . . . .|0,2 1,2 . . . .')
            ->and(cursorAt($s))->toBe([2, 2])
            ->and($s->line(-2))->toBe('top');
    });

    it('wraps the cursor to the next line when the image ends at the right edge', function () {
        $s = imaging(4, 3, $replies);
        $s->write("\e[1;3H\e_Ga=T,f=100,q=2;" . base64_encode(png(16, 16)) . "\e\\");
        expect(cursorAt($s))->toBe([0, 1]);
    });

    it('moves a placement put again with the same placement id (p=), leaving other placements of the image', function () {
        $s = imaging(10, 4, $replies);
        $s->write("\e_Ga=t,f=100,i=1,q=2;" . base64_encode(png(8, 16)) . "\e\\");
        $s->write("\e[1;1H\e_Ga=p,i=1,p=1,q=2\e\\\e[1;5H\e_Ga=p,i=1,p=2,q=2\e\\");
        $s->write("\e[3;8H\e_Ga=p,i=1,p=1,q=2\e\\");
        expect(fragments($s))->toBe('. . . . 0,0 . . . . .|. . . . . . . . . .|. . . . . . . 0,0 . .|. . . . . . . . . .');
    });

    it('deletes placements by all, id, id and placement, number, cursor, cell, column, row and id range', function () {
        $place = function (): Surface {
            $s = imaging(12, 3, $replies);
            $s->write("\e_Ga=t,f=100,i=1,q=2;" . base64_encode(png(16, 16)) . "\e\\");
            $s->write("\e_Ga=t,f=100,i=2,I=40,q=2;" . base64_encode(png(16, 16, "\x00\xff\x00")) . "\e\\");
            $s->write("\e[1;1H\e_Ga=p,i=1,p=1,q=2\e\\\e[1;4H\e_Ga=p,i=1,p=2,q=2\e\\\e[1;7H\e_Ga=p,i=2,q=2\e\\\e[3;10H\e_Ga=p,i=2,p=3,q=2\e\\");
            return $s;
        };
        $shown = function (Surface $s): string {
            $out = '';
            for ($y = 0; $y < 3; $y++) {
                for ($x = 0; $x < 12; $x++) {
                    $out .= (string) ($s->cell($x, $y)->style->image?->image->width === null ? '.' : ($s->cell($x, $y)->style->image->placement ?: 'x'));
                }
                $out .= '|';
            }
            return $out;
        };
        expect($shown($place()))->toBe('11.22.xx....|............|.........33.|');
        $cases = [
            'd=a' => '............|............|............|',
            'd=i,i=1' => '......xx....|............|.........33.|',
            'd=i,i=1,p=2' => '11....xx....|............|.........33.|',
            'd=n,I=40' => '11.22.......|............|............|',
            'd=p,x=5,y=1' => '11....xx....|............|.........33.|',
            'd=x,x=11' => '11.22.xx....|............|............|',
            'd=y,y=1' => '............|............|.........33.|',
            'd=r,x=2,y=9' => '11.22.......|............|............|',
        ];
        foreach ($cases as $keys => $want) {
            $s = $place();
            $s->write("\e_Ga=d,$keys\e\\");
            expect($shown($s))->toBe($want, $keys);
        }
        $s = $place();
        $s->write("\e[1;2H\e_Ga=d,d=c\e\\");
        expect($shown($s))->toBe('...22.xx....|............|.........33.|');
    });

    it('frees the image with an upper-case delete: a later put does not find it', function () {
        $s = imaging(10, 3, $replies);
        $s->write("\e_Ga=T,f=100,i=4,q=2;" . base64_encode(png(8, 16)) . "\e\\\e_Ga=d,d=i,i=4\e\\\e_Ga=p,i=4\e\\");
        expect($replies)->toBe("\e_Gi=4;OK\e\\");       // (the lower-case delete keeps the image)
        $s->write("\e_Ga=d,d=I,i=4\e\\\e_Ga=p,i=4\e\\");
        expect($replies)->toEndWith("\e_Gi=4;ENOENT:image not found\e\\");
    });

    it('gives an image sent with a number (I=) an id, and answers with both', function () {
        $s = imaging(10, 3, $replies);
        $s->write("\e_Ga=t,f=100,I=13;" . base64_encode(png(8, 16)) . "\e\\");
        expect($replies)->toMatch('/^\e_Gi=\d+,I=13;OK\e\\\\$/');
        $s->write("\e_Ga=p,I=13,q=2\e\\");
        expect($s->cell(0, 0)->style->image)->not->toBeNull();
    });

    it('answers a query (a=q) about an image it could show, without keeping it', function () {
        $s = imaging(10, 3, $replies);
        $s->write("\e_Gi=31,s=1,v=1,a=q,t=d,f=24;AAAA\e\\");
        expect($replies)->toBe("\e_Gi=31;OK\e\\");
        $s->write("\e_Ga=p,i=31\e\\");
        expect($replies)->toEndWith("\e_Gi=31;ENOENT:image not found\e\\")->and(rows($s))->toBe(str_repeat(' ', 10) . '|' . str_repeat(' ', 10) . '|' . str_repeat(' ', 10));
    });

    it('ignores keys it does not know, animation commands and APC strings that are not graphics, printing none of it', function () {
        $s = imaging(10, 3, $replies);
        $s->write("\e_Ga=T,f=100,q=2,z=-1,X=3,Y=2,foo=bar;" . base64_encode(png(8, 16)) . "\e\\");
        $s->write("\e_Ga=f,i=1,r=1;AAAA\e\\\e_Ga=a,i=1,s=2\e\\\e_Hello\e\\x");
        expect($s->cell(0, 0)->style->image)->not->toBeNull()->and(rows($s))->toBe(' x        |          |          ');
    });

    it('lets a glyph replace an image cell, erasing drop it, and scrolling move it with its cell', function () {
        $s = imaging(6, 3, $replies);
        $s->write("\e_Ga=T,f=100,c=4,r=2,C=1,q=2;" . base64_encode(png(32, 32)) . "\e\\");
        $s->write("\e[1;2HZ");
        expect($s->cell(1, 0)->style->image)->toBeNull()->and($s->cell(1, 0)->char)->toBe('Z')
            ->and($s->cell(2, 0)->style->image?->col)->toBe(2);
        $s->write("\e[1;3H\e[K");
        expect($s->cell(3, 0)->style->image)->toBeNull()->and($s->cell(0, 0)->style->image?->col)->toBe(0);
        $s->write("\e[2;1H\e[2X");
        expect($s->cell(1, 1)->style->image)->toBeNull()->and($s->cell(2, 1)->style->image?->col)->toBe(2);
        $s->write("\e[3;1H\n");      // (a line feed at the bottom scrolls the image up a row)
        expect($s->cell(2, 0)->style->image?->row)->toBe(1)->and($s->cell(3, 0)->style->image?->col)->toBe(3)
            ->and($s->cell(0, -1)->style->image?->row)->toBe(0);
        $s->write("\e[2J");
        expect(fragments($s))->not->toContain(',');
    });

    it('takes an image whose sequence is cut across writes, also one split into many small chunks', function () {
        $s = imaging(10, 3, $replies);
        $seq = "\e_Ga=T,f=100,i=2;" . base64_encode(png(40, 40)) . "\e\\";
        foreach (str_split($seq, 7) as $piece) {
            $s->write($piece);
        }
        expect($replies)->toBe("\e_Gi=2;OK\e\\")->and($s->cell(4, 2)->style->image?->row)->toBe(2);
    });

    it('drops the placements of an image sent again under the same id with other bytes', function () {
        $s = imaging(10, 3, $replies);
        $s->write("\e_Ga=T,f=100,i=1,q=2;" . base64_encode(png(8, 16)) . "\e\\");
        $s->write("\e[1;5H\e_Ga=T,f=100,i=1,q=2;" . base64_encode(png(8, 16, "\x00\x00\xff")) . "\e\\");
        expect($s->cell(0, 0)->style->image)->toBeNull()->and($s->cell(4, 0)->style->image)->not->toBeNull();
    });

    it('measures images in the cell size (CSI 16 t tells it), which a hosted surface takes from its host', function () {
        $s = imaging(10, 4, $replies);
        $s->write("\e[16t\e[14t");
        expect($replies)->toBe("\e[6;16;8t\e[4;64;80t");
        $s->setCellSize(16, 32);
        $s->write("\e_Ga=T,f=100,q=2;" . base64_encode(png(32, 32)) . "\e\\");
        expect(cursorAt($s))->toBe([2, 0]);
        $child = new Surface(5, 2);
        $s->host($child, 0, 0);
        expect($child->cellSize())->toBe([16, 32]);
        $s->setCellSize(10, 20);
        expect($child->cellSize())->toBe([10, 20]);
    });

    it('forgets an image without an id once no cell shows it', function () {
        $s = imaging(10, 3, $replies);
        $s->write("\e_Ga=T,f=100,q=2;" . base64_encode(png(9, 9, "\x01\x02\x03")) . "\e\\");
        $ref = WeakReference::create($s->cell(0, 0)->style->image->image);
        expect($ref->get())->not->toBeNull();
        $s->write("\e[2J");
        gc_collect_cycles();
        expect($ref->get())->toBeNull();
    });

    it('makes the same fragments for the same placement, so two writes of one image compare with ===', function () {
        $a = vt(Ansi::image(png(16, 16), 2, 1), 4, 1);
        $b = vt(Ansi::image(png(16, 16), 2, 1), 4, 1);
        expect($a->cell(1, 0)->style)->toBe($b->cell(1, 0)->style)
            ->and(ImageFragment::exist())->toBeTrue();
    });

    it('takes a transmission in the form kitty +kitten icat sends (centred with CUF, f=100 in chunks of 4096 with m=, q=2, a line feed after)', function () {
        $s = imaging(40, 12, $replies);
        $s->write(file_get_contents(__DIR__ . '/../fixtures/images/icat.bytes'));
        // 120x90 pixels in 8x16 cells: 15 columns, 6 rows, centred in 40: 12 columns in
        expect($s->cell(12, 0)->style->image?->col)->toBe(0)->and($s->cell(26, 5)->style->image?->col)->toBe(14)
            ->and($s->cell(26, 5)->style->image?->row)->toBe(5)->and($s->cell(11, 0)->style->image)->toBeNull()
            ->and($s->cell(27, 0)->style->image)->toBeNull()
            ->and(cursorAt($s))->toBe([0, 6])->and($replies)->toBe('');
    });
});

describe('iTerm2 inline images', function () {
    it('places a file of auto size from its pixels and the cell size, the cursor right of its last row', function () {
        $s = imaging(20, 6, $replies);
        $s->write("x\e]1337;File=name=" . base64_encode('a.png') . ';size=99;inline=1:' . base64_encode(png(20, 40)) . "\x07");
        // 20x40 pixels: 3 columns (2.5 rounded up), 3 rows (2.5)
        expect($s->cell(1, 0)->style->image?->col)->toBe(0)->and($s->cell(3, 2)->style->image?->col)->toBe(2)
            ->and($s->cell(4, 2)->style->image)->toBeNull()->and($s->cell(1, 3)->style->image)->toBeNull()
            ->and(cursorAt($s))->toBe([4, 2]);
    });

    it('sizes it in cells, pixels or percent of the screen, keeping its shape unless preserveAspectRatio=0', function () {
        $place = function (string $args): array {
            $s = imaging(20, 10, $replies);
            $s->write("\e]1337;File=inline=1;$args:" . base64_encode(png(32, 32)) . "\e\\");
            $f = $s->cell(0, 0)->style->image;
            return [$f->cols, $f->rows];
        };
        expect($place('width=4'))->toBe([4, 2])          // 32 px wide, so 32 high: 2 rows of 16
            ->and($place('height=4'))->toBe([8, 4])      // 64 px high, so 64 wide: 8 columns of 8
            ->and($place('width=64px'))->toBe([8, 4])
            ->and($place('width=50%'))->toBe([10, 5])
            ->and($place('width=6;height=6'))->toBe([6, 3])      // fitted in 48x96: 48x48
            ->and($place('width=6;height=6;preserveAspectRatio=0'))->toBe([6, 6])
            ->and($place('width=40'))->toBe([20, 10]);   // narrowed to the screen
    });

    it('ignores a file that is not inline (a download), and takes the multipart form', function () {
        $s = imaging(10, 4, $replies);
        $s->write("\e]1337;File=inline=0:" . base64_encode(png(8, 16)) . "\x07");
        expect($s->cell(0, 0)->style->image)->toBeNull();
        $b64 = base64_encode(png(8, 16));
        $s->write("\e]1337;MultipartFile=inline=1;width=2\x07\e]1337;FilePart=" . substr($b64, 0, 20) . "\x07\e]1337;FilePart=" . substr($b64, 20) . "\x07\e]1337;FileEnd\x07");
        expect($s->cell(1, 0)->style->image?->col)->toBe(1);
    });

    it('takes JPEG and GIF files, sized from their headers', function () {
        if (!function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is needed to make a JPEG');
        }
        $gd = imagecreatetruecolor(16, 32);
        ob_start();
        imagejpeg($gd);
        $jpeg = ob_get_clean();
        $s = imaging(10, 4, $replies);
        $s->write("\e]1337;File=inline=1:" . base64_encode($jpeg) . "\x07");
        expect($s->cell(0, 0)->style->image?->image->format)->toBe('jpeg')->and($s->cell(1, 1)->style->image?->row)->toBe(1);
    });

    it('takes the output of imgcat (the file name and size given, a line feed after)', function () {
        $s = imaging(40, 12, $replies);
        $s->write(file_get_contents(__DIR__ . '/../fixtures/images/imgcat.bytes'));
        // 120x90 pixels: 15 columns, 6 rows (5.6), at the left; the line feed after the last row
        expect($s->cell(0, 0)->style->image?->col)->toBe(0)->and($s->cell(14, 5)->style->image?->row)->toBe(5)
            ->and($s->cell(15, 0)->style->image)->toBeNull()->and(cursorAt($s))->toBe([0, 6]);
    });
});

describe('Sixel', function () {
    /** The RGBA bytes of pixel ($x, $y) of the image a cell shows. */
    $pixel = fn(Surface $s, int $cx, int $cy, int $x, int $y): string
        => bin2hex(substr($s->cell($cx, $cy)->style->image->image->rgba(), 4 * ($y * $s->cell($cx, $cy)->style->image->image->width + $x), 4));

    it('draws a sixel image over the cells its pixels cover, the cursor below it in the column it started', function () use ($pixel) {
        $s = imaging(10, 5, $replies);
        // 12x20 pixels (8x16 cells: 2 columns, 2 rows): six columns of colour 1 (red), then of 2 (blue), in
        // three bands of six rows and one of two ('B': the top two pixels)
        $band = '#1!6~#2!6~';
        $s->write("ab\eP0;1q\"1;1;12;20#1;2;100;0;0#2;2;0;0;100$band-$band-$band-#1!6B#2!6B\e\\X");
        expect(fragments($s))->toBe('. . 0,0 1,0 . . . . . .|. . 0,1 1,1 . . . . . .|. . . . . . . . . .|. . . . . . . . . .|. . . . . . . . . .')
            ->and($s->line(2))->toBe('  X')
            ->and($s->cell(2, 0)->style->image->image->width)->toBe(16)->and($s->cell(2, 0)->style->image->image->height)->toBe(32)
            ->and($pixel($s, 2, 0, 0, 0))->toBe('ff0000ff')->and($pixel($s, 2, 0, 11, 19))->toBe('0000ffff')
            // (the rest of the last column and row of cells is transparent: the cell shows through, as on a terminal)
            ->and($pixel($s, 2, 0, 12, 0))->toBe('00000000')->and($pixel($s, 2, 0, 0, 20))->toBe('00000000');
    });

    it('repeats, returns to the left of the band, moves down a band, and paints what P2 0 leaves in the background colour', function () use ($pixel) {
        $s = imaging(4, 2, $replies);
        // '@' is the top pixel only, 'A' the second; '$' paints over the same band; 3 pixels wide
        $s->write("\ePq#1;2;0;100;0!3@\$#2;2;0;0;100?A-#1N\e\\");
        $at = fn(int $x, int $y) => $pixel($s, 0, 0, $x, $y);
        expect($at(0, 0))->toBe('00ff00ff')->and($at(2, 0))->toBe('00ff00ff')->and($at(3, 0))->toBe('00000000')
            ->and($at(0, 1))->toBe('000000ff')->and($at(1, 1))->toBe('0000ffff')
            // 'N' (15 = the top four pixels) in the second band
            ->and($at(0, 6))->toBe('00ff00ff')->and($at(0, 9))->toBe('00ff00ff')->and($at(1, 6))->toBe('000000ff')
            // (below the 10 rows painted, to the cell's edge: transparent)
            ->and($at(0, 10))->toBe('00000000')
            ->and($s->cell(0, 0)->style->image->image->height)->toBe(16);
    });

    it('defines colours in RGB and in DEC HLS (hue with blue at 0), and starts with the VT340 palette', function () use ($pixel) {
        $colour = function (string $define) use ($pixel): string {
            $s = imaging(2, 2, $replies);
            $s->write("\eP0;1q$define~\e\\");
            return $pixel($s, 0, 0, 0, 0);
        };
        expect($colour('#5;2;100;50;0'))->toBe('ff8000ff')
            ->and($colour('#5;1;0;50;100'))->toBe('0000ffff')      // blue
            ->and($colour('#5;1;120;50;100'))->toBe('ff0000ff')    // red
            ->and($colour('#5;1;240;50;100'))->toBe('00ff00ff')    // green
            ->and($colour('#5;1;0;100;0'))->toBe('ffffffff')
            ->and($colour('#2'))->toBe('cc2121ff')                  // the VT340's register 2
            ->and($colour(''))->toBe('000000ff');                   // register 0 at the start
    });

    it('leaves the cursor right of the image with mode 8452, and draws at the top left without moving it with DECSDM', function () {
        $s = imaging(10, 4, $replies);
        $s->write("\e[?8452h\e[2;3H\ePq\"1;1;16;32!16~-!16~-!16~-!16~-!16~-!16B\e\\");
        expect(cursorAt($s))->toBe([4, 2])->and($s->cell(2, 1)->style->image?->col)->toBe(0);
        $s = imaging(10, 4, $replies);
        $s->write("\e[?80h\e[3;5H\ePq!8~\e\\");
        expect(cursorAt($s))->toBe([4, 2])->and($s->cell(0, 0)->style->image?->col)->toBe(0)->and($s->cell(4, 2)->style->image)->toBeNull();
    });

    it('scrolls at the bottom of the screen like text, and cuts what is wider than the screen', function () {
        $s = imaging(4, 3, $replies);
        $s->write("top\e[3;1H\ePq\"1;1;48;32!48~-!48~-!48~-!48~-!48~-!48B\e\\");
        // two rows of cells from the last line: one line up, and the cursor below scrolls another
        expect(fragments($s))->toBe('0,0 1,0 2,0 3,0|0,1 1,1 2,1 3,1|. . . .')->and(cursorAt($s))->toBe([0, 2])
            ->and($s->cell(0, 0)->style->image->cols)->toBe(6)->and($s->line(-2))->toBe('top');
    });

    it('is a DCS in either form, across writes, and nothing when cancelled or not sixel', function () {
        $s = imaging(4, 2, $replies);
        $s->write("\u{90}q~~\u{9c}");
        expect($s->cell(0, 0)->style->image)->not->toBeNull();
        $s = imaging(4, 2, $replies);
        $s->write("\ePq#1;2;100;0;0");
        $s->write('!8~');
        expect($s->cell(0, 0)->style->image)->toBeNull();
        $s->write("\e\\");
        expect($s->cell(0, 0)->style->image)->not->toBeNull();
        $s = imaging(4, 2, $replies);
        $s->write("\ePq~~\x18x\eP1\$qm\e\\\eP+q544e\e\\");
        expect(fragments($s))->toBe('. . . .|. . . .')->and($s->line(0))->toBe('x');
    });

    it('says it has Sixel in its device attributes, and answers XTSMGRAPHICS', function () {
        $s = imaging(80, 24, $replies);
        $s->write("\e[c\e[?1;1S\e[?2;1S\e[?3;1S\e[?1;9S");
        expect($replies)->toBe("\e[?62;4;22c\e[?1;0;1024S\e[?2;0;640;384S\e[?3;1;0S\e[?1;2;0S");
    });

    it('takes the output of ImageMagick (convert image.png sixel:-)', function () use ($pixel) {
        $s = imaging(40, 12, $replies);
        $s->write(file_get_contents(__DIR__ . '/../fixtures/images/sixel.bytes'));
        // 120x90 pixels: 15 columns, 6 rows (5.6); the line feed after it from the row below
        $f = $s->cell(0, 0)->style->image;
        expect($f?->cols)->toBe(15)->and($f->rows)->toBe(6)->and($s->cell(14, 5)->style->image?->row)->toBe(5)
            ->and($s->cell(15, 0)->style->image)->toBeNull()->and(cursorAt($s))->toBe([0, 7]);
        // the gradient: red grows to the right, green downwards, blue 128 (in ImageMagick's palette)
        [$r0, $g0, $b0] = array_map('hexdec', str_split(substr($pixel($s, 0, 0, 0, 0), 0, 6), 2));
        [$r1, $g1] = array_map('hexdec', str_split(substr($pixel($s, 0, 0, 119, 89), 0, 4), 2));
        expect($r0)->toBeLessThan(16)->and($g0)->toBeLessThan(16)->and(abs($b0 - 128))->toBeLessThan(16)
            ->and(abs($r1 - 238))->toBeLessThan(16)->and(abs($g1 - 178))->toBeLessThan(16);
    });
});

describe('Images of hosted programs', function () {
    it('shows the image a program in a pty sends, by any of the protocols, in its pane on the host', function () {
        \fiber\run(function () {
            foreach (['icat', 'imgcat', 'sixel'] as $name) {
                $root = new Surface(60, 12);
                $pane = new Surface(40, 10);
                $root->host($pane, 5, 1);
                $pty = \phasync\Tui\Pty::start(['cat', __DIR__ . "/../fixtures/images/$name.bytes"], $pane);
                expect($pty->wait(5))->toBe(0);
                $x = $name === 'icat' ? 12 : 0;
                expect($root->cell(5 + $x, 1)->style->image?->col)->toBe(0, $name)
                    ->and($root->cell(5 + $x + 14, 6)->style->image?->row)->toBe(5, $name)
                    ->and($root->cell(4 + $x, 1)->style->image)->toBeNull($name);
            }
        });
    });
});
