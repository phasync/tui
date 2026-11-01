<?php

use phasync\Tui\Component\Canvas;
use phasync\Tui\Component\CanvasContext;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Layers;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Image;
use phasync\Tui\Mouse;
use phasync\Tui\State;
use phasync\Tui\Tui;

/**
 * What phasync/canvas (written alongside this version) does not do yet, so a test of a drawing
 * that needs it is skipped with the reason instead of recording a wrong picture: null when the
 * operation works.
 */
function canvasGap(string $operation): ?string
{
    static $gaps = [];
    $gaps[$operation] ??= (function () use ($operation): string {
        $canvas = new \phasync\Canvas\Canvas(40, 40);
        $c = $canvas->getContext();
        $c->setTransform(10, 0, 0, 10, 0, 0);
        $c->strokeStyle = $c->fillStyle = '#ff0000';
        try {
            match ($operation) {
                'stroke' => (function () use ($c) {
                    $c->beginPath();
                    $c->moveTo(1, 1);
                    $c->lineTo(3, 3);
                    $c->stroke();
                })(),
                'drawImage' => (function () use ($c) {
                    $gd = imagecreatetruecolor(2, 2);
                    imagefill($gd, 0, 0, imagecolorallocate($gd, 255, 0, 0));
                    $c->drawImage($gd, 1, 1, 2, 2);
                    $c->drawImage(Image::encodePng(str_repeat("\xff\x00\x00", 4), 2, 2, false), 1, 1, 2, 2);
                })(),
            };
        } catch (\Throwable $e) {
            return "phasync/canvas: $operation fails (" . $e->getMessage() . ')';
        }
        // (a correct context draws in the middle, at (10..30, 10..30) after the scale)
        $alpha = (imagecolorat($canvas->toGd(), 20, 20) >> 24) & 127;
        return $alpha < 127 ? '' : "phasync/canvas: $operation does not apply the transform yet";
    })();
    return $gaps[$operation] === '' ? null : $gaps[$operation];
}

/** Draw $draw on a canvas of $cols x $rows with $marker on a headless terminal; give the terminal to $test. */
function canvasOn(Closure $draw, string $marker, int $cols, int $rows, string $images, Closure $test): void
{
    fiber\run(function () use ($draw, $marker, $cols, $rows, $images, $test) {
        $t = Tui::headless(fn() => new Canvas(draw: $draw, marker: $marker), $cols, $rows, images: $images);
        $t->settle();
        try {
            $test($t);
        } finally {
            $t->detach();
        }
    });
}

/** A drawing of fills: a rectangle, a triangle, an ellipse and a label. */
function shapes(CanvasContext $c): void
{
    $c->fillStyle = '#ef4444';
    $c->fillRect(1.5, 1.5, 6, 2);
    $c->fillStyle = '#22c55e';
    $c->beginPath();
    $c->moveTo(10.5, 0.5);
    $c->lineTo(19.5, 5.5);
    $c->lineTo(10.5, 5.5);
    $c->closePath();
    $c->fill();
    $c->fillStyle = '#38bdf8';
    $c->beginPath();
    $c->ellipse(26, 3, 4, 2, 0, 0, 2 * M_PI);
    $c->fill();
    $c->fillStyle = '#ffffff';
    $c->fillText('shapes', 1.5, 6.5, null);
}

it('draws on braille as recorded: fills in dots, a cell\'s dots in the colour most of them have, text in characters', function () {
    canvasOn(shapes(...), 'braille', 32, 7, 'none', function (FakeTerminal $t) {
        $file = __DIR__ . '/../fixtures/canvas/shapes-braille.txt';
        if (getenv('TUI_RECORD') === '1') {
            file_put_contents($file, $t->text() . "\n");      // (TUI_RECORD=1 vendor/bin/pest: record again, then look at it)
        }
        expect($t->text() . "\n")->toBe(file_get_contents($file))
            ->and($t->surface->cell(3, 1)->style->fg)->toBe(\phasync\Tui\Color::rgb(0xef, 0x44, 0x44))
            ->and($t->surface->line(6))->toContain('shapes');
    });
});

it('draws the same picture with quarter, half and block markers, as recorded', function () {
    foreach (['quarter', 'half', 'block'] as $marker) {
        canvasOn(shapes(...), $marker, 32, 7, 'none', function (FakeTerminal $t) use ($marker) {
            $file = __DIR__ . "/../fixtures/canvas/shapes-$marker.txt";
            if (getenv('TUI_RECORD') === '1') {
                file_put_contents($file, $t->text() . "\n");
            }
            expect($t->text() . "\n")->toBe(file_get_contents($file), $marker);
        });
    }
});

it('covers the same cells on braille and on pixels (Kitty), and draws text in characters on both', function () {
    $covered = [];
    canvasOn(shapes(...), 'braille', 32, 7, 'none', function (FakeTerminal $t) use (&$covered) {
        for ($y = 0; $y < 7; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $ch = $t->surface->cell($x, $y)->char;
                $covered['braille'][] = $ch !== ' ' && preg_match('/^[\x{2800}-\x{28ff}]$/u', $ch) === 1 ? "$x,$y" : null;
            }
        }
        $covered['brailleText'] = $t->surface->line(6);
    });
    canvasOn(shapes(...), 'pixels', 32, 7, 'kitty', function (FakeTerminal $t) use (&$covered) {
        expect($t->output())->toContain("\e_Ga=t,")->and($t->output())->toContain("\e_Ga=p,");
        // the cells whose pixels are drawn on (opaque), from the image the terminal shows
        for ($y = 0; $y < 7; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $f = $t->surface->cell($x, $y)->style->image;
                $opaque = false;
                if ($f !== null) {
                    [$px, $py, $pw, $ph] = $f->pixels();
                    $gd = $f->image->gd();
                    for ($j = $py; $j < $py + $ph && !$opaque; $j++) {
                        for ($i = $px; $i < $px + $pw && !$opaque; $i++) {
                            $opaque = ((imagecolorat($gd, $i, $j) >> 24) & 127) < 127;
                        }
                    }
                }
                $covered['pixels'][] = $opaque ? "$x,$y" : null;
            }
        }
        $covered['pixelsText'] = $t->surface->line(6);
    });
    // the same cells but at a shape's edge, where a cell drawn on in one and not the other is
    // next to cells drawn on in both (phasync/canvas's toDots() scales bilinearly, so an edge
    // may reach a dot further or less far than the pixels: see DEVNOTES)
    $braille = array_values(array_filter($covered['braille']));
    $pixels = array_values(array_filter($covered['pixels']));
    $both = array_intersect($braille, $pixels);
    foreach ([...array_diff($braille, $pixels), ...array_diff($pixels, $braille)] as $cell) {
        [$x, $y] = explode(',', $cell);
        $near = array_filter($both, function (string $p) use ($x, $y) {
            [$px, $py] = explode(',', $p);
            return abs($px - $x) <= 1 && abs($py - $y) <= 1;
        });
        expect($near)->not->toBe([], "$cell is drawn on in one marker only, away from the shapes' edges");
    }
    expect(count($both))->toBeGreaterThan(0.9 * count($pixels));
    expect(count($pixels))->toBeGreaterThan(60)->and($covered['pixelsText'])->toBe($covered['brailleText']);
});

it('picks pixels where the terminal shows images, else braille (auto), and draws pixels as quarter blocks without images', function () {
    $markers = [];
    foreach (['none', 'kitty', 'iterm2', 'sixel'] as $images) {
        foreach (['auto', 'pixels', 'braille'] as $marker) {
            fiber\run(function () use ($images, $marker, &$markers) {
                $canvas = new Canvas(draw: shapes(...), marker: $marker);
                $t = Tui::headless(fn() => $canvas, 32, 7, images: $images);
                $t->settle();
                $markers["$images/$marker"] = $canvas->markerInUse();
                $t->detach();
            });
        }
    }
    expect($markers)->toBe([
        'none/auto' => 'braille', 'none/pixels' => 'quarter', 'none/braille' => 'braille',
        'kitty/auto' => 'pixels', 'kitty/pixels' => 'pixels', 'kitty/braille' => 'braille',
        'iterm2/auto' => 'pixels', 'iterm2/pixels' => 'pixels', 'iterm2/braille' => 'braille',
        'sixel/auto' => 'pixels', 'sixel/pixels' => 'pixels', 'sixel/braille' => 'braille',
    ]);
});

it('draws pixels on a Sixel terminal over the cells it covers on Kitty', function () {
    $opaque = function (FakeTerminal $t): array {
        $cells = [];
        for ($y = 0; $y < 7; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $f = $t->surface->cell($x, $y)->style->image;
                if ($f === null) {
                    continue;
                }
                [$px, $py, $pw, $ph] = $f->pixels();
                $gd = $f->image->gd();
                for ($j = $py; $j < $py + $ph; $j++) {
                    for ($i = $px; $i < $px + $pw; $i++) {
                        if (((imagecolorat($gd, $i, $j) >> 24) & 127) < 64) {
                            $cells["$x,$y"] = true;
                            break 2;
                        }
                    }
                }
            }
        }
        return array_keys($cells);
    };
    $covered = [];
    foreach (['kitty', 'sixel'] as $images) {
        canvasOn(shapes(...), 'pixels', 32, 7, $images, function (FakeTerminal $t) use (&$covered, $images, $opaque) {
            $covered[$images] = $opaque($t);
            $covered["$images text"] = $t->surface->line(6);
            if ($images === 'sixel') {
                expect($t->output())->toContain("\eP0;1;0q")->and($t->output())->not->toContain("\e_G");
            }
        });
    }
    expect($covered['sixel'])->toBe($covered['kitty'])->and(count($covered['sixel']))->toBeGreaterThan(60)
        ->and($covered['sixel text'])->toBe($covered['kitty text']);
});

it('draws again when a State its closure read changes, and writes nothing when the drawing comes out the same', function () {
    fiber\run(function () {
        $s = new State(['x' => 2.0, 'other' => 0]);
        $draws = 0;
        $canvas = new Canvas(draw: function (CanvasContext $c) use ($s, &$draws) {
            $draws++;
            $c->fillRect($s->x, 1, 3, 2);
        }, marker: 'braille');
        $t = Tui::headless(fn() => $canvas, 20, 4);
        $t->settle();
        $first = $draws;
        $before = $t->surface->text();
        $s->x = 8.0;
        $t->settle();
        expect($draws)->toBe($first + 1)->and($t->surface->text())->not->toBe($before);
        // the same picture again (a value it reads set to what it was): drawn, nothing written
        $t->takeOutput();
        $s->x = 8.0 + 1e-9;
        $t->settle();
        expect($draws)->toBe($first + 2)->and($t->takeOutput())->toBe('');
        $t->detach();
    });
});

it('draws an image with drawImage on braille and on pixels, in the canvas\'s units', function () {
    if (($gap = canvasGap('drawImage')) !== null) {
        $this->markTestSkipped($gap);
    }
    $gd = imagecreatetruecolor(16, 16);
    imagefill($gd, 0, 0, imagecolorallocate($gd, 0, 0, 255));
    $png = Image::encodePng(str_repeat("\x00\x00\xff", 16 * 16), 16, 16, false);
    foreach (['a GdImage' => $gd, 'PNG bytes' => $png] as $what => $source) {
        $draw = fn(CanvasContext $c) => $c->drawImage($source, 0, 0, 2, 1);
        canvasOn($draw, 'braille', 10, 4, 'none', function (FakeTerminal $t) use ($what) {
            // two cells wide, one high, full of dots, in blue
            expect($t->surface->line(0))->toBe('⣿⣿', $what)->and($t->surface->line(1))->toBe('', $what)
                ->and($t->surface->cell(1, 0)->style->fg)->toBe(\phasync\Tui\Color::rgb(0, 0, 255));
        });
        canvasOn($draw, 'pixels', 10, 4, 'kitty', function (FakeTerminal $t) use ($what) {
            $f = $t->surface->cell(0, 0)->style->image;
            expect($f)->not->toBeNull($what)->and(imagecolorat($f->image->gd(), 4, 4) & 0xffffff)->toBe(0x0000ff);
        });
    }
});

it('strokes paths in the canvas\'s units', function () {
    if (($gap = canvasGap('stroke')) !== null) {
        $this->markTestSkipped($gap);
    }
    canvasOn(function (CanvasContext $c) {
        $c->lineWidth = 0.25;
        $c->beginPath();
        $c->moveTo(0, 2.1);
        $c->lineTo(10, 2.1);
        $c->stroke();
    }, 'braille', 12, 4, 'none', function (FakeTerminal $t) {
        // a line one dot high along row 2, ten cells long
        expect(mb_strlen(trim($t->surface->line(2))))->toBe(10)->and(trim($t->surface->line(0) . $t->surface->line(1) . $t->surface->line(3)))->toBe('');
    });
});

it('places text with textAlign and textBaseline, through the transform, in the fill colour, and measures it in cells', function () {
    canvasOn(function (CanvasContext $c) {
        $c->fillStyle = 'rgb(255, 0, 0)';
        $c->textBaseline = 'top';
        $c->fillText('left', 0, 0);
        $c->textAlign = 'center';
        $c->fillText('mid', 10, 1);
        $c->textAlign = 'end';
        $c->fillText('right', 20, 2);
        $c->textAlign = 'start';
        $c->textBaseline = 'alphabetic';
        $c->save();
        $c->translate(5, 1);
        $c->scale(2, 1);
        $c->fillText('moved', 1, 3);       // (5 + 2, 1 + 3), on the row above the baseline
        $c->restore();
        $c->fillText('w=' . $c->measureText('wide字')['width'], 0, 6);
    }, 'braille', 20, 6, 'none', function (FakeTerminal $t) {
        expect($t->surface->line(0))->toBe('left')
            ->and($t->surface->line(1))->toBe('         mid')
            ->and($t->surface->line(2))->toBe('               right')
            ->and($t->surface->line(3))->toBe('       moved')
            ->and($t->surface->line(5))->toBe('w=6')
            ->and($t->surface->cell(0, 0)->style->fg)->toBe(\phasync\Tui\Color::rgb(255, 0, 0));
    });
});

it('tells its size, resolution and marker to the drawing, and starts in the theme\'s accent', function () {
    $seen = [];
    foreach (['braille' => 'none', 'half' => 'none', 'pixels' => 'kitty'] as $marker => $images) {
        canvasOn(function (CanvasContext $c) use (&$seen, $marker) {
            $seen[$marker] = [$c->width(), $c->height(), $c->resolution(), $c->marker(), $c->strokeStyle];
        }, $marker, 12, 3, $images, fn() => null);
    }
    expect($seen['braille'])->toBe([12, 3, [2, 4], 'braille', $seen['braille'][4]])
        ->and($seen['half'][2])->toBe([1, 2])
        ->and($seen['pixels'][2])->toBe([8, 16])
        ->and($seen['braille'][4])->toMatch('/^#[0-9a-f]{6}$/');
});

it('gives onMouse the position in the canvas\'s units and follows the mouse', function () {
    fiber\run(function () {
        $events = [];
        $canvas = new Canvas(draw: fn() => null, onMouse: function (Mouse $m) use (&$events) {
            $events[] = [$m->action, $m->canvasX, $m->canvasY, $m->precise];
        });
        $t = Tui::headless(fn() => new Column([new Text('title'), $canvas]), 20, 6);
        $t->settle();
        expect($canvas->surface->mode(1003))->toBeTrue();
        $t->click(3, 2);
        $t->mouse(new Mouse(Mouse::MOVE, 5, 4));
        $t->settle();
        expect($events[0])->toBe([Mouse::PRESS, 3.5, 1.5, false])
            ->and(end($events))->toBe([Mouse::MOVE, 5.5, 3.5, false]);
        $t->detach();
    });
});

it('shows a canvas inside nested windows on Kitty as the root does (the device output replayed gives the same cells)', function () {
    fiber\run(function () {
        $s = new State(['n' => 1]);
        $t = Tui::headless(fn() => new Layers([
            new Box(new Column([new Text('graph'), new Box(new Canvas(draw: function (CanvasContext $c) use ($s) {
                $c->fillRect(1, 0.5, $s->n * 3, 1.5);
            }, marker: 'pixels'), 'inner')]), 'outer'),
            new Text('over it', class: 'w-10 h-1'),
        ]), 40, 10, images: 'kitty');
        $t->settle();
        foreach ([2, 3, 1] as $n) {
            $s->n = $n;
            $t->settle();
            $replay = new \phasync\Tui\Surface(40, 10);
            $replay->write($t->output());
            expect($replay->dump())->toBe($t->surface->dump(), "n=$n")
                ->and(substr_count($t->surface->dump(), 'img='))->toBeGreaterThan(20);
        }
        $t->detach();
    });
});
