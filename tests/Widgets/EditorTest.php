<?php

/*
 * Editor through the terminal: typing, lines and word wrap, the editing keys, undo/redo, the
 * kill ring, selection by keyboard and mouse, bracketed paste, single-line mode, styles.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Editor;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;
use phasync\Tui\Style;

/** An editor in a column under a label, as in a form. */
final class EditorForm extends Component
{
    public array $submitted = [];

    public function __construct(public Editor $editor)
    {
    }

    protected function render(): Element
    {
        return new Column([new Text('Label'), $this->child('e', fn() => $this->editor), new Text('end')]);
    }
}

it('types lines, wraps them between words and edits by grapheme cluster', function () {
    mountOn(new Editor(), 10, 4, function (FakeTerminal $t, Editor $e) {
        $t->type("hello world again\nnext");
        frame();
        expect($e->value())->toBe("hello world again\nnext")
            ->and($t->text())->toBe("hello\nworld\nagain\nnext")
            ->and($e->cursor())->toBe([1, 4]);
        $t->press('backspace', 'backspace');
        $t->type('👍中');
        $t->press('left', 'backspace');
        frame();
        expect($e->value())->toBe("hello world again\nne中")->and($e->cursor())->toBe([1, 2]);
    });
});

it('moves by character, word, line and row, keeping the column across rows', function () {
    mountOn(new Editor("first line\nsecond line here\nx"), 30, 4, function (FakeTerminal $t, Editor $e) {
        $e->setCursor(1, 12);
        $t->press('up');
        expect($e->cursor())->toBe([0, 10]);
        $t->press('down', 'down');
        expect($e->cursor())->toBe([2, 1]);
        $t->press('up');
        expect($e->cursor())->toBe([1, 12]);      // back to the column it came from
        $t->press('ctrl+left');
        expect($e->cursor())->toBe([1, 7]);
        $t->press('ctrl+left', 'ctrl+left');
        expect($e->cursor())->toBe([0, 6]);       // on across the line break
        $t->press('ctrl+right', 'home');
        expect($e->cursor())->toBe([0, 0]);
        $t->press('end', 'right');
        expect($e->cursor())->toBe([1, 0]);
        $t->press('ctrl+end');
        expect($e->cursor())->toBe([2, 1]);
    });
});

it('undoes and redoes in steps: a word typed in one go, a deletion, a paste', function () {
    mountOn(new Editor(), 30, 3, function (FakeTerminal $t, Editor $e) {
        $t->type('one two');
        $t->press('backspace');
        $t->paste('PASTED');
        expect($e->value())->toBe('one twPASTED');
        $t->press('ctrl+z');
        expect($e->value())->toBe('one tw');
        $t->press('ctrl+z');
        expect($e->value())->toBe('one two');
        $t->press('ctrl+z');
        expect($e->value())->toBe('one ');
        $t->press('ctrl+z', 'ctrl+z', 'ctrl+z');
        expect($e->value())->toBe('');
        $t->press('alt+z', 'alt+z');
        expect($e->value())->toBe('one ');
        $t->type('x');           // a new change ends the redo
        $t->press('alt+z');
        expect($e->value())->toBe('one x');
    });
});

it('kills to the kill ring and yanks back, consecutive kills as one, Alt-Y cycling', function () {
    mountOn(new Editor('alpha beta gamma'), 30, 3, function (FakeTerminal $t, Editor $e) {
        $t->press('ctrl+w', 'ctrl+w');           // two kills in a row: one entry
        expect($e->value())->toBe('alpha ');
        $t->press('ctrl+a', 'ctrl+k');           // kill to the end: a second entry
        expect($e->value())->toBe('');
        $t->press('ctrl+y');
        expect($e->value())->toBe('alpha ');
        $t->press('alt+y');
        expect($e->value())->toBe('beta gamma');
        $t->press('alt+y');
        expect($e->value())->toBe('alpha ');
        $t->press('ctrl+u');
        expect($e->value())->toBe('');
    });
});

it('selects with Shift and the arrows, replaces the selection, copies and cuts it', function () {
    mountOn(new Editor("one two\nthree"), 20, 3, function (FakeTerminal $t, Editor $e) {
        $e->setCursor(0, 4);
        $t->press('shift+right', 'shift+right', 'shift+right');
        expect($e->selectedText())->toBe('two');
        $t->press('shift+down');
        expect($e->selectedText())->toBe("two\nthree");
        $t->press('shift+up', 'ctrl+c');
        expect($t->clipboardText())->toBe('two');
        $t->type('2');
        expect($e->value())->toBe("one 2\nthree");
        $t->press('alt+a', 'ctrl+x');
        expect($e->value())->toBe('')->and($t->clipboardText())->toBe("one 2\nthree");
        $t->press('ctrl+y');
        expect($e->value())->toBe("one 2\nthree");
    });
});

it('shows the selection in the selection style', function () {
    mountOn(new Editor('abcdef'), 10, 2, function (FakeTerminal $t, Editor $e) {
        $e->select(0, 1, 0, 3);
        frame();
        expect($t->dump())->toContain('a[/][inverse bg=8]bc[/][bg=8]def');     // (on the focused panel)
    });
});

it('selects with the mouse: press puts the cursor, drag selects, the wheel scrolls', function () {
    $e = new Editor(implode("\n", array_map(fn($i) => "line $i", range(0, 19))));
    mountOn($e, 12, 4, function (FakeTerminal $t, Editor $e) {
        $e->setCursor(0, 0);
        frame();
        $t->click(2, 1);
        expect($e->cursor())->toBe([1, 2])->and($e->selectedText())->toBe('');
        $t->drag(0, 0, 4, 2);
        expect($e->selectedText())->toBe("line 0\nline 1\nline");
        $t->scroll(1, 1, 2);       // two clicks down: six rows
        frame();
        expect($t->text())->toStartWith('line 6');
    });
});

it('inserts a bracketed paste as one step, line breaks included', function () {
    mountOn(new Editor('ab'), 20, 4, function (FakeTerminal $t, Editor $e) {
        $e->setCursor(0, 1);
        $t->paste("x\r\ny\nz");
        expect($e->value())->toBe("ax\ny\nzb");
        $t->press('ctrl+z');
        expect($e->value())->toBe('ab');
    });
});

it('is a one-line field with maxLines 1: Enter submits, breaks become spaces, it scrolls sideways', function () {
    $got = [];
    $editor = new Editor(maxLines: 1, onSubmit: function (string $v) use (&$got) {
        $got[] = $v;
    });
    mountOn(new EditorForm($editor), 8, 3, function (FakeTerminal $t) use ($editor, &$got) {
        $t->paste("a\nb");
        $t->type('cdefghij');
        $t->press('enter');
        frame();
        expect($got)->toBe(['a bcdefghij'])->and($editor->value())->toBe('a bcdefghij')
            ->and($t->text())->toBe("Label\ndefghij\nend")       // the cursor's cell after the text shows
            ->and($editor->measure(8, 3))->toBe([8, 1]);
        $t->press('up');            // not taken: no rows to move in
        expect($editor->cursor())->toBe([0, 11]);
    });
});

it('shows at most maxLines rows, as high as its text up to there, and keeps every line beyond', function () {
    $editor = new Editor(maxLines: 3);
    mountOn(new EditorForm($editor), 10, 6, function (FakeTerminal $t) use ($editor) {
        $t->type("1\n2");
        frame();
        expect($t->text())->toBe("Label\n1\n2\nend\n\n");     // grows with its text
        $t->type("\n3\n4");
        $t->paste("5\n6");
        frame();
        expect($editor->value())->toBe("1\n2\n3\n45\n6")    // nothing joined
            ->and($t->text())->toBe("Label\n3\n45\n6\nend\n");  // three rows, scrolled to the cursor
        $t->press('ctrl+home');
        frame();
        expect($t->text())->toBe("Label\n1\n2\n3\nend\n");
    });
});

it('submits a multi-line text with Alt-Enter', function () {
    $got = null;
    mountOn(new Editor(onSubmit: function (string $v) use (&$got) {
        $got = $v;
    }), 20, 3, function (FakeTerminal $t) use (&$got) {
        $t->type("a\nb");
        $t->press('alt+enter');
        expect($got)->toBe("a\nb");
    });
});

it('looks different focused, shows its placeholder, and takes no input disabled', function () {
    $a = new Editor(placeholder: 'name', maxLines: 1);
    $b = new Editor('x', maxLines: 1);
    $app = new class ($a, $b) extends Component {
        public function __construct(private Editor $a, private Editor $b)
        {
        }

        protected function render(): Element
        {
            return new Column([$this->child('a', fn() => $this->a), $this->child('b', fn() => $this->b)]);
        }
    };
    mountOn($app, 10, 2, function (FakeTerminal $t) use ($a, $b) {
        $a->focus();
        frame();
        expect($t->dump())->toContain('[fg=8 bg=8]name')        // placeholder, on the focused panel
            ->and($t->surface->cell(0, 1)->style)->toBe(Style::plain());
        $b->focus();
        frame();
        $t->refresh();
        expect($t->surface->cell(0, 1)->style->bg)->toBe(8)
            ->and($t->surface->cursor()->visible)->toBeTrue();
        $b->with(disabled: true);
        $t->type('yz');
        frame();
        expect($b->value())->toBe('x')->and($t->dump())->toContain('[dim bg=8]x');
    });
});
