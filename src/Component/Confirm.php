<?php

namespace phasync\Tui\Component;

/**
 * A yes/no question as a dialog, answering true or false (false for Escape too):
 *
 *     $this->go(function () {
 *         if (fiber\await($this->dialog(new Confirm('Delete 3 files?')))) {
 *             $this->deleteFiles();
 *         }
 *     });
 *
 * The labels are `$yes` and `$no`; `$yes` has the focus. Keys: Enter presses the focused
 * button, Escape answers false, and the first letter of each label answers at once (`y` and
 * `n` for the default labels; none when the two labels start with the same letter). With
 * `timeout:` it answers false by itself when nobody answered in time. Styled as
 * a `Dialog` (and as `Confirm`, for rules of its own).
 */
final class Confirm extends Dialog
{
    /** @var array<string, string> the key of each answer, 'yes' and 'no' (see bindings()) */
    private array $letters = [];

    /**
     * Ask $question with the buttons $yes (true, focused) and $no (false); with $timeout, it
     * answers false by itself after that many seconds.
     */
    public function __construct(string $question, string $yes = 'Yes', string $no = 'No', string $title = '', ?float $timeout = null)
    {
        parent::__construct($title, $question, [$yes => true, $no => false], false, $timeout);
        $y = mb_strtolower(mb_substr(trim($yes), 0, 1));
        $n = mb_strtolower(mb_substr(trim($no), 0, 1));
        if ($y !== $n && preg_match('/^\pL$/u', $y) === 1 && preg_match('/^\pL$/u', $n) === 1) {
            $this->letters = [$y => 'yes', $n => 'no'];
        }
    }

    protected function bindings(): array
    {
        $bindings = parent::bindings();
        foreach ($this->letters as $key => $method) {
            $bindings[$key] = $method;
        }
        return $bindings;
    }

    /** Answer true, as the `$yes` button does (its first letter, `y`). */
    public function yes(): void
    {
        $this->close(true);
    }

    /** Answer false, as the `$no` button does (its first letter, `n`). */
    public function no(): void
    {
        $this->close(false);
    }

    /**
     * Ask $text under the title $title over the running program (see `Dialog::open()`) and
     * return an awaitable of the answer: true for $yes, false for $no and for Escape. From any
     * code, a key handler included: `if (fiber\await(Confirm::ask('Delete', "Delete {$name}?"))) { ... }`.
     *
     * @throws \LogicException when no program is running
     */
    public static function ask(string $title, string $text, string $yes = 'Yes', string $no = 'No', ?float $timeout = null): object
    {
        return self::open(new self($text, $yes, $no, $title, $timeout));
    }
}
