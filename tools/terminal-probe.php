#!/usr/bin/env php
<?php
/**
 * What the terminal in front of you answers to the queries phasync/tui uses (and a few more), so
 * a report about a terminal can show what it says about itself rather than what a table says:
 *
 *     php tools/terminal-probe.php
 *
 * Prints each query, the raw answer (escaped) and what it means. Needs a real terminal on
 * stdin/stdout; nothing is drawn, the terminal is left as it was.
 */

if (!stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
    fwrite(STDERR, "stdin and stdout must be a terminal\n");
    exit(1);
}
$saved = trim((string) shell_exec('stty -g'));
shell_exec('stty -echo -icanon min 0 time 0');
register_shutdown_function(fn() => shell_exec('stty ' . escapeshellarg($saved)));

$queries = [
    'DA1 (device attributes)'            => ["\e[c",            '/\e\[\?[\d;]*c/'],
    'DA2 (terminal type/version)'        => ["\e[>c",           '/\e\[>[\d;]*c/'],
    'XTVERSION (name)'                   => ["\e[>0q",          '/\eP>\|[^\e]*\e\\\\/'],
    'kitty keyboard protocol'            => ["\e[?u",           '/\e\[\?\d+u/'],
    'kitty graphics (query a 1x1 image)' => ["\e_Gi=31,s=1,v=1,a=q,t=d,f=24;AAAA\e\\", '/\e_G[^\e]*\e\\\\/'],
    'cell size in pixels (CSI 16 t)'     => ["\e[16t",          '/\e\[6;\d+;\d+t/'],
    'window size in pixels (CSI 14 t)'   => ["\e[14t",          '/\e\[4;\d+;\d+t/'],
    'sixel geometry (XTSMGRAPHICS)'      => ["\e[?2;1;0S",      '/\e\[\?2;\d+(?:;\d+)*S/'],
    'colour registers (XTSMGRAPHICS)'    => ["\e[?1;1;0S",      '/\e\[\?1;\d+(?:;\d+)*S/'],
    'background colour (OSC 11)'         => ["\e]11;?\e\\",     '/\e\]11;[^\e\x07]*(?:\e\\\\|\x07)/'],
    'synchronized output (mode 2026)'    => ["\e[?2026\$p",     '/\e\[\?2026;\d\$y/'],
    'sixel scrolling (mode 80)'          => ["\e[?80\$p",       '/\e\[\?80;\d\$y/'],
    'SGR-pixels mouse (mode 1016)'       => ["\e[?1016\$p",     '/\e\[\?1016;\d\$y/'],
];

$esc = fn(string $s) => preg_replace_callback('/[\x00-\x1f\x7f]/', fn($m) => $m[0] === "\e" ? 'ESC' : sprintf('\\x%02x', ord($m[0])), $s);
$all = '';
foreach ($queries as $name => [$query, $pattern]) {
    fwrite(STDOUT, $query);
    fflush(STDOUT);
    $buf = '';
    $deadline = microtime(true) + 0.6;
    while (microtime(true) < $deadline) {
        $r = [STDIN]; $w = $e = [];
        if (stream_select($r, $w, $e, 0, 100000) > 0) {
            $buf .= (string) fread(STDIN, 4096);
            if (preg_match($pattern, $buf)) {
                break;
            }
        }
    }
    $all .= $buf;
    printf("%-36s %s\n", $name, $buf === '' ? '(no answer in 0.6 s)' : $esc($buf));
}

echo "\nWhat it means:\n";
if (preg_match('/\e\[\?([\d;]*)c/', $all, $m)) {
    $caps = explode(';', $m[1]);
    $known = [4 => 'Sixel graphics', 6 => 'selective erase', 18 => 'user windows', 21 => 'horizontal scrolling', 22 => 'ANSI colour', 28 => 'rectangular editing', 52 => 'clipboard (OSC 52)'];
    echo "  DA1 level/features: " . implode(', ', array_map(fn($c) => $c . (isset($known[$c]) ? " ($known[$c])" : ''), $caps)) . "\n";
    echo "  Sixel: " . (in_array('4', $caps, true) ? 'yes' : 'not reported') . "\n";
}
echo "  Kitty graphics: " . (str_contains($all, "\e_Gi=31;OK") ? 'yes' : 'no') . "\n";
echo "  Kitty keyboard protocol: " . (preg_match('/\e\[\?\d+u/', $all) ? 'yes' : 'no') . "\n";
if (preg_match('/\eP>\|([^\e]*)\e\\\\/', $all, $m)) {
    echo "  Name (XTVERSION): $m[1]\n";
}
if (preg_match('/\e\[6;(\d+);(\d+)t/', $all, $m)) {
    echo "  Cell size: {$m[2]}x{$m[1]} px\n";
}
if (preg_match('/\e\[\?2;0;(\d+);(\d+)S/', $all, $m)) {
    echo "  Max sixel image: {$m[1]}x{$m[2]} px\n";
}
foreach ([2026 => 'synchronized output', 80 => 'sixel scrolling mode', 1016 => 'SGR-pixels mouse'] as $mode => $what) {
    if (preg_match('/\e\[\?' . $mode . ';(\d)\$y/', $all, $m)) {
        echo "  $what: " . match ((int) $m[1]) { 0 => 'not recognised', 1 => 'set', 2 => 'reset (supported)', 3 => 'permanently set', 4 => 'permanently reset', default => $m[1] } . "\n";
    }
}
echo "  TERM=" . getenv('TERM') . " COLORTERM=" . getenv('COLORTERM') . " TERM_PROGRAM=" . getenv('TERM_PROGRAM') . " WT_SESSION=" . (getenv('WT_SESSION') ? 'set' : 'unset') . "\n";
