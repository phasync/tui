<?php

namespace phasync\Tui\Tests\Support;

/**
 * Reference terminal for tests: feeds bytes into a tmux pane of a given size and reads back
 * what tmux shows (capture-pane), so the Surface can be compared with a real emulator.
 */
final class Tmux
{
    public static function available(): bool
    {
        static $ok = null;
        return $ok ??= trim((string) shell_exec('command -v tmux 2>/dev/null')) !== '';
    }

    /**
     * Run $command in a fresh tmux server with a pane of $cols x $rows and return the server's
     * socket name; the caller must kill() it.
     */
    public static function start(string $command, int $cols, int $rows): string
    {
        $socket = 'phasync-tui-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $conf = sys_get_temp_dir() . "/$socket.conf";
        file_put_contents($conf, "set -g status off\nset -g history-limit 0\n");
        self::tmux($socket, sprintf('-f %s new-session -d -x %d -y %d %s', escapeshellarg($conf), $cols, $rows, escapeshellarg($command)));
        @unlink($conf);
        return $socket;
    }

    public static function kill(string $socket): void
    {
        self::tmux($socket, 'kill-server');
    }

    /** @return array{text: string, ansi: string, x: int, y: int, title: string} */
    public static function capture(string $socket): array
    {
        $text = self::tmux($socket, 'capture-pane -p -t 0');
        $ansi = self::tmux($socket, 'capture-pane -p -e -N -t 0');
        [$x, $y, $title] = explode(' ', trim(self::tmux($socket, "display -p -t 0 '#{cursor_x} #{cursor_y} #{pane_title}'")) . '  ', 3);
        $lines = array_map(static fn($l) => rtrim($l, ' '), explode("\n", rtrim($text, "\n")));
        // tmux reports a pending wrap as the column after the last; the Surface keeps the cursor
        // in the last column with the wrap pending, as xterm does
        $cols = (int) trim(self::tmux($socket, "display -p -t 0 '#{pane_width}'"));
        return ['text' => implode("\n", $lines), 'ansi' => rtrim($ansi, "\n"), 'x' => min((int) $x, $cols - 1), 'y' => (int) $y, 'title' => trim($title)];
    }

    /**
     * What tmux shows after the bytes are written to a pane of $cols x $rows.
     *
     * @return array{text: string, ansi: string, x: int, y: int, title: string}
     */
    public static function render(string $bytes, int $cols, int $rows): array
    {
        $file = tempnam(sys_get_temp_dir(), 'phasync-tui-bytes');
        file_put_contents($file, $bytes);
        $socket = self::start(sprintf('stty raw -echo; cat %s; tmux wait-for -S done; sleep 60', escapeshellarg($file)), $cols, $rows);
        try {
            self::tmux($socket, 'wait-for done');
            return self::capture($socket);
        } finally {
            self::kill($socket);
            unlink($file);
        }
    }

    /**
     * Turn the rows of capture-pane -e -N back into bytes a Surface can replay: tmux writes the
     * styles as SGR sequences that carry over from one row to the next, rows separated by
     * newlines and padded to the full width.
     */
    public static function replayable(string $ansi): string
    {
        $out = "\e[?7l";
        foreach (explode("\n", $ansi) as $y => $row) {
            $out .= "\e[" . ($y + 1) . ";1H" . $row;
        }
        return $out;
    }

    public static function tmux(string $socket, string $args): string
    {
        return (string) shell_exec('tmux -L ' . escapeshellarg($socket) . ' ' . $args . ' 2>/dev/null');
    }
}
