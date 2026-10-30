<?php

/**
 * A dashboard: graphs and boxes drawn with escape sequences from coroutines.
 *
 *     php examples/dashboard.php
 *
 * Each graph is a component whose coroutine samples a value a few times a second and redraws
 * a bar chart with `Ansi` (block characters, colors by level). The boxes are `Box` components,
 * the event list a `Log`, the header a `StatusBar`. q or Ctrl-C quits.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Component\Box;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\StatusBar;
use phasync\Tui\Key;
use phasync\Tui\Layout;
use phasync\Tui\Split;
use phasync\Tui\Style;
use phasync\Tui\Text;
use phasync\Tui\Tty;

/** A bar chart of the last values of a sampled number. */
final class Graph extends Component
{
    private const BLOCKS = [' ', '▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
    /** @var list<float> */
    private array $values = [];

    /** @param \Closure(): float $sample a value between 0 and $max */
    public function __construct(private readonly \Closure $sample, private readonly float $max, private readonly string $unit, private readonly float $interval = 0.25)
    {
    }

    protected function run(): void
    {
        do {
            $this->values[] = ($this->sample)();
            $this->values = array_slice($this->values, -500);
            $this->requestRender();
        } while ($this->sleep($this->interval));
    }

    protected function render(): ?Layout
    {
        $cols = $this->surface->cols();
        $rows = $this->surface->rows();
        $values = array_slice($this->values, -$cols);
        $values = array_pad($values, -$cols, null);
        $out = "\e[?25l\e[0m\e[H";
        for ($y = 0; $y < $rows; $y++) {
            $out .= Ansi::moveTo(0, $y);
            $pen = null;
            foreach ($values as $v) {
                if ($v === null) {
                    $out .= ' ';
                    continue;
                }
                $level = max(0.0, min(1.0, $v / $this->max));
                $eighths = (int) round($level * $rows * 8) - ($rows - 1 - $y) * 8;
                $color = $level > 0.8 ? Color::RED : ($level > 0.5 ? Color::YELLOW : Color::GREEN);
                if ($pen !== $color) {
                    $out .= Ansi::fg($color);
                    $pen = $color;
                }
                $out .= self::BLOCKS[max(0, min(8, $eighths))];
            }
            $out .= "\e[0m";
        }
        $last = end($this->values);
        $label = $last === false ? '' : sprintf(' %.2f %s ', $last, $this->unit);
        $out .= Ansi::moveTo(max(0, $cols - Text::width($label)), 0) . Ansi::styled($label, Style::of(attrs: Style::INVERSE));
        $this->surface->write($out);
        return null;
    }
}

final class Dashboard extends Component
{
    private ?Log $events = null;
    private int $samples = 0;

    protected function render(): Layout
    {
        $style = Style::of(Color::BLUE);
        $box = fn(string $key, string $title, \Closure $create) => $this->child($key, fn() => new Box($create(), $title, $style));
        $load = $box('load', 'load average', fn() => new Graph(fn() => (float) explode(' ', (string) @file_get_contents('/proc/loadavg'))[0], max(1, (int) shell_exec('nproc')), 'load'));
        $memory = $box('memory', 'memory used', fn() => new Graph(function (): float {
            preg_match_all('/^(MemTotal|MemAvailable):\s+(\d+)/m', (string) @file_get_contents('/proc/meminfo'), $m);
            $kb = array_combine($m[1], $m[2]) + ['MemTotal' => 1, 'MemAvailable' => 1];
            return 100 * (1 - $kb['MemAvailable'] / max(1, $kb['MemTotal']));
        }, 100, '%'));
        $walk = 50.0;
        $random = $box('random', 'random walk', fn() => new Graph(function () use (&$walk): float {
            $this->samples++;
            return $walk = max(0, min(100, $walk + mt_rand(-80, 80) / 10));
        }, 100, 'pts', 0.1));
        $events = $box('events', 'events', fn() => new Log(wordWrap: true));
        $this->events = $events->content();
        $header = $this->child('header', fn() => new StatusBar(' dashboard', 'q: quit '));
        $graphs = Split::horizontal()->add($load->surface)->add($memory->surface)->add($random->surface);
        return Split::vertical()->add($header->surface, 1)->add($graphs, '60%')->add($events->surface);
    }

    protected function run(): void
    {
        while ($this->sleep(1)) {
            $this->events?->append(sprintf("\e[90m%s\e[0m  %d samples, peak memory %s\n", date('H:i:s'), $this->samples, number_format(memory_get_peak_usage() / 1048576, 1) . ' MB'));
        }
    }

    protected function onKey(Key $key): bool
    {
        if ($key->is('q') || $key->is('ctrl+c')) {
            $this->dispose();       // quit: the terminal is given back
            return true;
        }
        return false;
    }
}

$tty = new Tty();
if (!$tty->isTerminal()) {
    fwrite(STDERR, "dashboard needs a terminal\n");
    exit(1);
}
fiber\run(fn() => (new Dashboard())->start($tty));
