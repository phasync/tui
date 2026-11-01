<?php

use phasync\Tui\Ansi;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Image;
use phasync\Tui\Surface;
use phasync\Tui\Tty;

/** A PNG of $w x $h: the left half red, the right half blue (made without GD). */
function twoColours(int $w, int $h): string
{
    $row = str_repeat("\xff\x00\x00", intdiv($w, 2)) . str_repeat("\x00\x00\xff", $w - intdiv($w, 2));
    return Image::encodePng(str_repeat($row, $h), $w, $h, false);
}

/** Run $test($terminal, $root) with a root attached to a FakeTerminal showing images by $images. */
function onImages(?string $images, Closure $test, int $cols = 30, int $rows = 10, bool $inline = false): void
{
    \fiber\run(function () use ($images, $test, $cols, $rows, $inline) {
        $t = $inline ? FakeTerminal::inline($cols, $rows, images: $images) : new FakeTerminal($cols, $rows, images: $images);
        $root = new Surface($cols, $rows);
        $t->attach($root);
        try {
            $test($t, $root);
        } finally {
            $t->detach();
        }
    });
}

it('asks the terminal for Kitty graphics and its cell size at attach, and says what it found', function () {
    onImages(null, function (FakeTerminal $t, Surface $root) {
        $t->settle();
        expect($t->images())->toBe('kitty')->and($root->cellSize())->toBe([8, 16]);
    });
    \fiber\run(function () {
        $t = new FakeTerminal(20, 5, images: null);
        $t->surface->setCellSize(10, 20);
        $root = new Surface(20, 5);
        $t->attach($root);
        $t->settle();
        expect($root->cellSize())->toBe([10, 20]);
        $t->detach();
    });
    onImages('none', fn(FakeTerminal $t) => expect($t->images())->toBe('none')->and($t->output())->not->toContain("\e_G"));
    expect(Tty::imagesFromEnvironment(['TERM_PROGRAM' => 'iTerm.app']))->toBe('iterm2')
        ->and(Tty::imagesFromEnvironment(['LC_TERMINAL' => 'iTerm2', 'TERM' => 'xterm-256color']))->toBe('iterm2')
        ->and(Tty::imagesFromEnvironment(['TERM_PROGRAM' => 'vscode']))->toBe('iterm2')
        ->and(Tty::imagesFromEnvironment(['TMUX' => '/tmp/x', 'TERM' => 'screen-256color', 'LC_TERMINAL' => 'iTerm2']))->toBeNull()
        ->and(Tty::imagesFromEnvironment(['STY' => '1.pts', 'TERM_PROGRAM' => 'iTerm.app']))->toBe('none')
        ->and(Tty::imagesFromEnvironment(['TERM' => 'linux']))->toBe('none')
        ->and(Tty::imagesFromEnvironment(['TERM' => 'xterm-kitty']))->toBeNull();
});

it('on Kitty, sends an image placed once as one transmission and one placement, and the terminal shows the same cells', function () {
    onImages('kitty', function (FakeTerminal $t, Surface $root) {
        $t->refresh();
        $t->takeOutput();
        $root->write("\e[2;3H" . Ansi::image(twoColours(64, 48), 8, 3));
        $t->refresh();
        $out = $t->takeOutput();
        expect(substr_count($out, "\e_Ga=t,"))->toBe(1)
            ->and(substr_count($out, "\e_Ga=p,"))->toBe(1)
            ->and($out)->toContain('c=8,r=3')
            ->and($t->surface->dump())->toBe($root->dump());
        // the same frame again, and the same image written again: nothing
        $t->refresh();
        $root->write("\e[2;3H" . Ansi::image(twoColours(64, 48), 8, 3));
        $t->refresh();
        expect($t->takeOutput())->toBe('');
    });
});

it('on Kitty, places only the parts a dialog sliding over the image leaves, without sending the image again', function () {
    onImages('kitty', function (FakeTerminal $t, Surface $root) {
        $root->write("\e[2;3H" . Ansi::image(twoColours(80, 96), 10, 6));
        $t->refresh();
        $t->takeOutput();
        $dialog = new Surface(6, 3);
        $dialog->write("\e[44m\e[2J hello");
        $view = $root->host($dialog, 0, 3);
        for ($x = 0; $x < 16; $x += 2) {
            $view->move($x, 3);
            $t->refresh();
            $out = $t->takeOutput();
            expect($out)->not->toContain('a=t,')
                ->and($t->surface->dump())->toBe($root->dump(), "dialog at $x");
        }
        $root->unhost($dialog);
        $t->refresh();
        expect($t->takeOutput())->not->toContain('a=t,')->and($t->surface->dump())->toBe($root->dump());
    });
});

it('on Kitty, deletes the placements of erased image cells, and the images from the terminal when it detaches', function () {
    onImages('kitty', function (FakeTerminal $t, Surface $root) {
        $root->write(Ansi::image(twoColours(16, 16), 2, 1));
        $t->refresh();
        $t->takeOutput();
        $root->write("\e[2J");
        $t->refresh();
        expect($t->takeOutput())->toContain("a=d,d=i,")->and($t->surface->dump())->toBe($root->dump());
        // (the image stays in the terminal's memory while a cell shows it, here the fake
        // terminal's own, which keeps it by its id as kitty does; detaching takes it out)
        $t->detach();
        expect($t->takeOutput())->toContain("a=d,d=I,");
    });
});

it('round-trips: the Kitty output replayed into a surface shows the same image cells, through windows and scrolling', function () {
    onImages('kitty', function (FakeTerminal $t, Surface $root) {
        $pane = new Surface(20, 6);
        $root->host($pane, 4, 2);
        $pane->write(Ansi::image(twoColours(64, 64), 8, 4) . "\e[5;1Hline\r\n");
        $t->refresh();
        $pane->write("next\r\nmore\r\n");      // (the image scrolls up in its pane)
        $t->refresh();
        $replay = new Surface(30, 10);
        $replay->write($t->output());
        expect($replay->dump())->toBe($root->dump())->and(substr_count($root->dump(), 'img='))->toBeGreaterThan(10);
    });
});

it('on iTerm2, sends a fully visible image whole, the cells a window covered again cropped, and nothing for text changes', function () {
    onImages('iterm2', function (FakeTerminal $t, Surface $root) {
        $root->write("\e[2;3H" . Ansi::image(twoColours(64, 48), 8, 3));
        $t->refresh();
        $out = $t->takeOutput();
        expect(substr_count($out, "\e]1337;File=inline=1;"))->toBe(1)->and($out)->toContain('width=8;height=3;')
            ->and($t->surface->cell(2, 1)->style->image)->not->toBeNull()->and($t->surface->cell(9, 3)->style->image)->not->toBeNull();
        $root->write("\e[8;1Hsome text");
        $t->refresh();
        expect($t->takeOutput())->not->toContain('1337');
        $cover = new Surface(3, 1);
        $cover->write('XYZ');
        $root->host($cover, 4, 2);
        $t->refresh();
        expect($t->takeOutput())->not->toContain('1337')->and($t->surface->line(2))->toContain('XYZ');
        $root->unhost($cover);
        $t->refresh();
        $out = $t->takeOutput();
        if (function_exists('imagecrop')) {
            // (only the three cells come back, cropped)
            expect($out)->toContain('width=3;height=1;')->and($t->surface->cell(5, 2)->style->image)->not->toBeNull();
        } else {
            expect($out)->toContain('width=8;height=3;');
        }
    });
});

it('with no protocol, draws image cells as quarter blocks of their pixels', function () {
    if (!function_exists('imagecreatefromstring')) {
        $this->markTestSkipped('quarter blocks of an image need GD; without it the cells are blank');
    }
    onImages('none', function (FakeTerminal $t, Surface $root) {
        $root->write(Ansi::image(twoColours(32, 32), 4, 2));
        $t->refresh();
        // left half red, right half blue: full blocks in each colour
        expect($t->surface->line(0))->toBe('████')
            ->and($t->surface->cell(0, 0)->style->fg)->toBe(\phasync\Tui\Color::rgb(255, 0, 0))
            ->and($t->surface->cell(3, 1)->style->fg)->toBe(\phasync\Tui\Color::rgb(0, 0, 255))
            ->and($t->output())->not->toContain("\e_G")->and($t->output())->not->toContain('1337');
    });
});

it('with no protocol, draws an image it cannot decode as blank cells', function () {
    onImages('none', function (FakeTerminal $t, Surface $root) {
        // a PNG header with nothing GD can read after it
        $broken = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NNCCCCC', 16, 16, 8, 2, 0, 0, 0) . 'xxxxxxxx';
        $root->write('ab' . Ansi::image($broken, 2, 1) . "\e[1;5Hcd");
        $t->refresh();
        expect($t->surface->line(0))->toBe('ab  cd');
    });
});

it('in inline mode on Kitty, leaves the image of a row that scrolled above the region standing', function () {
    onImages('kitty', function (FakeTerminal $t, Surface $root) {
        $root->write(Ansi::image(twoColours(16, 16), 2, 1) . "\e[3Gfirst\r\n");
        $t->refresh();
        for ($i = 0; $i < 6; $i++) {
            $root->write("line $i\r\n");
            $t->refresh();
        }
        $out = $t->output();
        expect(substr_count($out, "a=t,"))->toBe(1)
            ->and($t->frozen())->toContain('first')
            ->and($out)->not->toContain('d=I');
        // the frozen row's image cells are on the terminal above the region
        $found = false;
        for ($y = -$t->surface->scrollbackSize(); $y < $t->surface->rows(); $y++) {
            $found = $found || ($y < 0 ? $t->surface->scrollbackLine($y)?->styles[0]->image : $t->surface->cell(0, $y)->style->image) !== null;
        }
        expect($found)->toBeTrue();
    }, 20, 5, true);
});

it('round-trips the icat and imgcat streams of a hosted program on Kitty, and shows them as quarter blocks without a protocol', function () {
    foreach (['icat', 'imgcat'] as $name) {
        onImages('kitty', function (FakeTerminal $t, Surface $root) use ($name) {
            $pane = new Surface(40, 10);
            $root->host($pane, 2, 1);
            $pane->write(file_get_contents(__DIR__ . "/../fixtures/images/$name.bytes"));
            $t->refresh();
            $replay = new Surface(44, 12);
            $replay->write($t->output());
            expect($replay->dump())->toBe($root->dump(), $name)->and(substr_count($root->dump(), 'img='))->toBe(90, $name);
        }, 44, 12);
        if (function_exists('imagecreatefromstring')) {
            onImages('none', function (FakeTerminal $t, Surface $root) use ($name) {
                $pane = new Surface(40, 10);
                $root->host($pane, 2, 1);
                $pane->write(file_get_contents(__DIR__ . "/../fixtures/images/$name.bytes"));
                $x = $name === 'icat' ? 14 : 2;
                // (a gradient: the cells are blocks in truecolour, not blanks)
                expect(trim($t->text()))->not->toBe('')->and($t->surface->cell($x + 3, 3)->style->fg)->toBeGreaterThan(0xffffff);
            }, 44, 12);
        }
    }
});

it('finds Sixel in the device attributes of a terminal without Kitty graphics or an iTerm2 name, and prefers Kitty, then iTerm2', function () {
    $probe = function (string $answers): array {
        return \fiber\run(function () use ($answers) {
            $device = new class extends \phasync\Tui\TerminalDevice {
                protected function open(string $restore): void {}
                protected function close(): void {}
                protected function send(string $bytes): void {}
                protected function read(float $timeout): string
                {
                    \fiber\sleep(min($timeout, 0.01));
                    return '';
                }
                public function size(): array
                {
                    return [20, 5];
                }
                public function answer(string $bytes): void
                {
                    $this->receive($bytes);
                }
            };
            $root = new Surface(20, 5);
            $device->attach($root);
            $device->answer($answers);
            $found = [$device->images(), $root->cellSize()];
            $device->detach();
            return $found;
        });
    };
    expect($probe("\e[6;20;10t\e[?65;1;4;22c"))->toBe(['sixel', [10, 20]])
        ->and($probe("\e[?62;22c"))->toBe(['none', [8, 16]])
        ->and($probe("\e_Gi=31;OK\e\\\e[?62;4;22c"))->toBe(['kitty', [8, 16]])
        ->and($probe("\eP>|WezTerm 20240203\e\\\e[?62;4;22c"))->toBe(['iterm2', [8, 16]]);
});

it('on Sixel, draws an image scaled to its cells in pixels, the same cells on the terminal, and nothing for text changes or the same frame', function () {
    if (!function_exists('imagecreatefromstring')) {
        $this->markTestSkipped('Sixel output needs GD');
    }
    onImages('sixel', function (FakeTerminal $t, Surface $root) {
        $root->write("\e[2;3H" . Ansi::image(twoColours(64, 48), 8, 3));
        $t->refresh();
        $out = $t->takeOutput();
        // 8x3 cells of 8x16 pixels: 64x48, drawn with the cursor saved around it, mode 8452 set once
        expect(substr_count($out, "\eP0;1;0q"))->toBe(1)->and($out)->toContain("\"1;1;64;48")
            ->and($out)->toContain("\e[?8452h")->and($out)->toMatch('/\e7\eP0;1;0q[^\e]*\e\\\\\e8/');
        $f = $t->surface->cell(2, 1)->style->image;
        expect($f?->cols)->toBe(8)->and($f->rows)->toBe(3)->and($t->surface->cell(9, 3)->style->image?->col)->toBe(7)
            ->and($t->surface->cell(10, 1)->style->image)->toBeNull();
        // left half red, right half blue, on the terminal too
        $px = $f->image->rgba();
        expect(bin2hex(substr($px, 4 * (10 * 64 + 5), 4)))->toBe('ff0000ff')->and(bin2hex(substr($px, 4 * (40 * 64 + 60), 4)))->toBe('0000ffff');
        $root->write("\e[8;1Hsome text");
        $t->refresh();
        expect($t->takeOutput())->not->toContain("\eP");
        $t->refresh();
        expect($t->takeOutput())->toBe('');
    });
});

it('on Sixel, draws again only the cells a window covered, scaled to them, and uses the cell size the terminal reports', function () {
    if (!function_exists('imagecreatefromstring')) {
        $this->markTestSkipped('Sixel output needs GD');
    }
    onImages('sixel', function (FakeTerminal $t, Surface $root) {
        $root->write("\e[2;3H" . Ansi::image(twoColours(64, 48), 8, 3));
        $t->refresh();
        $t->takeOutput();
        $cover = new Surface(3, 1);
        $cover->write('XYZ');
        $root->host($cover, 4, 2);
        $t->refresh();
        expect($t->takeOutput())->not->toContain("\eP")->and($t->surface->line(2))->toContain('XYZ');
        $root->unhost($cover);
        $t->refresh();
        $out = $t->takeOutput();
        expect(substr_count($out, "\eP0;1;0q"))->toBe(1)->and($out)->toContain("\"1;1;24;16")
            ->and($t->surface->cell(4, 2)->style->image)->not->toBeNull();
    });
    \fiber\run(function () {
        $t = new FakeTerminal(30, 10, images: 'sixel');
        $t->surface->setCellSize(10, 20);
        $root = new Surface(30, 10);
        $t->attach($root);
        $t->settle();
        $root->write(Ansi::image(twoColours(64, 48), 4, 2));
        $t->refresh();
        expect($t->output())->toContain("\"1;1;40;40")->and($t->surface->cell(3, 1)->style->image?->col)->toBe(3);
        $t->takeOutput();
        $t->detach();
        expect($t->takeOutput())->toContain("\e[?8452l");
    });
});

it('round-trips a hosted program\'s Sixel image to a Sixel terminal, and shows it as quarter blocks without a protocol', function () {
    if (!function_exists('imagecreatefromstring')) {
        $this->markTestSkipped('Sixel output needs GD');
    }
    onImages('sixel', function (FakeTerminal $t, Surface $root) {
        $pane = new Surface(40, 10);
        $root->host($pane, 2, 1);
        $pane->write(file_get_contents(__DIR__ . '/../fixtures/images/sixel.bytes'));
        $t->refresh();
        // 120x90 pixels in 15x6 cells, at (2, 1) of the root and of the terminal
        expect($t->surface->cell(2, 1)->style->image?->cols)->toBe(15)->and($t->surface->cell(16, 6)->style->image?->row)->toBe(5)
            ->and($t->surface->cell(17, 1)->style->image)->toBeNull()->and($t->surface->cell(1, 1)->style->image)->toBeNull();
    }, 44, 12);
    onImages('none', function (FakeTerminal $t, Surface $root) {
        $root->write(file_get_contents(__DIR__ . '/../fixtures/images/sixel.bytes'));
        $t->refresh();
        expect($t->surface->cell(3, 3)->style->fg)->toBeGreaterThan(0xffffff)->and($t->output())->not->toContain("\eP0;");
    }, 44, 12);
});

it('encodes Sixel that decodes to the same picture, here and in ImageMagick', function () {
    if (!function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('the Sixel encoder works on GD images');
    }
    // four colours in blocks, a transparent corner, 13x11 pixels (not whole bands)
    $gd = imagecreatetruecolor(13, 11);
    imagealphablending($gd, false);
    imagesavealpha($gd, true);
    $colours = [[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 255]];
    for ($y = 0; $y < 11; $y++) {
        for ($x = 0; $x < 13; $x++) {
            [$r, $g, $b] = $colours[($x >= 6 ? 1 : 0) + ($y >= 5 ? 2 : 0)];
            imagesetpixel($gd, $x, $y, $x < 2 && $y < 2 ? imagecolorallocatealpha($gd, 0, 0, 0, 127) : imagecolorallocate($gd, $r, $g, $b));
        }
    }
    $sixel = \phasync\Tui\Internal\Sixel::encode($gd);
    expect($sixel)->toStartWith("\eP0;1;0q\"1;1;13;11")->and($sixel)->toEndWith("\e\\");
    preg_match('/^\eP([0-9;]*)q(.*)\e\\\\$/s', $sixel, $m);
    [$w, $h, $rgba] = \phasync\Tui\Internal\Sixel::decode($m[1], $m[2], [0, 0, 0]);
    expect([$w, $h])->toBe([13, 11]);
    $at = fn(string $px, int $width, int $x, int $y) => bin2hex(substr($px, 4 * ($y * $width + $x), 4));
    expect($at($rgba, 13, 0, 0))->toBe('00000000')->and($at($rgba, 13, 3, 0))->toBe('ff0000ff')->and($at($rgba, 13, 12, 0))->toBe('00ff00ff')
        ->and($at($rgba, 13, 0, 10))->toBe('0000ffff')->and($at($rgba, 13, 12, 10))->toBe('ffffffff');
    $convert = trim((string) shell_exec('command -v convert 2>/dev/null'));
    if ($convert !== '') {
        $file = sys_get_temp_dir() . '/phasync-tui-' . getmypid() . '.sixel';
        file_put_contents($file, $sixel);
        $ppm = (string) shell_exec(escapeshellarg($convert) . ' ' . escapeshellarg("sixel:$file") . ' -depth 8 rgb:-');
        unlink($file);
        expect(strlen($ppm))->toBe(13 * 11 * 3)
            ->and(bin2hex(substr($ppm, 3 * 3, 3)))->toBe('ff0000')->and(bin2hex(substr($ppm, 3 * 12, 3)))->toBe('00ff00')
            ->and(bin2hex(substr($ppm, 3 * (10 * 13), 3)))->toBe('0000ff')->and(bin2hex(substr($ppm, 3 * (10 * 13 + 12), 3)))->toBe('ffffff');
    }
});

it('in inline mode on Sixel, leaves the image of a row that scrolled above the region standing', function () {
    if (!function_exists('imagecreatefromstring')) {
        $this->markTestSkipped('Sixel output needs GD');
    }
    onImages('sixel', function (FakeTerminal $t, Surface $root) {
        $root->write(Ansi::image(twoColours(16, 16), 2, 1) . "\e[3Gfirst\r\n");
        $t->refresh();
        for ($i = 0; $i < 6; $i++) {
            $root->write("line $i\r\n");
            $t->refresh();
        }
        expect($t->frozen())->toContain('first');
        $found = false;
        for ($y = -$t->surface->scrollbackSize(); $y < $t->surface->rows(); $y++) {
            $found = $found || ($y < 0 ? $t->surface->scrollbackLine($y)?->styles[0]->image : $t->surface->cell(0, $y)->style->image) !== null;
        }
        expect($found)->toBeTrue();
    }, 20, 5, true);
});
