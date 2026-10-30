<?php
/**
 * Records the output of real programs into tests/fixtures/recorded/<name>/, together with what
 * tmux shows at each step, for tests/Surface/RecordedTest.php.
 *
 *     php tools/record-fixtures.php [name...]
 *
 * Each program runs in a tmux pane of 80x24 with TERM=xterm-256color. The pane's output is piped
 * to NN.bytes per phase; at the end of each phase the screen is captured (NN.txt, NN.ansi and
 * NN.cursor), then the phase's keys are sent and the next phase starts.
 */
require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Tests\Support\Tmux;

$root = dirname(__DIR__);
$programs = __DIR__ . '/../tests/fixtures/programs';
$sample = escapeshellarg(realpath("$programs/sample.txt"));
$recordings = [
    // name => [command, [[seconds to wait, keys to send after the capture], ...]]
    'ls' => ['ls --color=always -l --time-style=+ /usr/share/', [[1.0, null]]],
    'progress' => ['php ' . escapeshellarg(realpath("$programs/progress.php")), [[2.0, null]]],
    'vim' => ["vim -u DEFAULTS -i NONE -N $sample", [[1.5, ':q Enter'], [1.0, null]]],
    'less' => ["less $sample", [[1.0, 'q'], [0.5, null]]],
    'htop' => ['htop -d 100', [[2.0, 'q'], [0.5, null]]],
];
$only = array_slice($argv, 1);

foreach ($recordings as $name => [$command, $phases]) {
    if ($only && !in_array($name, $only, true)) {
        continue;
    }
    $dir = "$root/tests/fixtures/recorded/$name";
    @mkdir($dir, 0777, true);
    array_map('unlink', glob("$dir/*"));
    $socket = Tmux::start("sh -c 'sleep 0.5; env TERM=xterm-256color COLORTERM=truecolor LANG=en_US.UTF-8 $command; sleep 60'", 80, 24);
    Tmux::tmux($socket, 'pipe-pane -t 0 ' . escapeshellarg('cat > ' . escapeshellarg("$dir/00.bytes")));
    foreach ($phases as $i => [$wait, $keys]) {
        usleep((int) ((($i === 0 ? 0.5 : 0) + $wait) * 1_000_000));
        $n = sprintf('%02d', $i);
        $ref = Tmux::capture($socket);
        Tmux::tmux($socket, 'pipe-pane -t 0');
        file_put_contents("$dir/$n.txt", $ref['text']);
        file_put_contents("$dir/$n.ansi", $ref['ansi']);
        file_put_contents("$dir/$n.cursor", "{$ref['x']} {$ref['y']}");
        if ($keys !== null) {
            $next = sprintf('%02d', $i + 1);
            Tmux::tmux($socket, 'pipe-pane -t 0 ' . escapeshellarg('cat > ' . escapeshellarg("$dir/$next.bytes")));
            usleep(100_000);
            Tmux::tmux($socket, 'send-keys -t 0 ' . $keys);
        }
    }
    Tmux::kill($socket);
    echo "$name: ", count($phases), " phase(s), ", array_sum(array_map('filesize', glob("$dir/*.bytes"))), " bytes\n";
}
