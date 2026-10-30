<?php

use phasync\Tui\Focus;
use phasync\Tui\InputDecoder;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Paste;
use phasync\Tui\Surface;

function decode(string $bytes, bool $flush = true): array
{
    $d = new InputDecoder();
    $events = $d->feed($bytes);
    return $flush ? [...$events, ...$d->flush()] : $events;
}

function keys(string $bytes): array
{
    return array_map(fn($e) => (string) $e, decode($bytes));
}

/** What a surface in the given modes sends for $key. */
function encodeKey(Key $key, string $modes = ''): string
{
    $s = new Surface(10, 1);
    $s->write($modes);
    $s->dispatch($key);
    return $s->input->read(timeout: 0) ?: $s->input->peek();
}

describe('Key', function () {
    it('parses and prints its notation', function () {
        expect((string) Key::parse('ctrl+c'))->toBe('ctrl+c')
            ->and((string) Key::parse('Shift+Alt+Up'))->toBe('alt+shift+up')
            ->and((string) Key::parse('ctrl+A'))->toBe('ctrl+shift+a')
            ->and(Key::parse('A')->text)->toBe('A')
            ->and(Key::parse('esc')->name)->toBe('escape')
            ->and(Key::parse('space')->text)->toBe(' ')
            ->and(Key::parse('ctrl+x')->text)->toBe('')
            ->and(Key::parse('f5')->is('F5'))->toBeTrue()
            ->and(new Key('q', ctrl: true))->is('ctrl+q')->toBeTrue();
        expect(fn() => Key::parse('ctrl+nope'))->toThrow(InvalidArgumentException::class);
    });
});

describe('decoding', function () {
    it('decodes text, control characters and named keys', function () {
        expect(keys("aZ é中\r\t\x7f\x01\x1a\x00\x08\e[Z"))->toBe([
            'a', 'Z', 'space', 'é', '中', 'enter', 'tab', 'backspace', 'ctrl+a', 'ctrl+z', 'ctrl+space', 'ctrl+backspace', 'shift+tab',
        ]);
    });

    it('decodes cursor and editing keys in normal and application mode, with modifiers', function () {
        expect(keys("\e[A\eOB\e[1;5C\e[1;2D\e[H\eOF\e[2~\e[3;3~\e[5~\e[6~\e[1~\e[4~"))->toBe([
            'up', 'down', 'ctrl+right', 'shift+left', 'home', 'end', 'insert', 'alt+delete', 'pageup', 'pagedown', 'home', 'end',
        ]);
    });

    it('decodes function keys', function () {
        expect(keys("\eOP\eOS\e[15~\e[24~\e[1;2P\e[17;5~"))->toBe(['f1', 'f4', 'f5', 'f12', 'shift+f1', 'ctrl+f6']);
    });

    it('decodes Alt as an ESC prefix', function () {
        expect(keys("\ex\eX\e\r\e\x7f\e "))->toBe(['alt+x', 'alt+shift+x', 'alt+enter', 'alt+backspace', 'alt+space']);
    });

    it('waits for more bytes after ESC and makes it the Escape key on flush', function () {
        $d = new InputDecoder();
        expect($d->feed("\e"))->toBe([])
            ->and($d->hasPending())->toBeTrue()
            ->and(array_map('strval', $d->flush()))->toBe(['escape']);
        expect(array_map('strval', $d->feed("\e")))->toBe([]);
        expect(array_map('strval', $d->feed("[A")))->toBe(['up']);
    });

    it('completes sequences and UTF-8 split across reads', function () {
        $d = new InputDecoder();
        $out = [];
        foreach (str_split("\e[1;5A\xe4\xb8\xad\e[<0;5;6M") as $b) {
            $out = [...$out, ...$d->feed($b)];
        }
        expect(array_map(fn($e) => $e instanceof Key ? (string) $e : $e::class, $out))->toBe(['ctrl+up', '中', Mouse::class]);
    });

    it('decodes the kitty keyboard protocol and modifyOtherKeys', function () {
        expect(keys("\e[27u\e[13;2u\e[97;5u\e[97;6u\e[9;2u\e[127;3u\e[27;5;13~\e[27;2;65~\e[65;2u\e[97;5:3u"))->toBe([
            'escape', 'shift+enter', 'ctrl+a', 'ctrl+shift+a', 'shift+tab', 'alt+backspace', 'ctrl+enter', 'A', 'A',
        ]);
    });

    it('decodes SGR and X10 mouse reports', function () {
        $events = decode("\e[<0;10;5M\e[<0;10;5m\e[<32;11;5M\e[<64;1;1M\e[<65;1;1M\e[<18;3;4M\e[<35;7;8M\e[M !!\e[M#!!");
        $short = array_map(fn(Mouse $m) => "$m->action:$m->x,$m->y:" . ($m->button ?? '-') . ($m->ctrl ? ':ctrl' : ''), $events);
        expect($short)->toBe([
            'press:9,4:0', 'release:9,4:0', 'drag:10,4:0', 'wheel-up:0,0:-', 'wheel-down:0,0:-', 'press:2,3:2:ctrl', 'move:6,7:-',
            'press:0,0:0', 'release:0,0:-',
        ]);
    });

    it('decodes bracketed paste as one event, also across reads', function () {
        $d = new InputDecoder();
        $events = [...$d->feed("x\e[200~line 1\rline 2\e[2"), ...$d->feed("01~y")];
        expect($events[0])->toEqual(new Key('x'))
            ->and($events[1])->toEqual(new Paste("line 1\nline 2"))
            ->and($events[2])->toEqual(new Key('y'));
    });

    it('decodes focus reports', function () {
        expect(decode("\e[I\e[O"))->toEqual([new Focus(true), new Focus(false)]);
    });

    it('drops replies to terminal queries', function () {
        expect(keys("\e[12;40R\e[?62;22c\e[>1;10;0c\e[?1u\e[?2004;1\$y\e]11;rgb:0000/0000/0000\x07\eP>|xterm(1)\e\\x"))->toBe(['x']);
    });

    it('keeps a lone ESC [ as Alt+[ on flush', function () {
        expect(keys("\e["))->toBe(['alt+[']);
    });
});

describe('encoding for a surface', function () {
    it('encodes keys as xterm does by default', function () {
        expect(encodeKey(Key::parse('a')))->toBe('a')
            ->and(encodeKey(Key::parse('ctrl+c')))->toBe("\x03")
            ->and(encodeKey(Key::parse('alt+x')))->toBe("\ex")
            ->and(encodeKey(Key::parse('up')))->toBe("\e[A")
            ->and(encodeKey(Key::parse('ctrl+up')))->toBe("\e[1;5A")
            ->and(encodeKey(Key::parse('shift+tab')))->toBe("\e[Z")
            ->and(encodeKey(Key::parse('enter')))->toBe("\r")
            ->and(encodeKey(Key::parse('backspace')))->toBe("\x7f")
            ->and(encodeKey(Key::parse('f1')))->toBe("\eOP")
            ->and(encodeKey(Key::parse('f5')))->toBe("\e[15~")
            ->and(encodeKey(Key::parse('shift+delete')))->toBe("\e[3;2~")
            ->and(encodeKey(Key::parse('escape')))->toBe("\e")
            ->and(encodeKey(Key::parse('中')))->toBe('中');
    });

    it('sends application cursor keys in DECCKM mode', function () {
        expect(encodeKey(Key::parse('up'), "\e[?1h"))->toBe("\eOA")
            ->and(encodeKey(Key::parse('home'), "\e[?1h"))->toBe("\eOH")
            ->and(encodeKey(Key::parse('ctrl+up'), "\e[?1h"))->toBe("\e[1;5A");
    });

    it('disambiguates with the kitty keyboard protocol when the program asked for it', function () {
        expect(encodeKey(Key::parse('escape'), "\e[>1u"))->toBe("\e[27u")
            ->and(encodeKey(Key::parse('shift+enter'), "\e[>1u"))->toBe("\e[13;2u")
            ->and(encodeKey(Key::parse('ctrl+a'), "\e[>1u"))->toBe("\e[97;5u")
            ->and(encodeKey(Key::parse('a'), "\e[>1u"))->toBe('a')
            ->and(encodeKey(Key::parse('A'), "\e[>1u"))->toBe('A')
            ->and(encodeKey(Key::parse('enter'), "\e[>1u"))->toBe("\r")
            ->and(encodeKey(Key::parse('a'), "\e[>8u"))->toBe("\e[97u")
            ->and(encodeKey(Key::parse('escape'), "\e[>1u\e[<u"))->toBe("\e");
    });

    it('uses modifyOtherKeys when the program asked for it', function () {
        expect(encodeKey(Key::parse('ctrl+a'), "\e[>4;2m"))->toBe("\e[27;5;97~")
            ->and(encodeKey(Key::parse('ctrl+a'), "\e[>4;1m"))->toBe("\x01")
            ->and(encodeKey(Key::parse('shift+enter'), "\e[>4;1m"))->toBe("\e[27;2;13~");
    });

    it('round-trips keys through the decoder in every keyboard mode', function () {
        $legacy = ['a', 'Z', '7', 'é', 'space', 'enter', 'tab', 'shift+tab', 'backspace', 'escape', 'ctrl+a', 'ctrl+z', 'alt+x',
            'alt+shift+x', 'alt+enter', 'up', 'ctrl+up', 'alt+shift+left', 'home', 'end', 'insert', 'delete', 'pageup', 'pagedown',
            'f1', 'f4', 'f5', 'f12', 'shift+f3', 'ctrl+f10', 'ctrl+space'];
        $extended = ['shift+enter', 'ctrl+enter', 'ctrl+shift+a', 'ctrl+tab', 'alt+escape'];
        foreach (['' => $legacy, "\e[?1h" => $legacy, "\e[>1u" => [...$legacy, ...$extended], "\e[>4;2m" => [...$legacy, 'ctrl+enter', 'ctrl+shift+a', 'ctrl+tab']] as $modes => $list) {
            foreach ($list as $spec) {
                $key = Key::parse($spec);
                expect(keys(encodeKey($key, $modes)))->toBe([(string) $key], "$spec with modes " . json_encode($modes));
            }
        }
    });

    it('encodes mouse events only in the modes the program enabled', function () {
        $s = new Surface(100, 50);
        expect($s->dispatch(new Mouse(Mouse::PRESS, 3, 4, Mouse::LEFT)))->toBeFalse();
        $s->write("\e[?1000h\e[?1006h");
        $s->dispatch(new Mouse(Mouse::PRESS, 3, 4, Mouse::LEFT));
        $s->dispatch(new Mouse(Mouse::DRAG, 4, 4, Mouse::LEFT));     // not in 1000
        $s->dispatch(new Mouse(Mouse::RELEASE, 4, 4, Mouse::LEFT));
        $s->dispatch(new Mouse(Mouse::WHEEL_DOWN, 0, 0, ctrl: true));
        expect($s->input->read(timeout: 0))->toBe("\e[<0;4;5M\e[<0;5;5m\e[<81;1;1M");
        $s->write("\e[?1002h\e[?1006l");
        $s->dispatch(new Mouse(Mouse::DRAG, 4, 4, Mouse::LEFT));
        $s->dispatch(new Mouse(Mouse::RELEASE, 4, 4, Mouse::LEFT));
        expect($s->input->read(timeout: 0))->toBe("\e[M@%%\e[M#%%");
    });

    it('round-trips mouse events through the decoder', function () {
        $s = new Surface(100, 50);
        $s->write("\e[?1003h\e[?1006h");
        $events = [new Mouse(Mouse::PRESS, 3, 4, Mouse::RIGHT, alt: true), new Mouse(Mouse::DRAG, 5, 6, Mouse::RIGHT),
            new Mouse(Mouse::RELEASE, 5, 6, Mouse::RIGHT), new Mouse(Mouse::MOVE, 9, 9), new Mouse(Mouse::WHEEL_UP, 1, 2, shift: true)];
        foreach ($events as $e) {
            $s->dispatch($e);
        }
        expect(decode($s->input->read(timeout: 0)))->toEqual($events);
    });

    it('brackets pastes when the program enabled it and sends newlines as CR', function () {
        $s = new Surface(10, 1);
        $s->dispatch(new Paste("a\nb"));
        expect($s->input->read(timeout: 0))->toBe("a\rb");
        $s->write("\e[?2004h");
        $s->dispatch(new Paste("x\e[201~y"));
        expect($s->input->read(timeout: 0))->toBe("\e[200~xy\e[201~");
    });

    it('reports focus only when the program enabled it', function () {
        $s = new Surface(10, 1);
        $s->dispatch(new Focus(true));
        $s->write("\e[?1004h");
        $s->dispatch(new Focus(false));
        expect($s->input->peek())->toBe("\e[O");
    });
});
