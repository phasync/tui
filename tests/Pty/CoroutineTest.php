<?php

use phasync\Tui\Surface;

use function fiber\await;
use function fiber\run;
use function fiber\sleep;

it('runs a closure as the producer of a surface', function () {
    run(function () {
        $s = new Surface(20, 3);
        $result = await($s->run(function (Surface $surface) {
            $surface->write("\e[32mgreen\e[0m\r\n");
            sleep(0.01);
            $surface->write('done');
            return 42;
        }));
        expect($result)->toBe(42)
            ->and($s->text())->toBe("green\ndone\n")
            ->and($s->cell(0, 0)->style->fg)->toBe(phasync\Tui\Color::GREEN);
    });
});

it('lets the closure read its input', function () {
    run(function () {
        $s = new Surface(20, 3);
        $task = $s->run(function (Surface $surface) {
            $surface->write('name? ');
            $name = $surface->input->read();
            $surface->write("hi $name");
        });
        sleep(0.01);
        $s->input->write('ann');
        await($task);
        expect($s->line(0))->toBe('name? hi ann');
    });
});

it('captures echo with ext-phasync, also from coroutines the closure starts', function () {
    run(function () {
        $a = new Surface(30, 3);
        $b = new Surface(30, 3);
        $ta = $a->run(function () {
            echo "a1 ";
            sleep(0.02);
            fiber\go(function () {
                sleep(0.01);
                print 'child';
            });
            printf('%s ', 'a2');
        });
        $tb = $b->run(function () {
            echo "\e[1mb1\e[0m ";
            sleep(0.01);
            echo 'b2';
        });
        fiber\await_all([$ta, $tb]);
        expect($a->line(0))->toBe('a1 a2 child')
            ->and($b->line(0))->toBe('b1 b2')
            ->and($b->cell(0, 0)->style->attrs)->toBe(phasync\Tui\Style::BOLD);
    });
})->skip(!Surface::canCaptureOutput(), 'needs ext-phasync');

it('renders a symfony/console progress bar written into a surface', function () {
    run(function () {
        $s = new Surface(60, 4);
        await($s->run(function (Surface $surface) {
            $stream = fopen('php://memory', 'w+');
            $output = new Symfony\Component\Console\Output\StreamOutput($stream, decorated: true);
            $bar = new Symfony\Component\Console\Helper\ProgressBar($output, 10);
            $bar->minSecondsBetweenRedraws(0);
            $bar->setRedrawFrequency(1);
            $bar->start();
            for ($i = 0; $i < 10; $i++) {
                $bar->advance();
                rewind($stream);
                $surface->write(stream_get_contents($stream));
                ftruncate($stream, 0);
                rewind($stream);
            }
            $bar->finish();
        }));
        expect($s->line(0))->toBe(' 10/10 [============================] 100%');
    });
});
