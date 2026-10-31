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
 * The labels are `$yes` and `$no`; `$yes` has the focus. Styled as a `Dialog` (and as
 * `Confirm`, for rules of its own).
 */
final class Confirm extends Dialog
{
    /** Ask $question with the buttons $yes (true, focused) and $no (false). */
    public function __construct(string $question, string $yes = 'Yes', string $no = 'No', string $title = '')
    {
        parent::__construct($title, $question, [$yes => true, $no => false], false);
    }

    /**
     * Ask $text under the title $title over the running program (see `Dialog::open()`) and
     * return an awaitable of the answer: true for $yes, false for $no and for Escape. From any
     * code, a key handler included: `if (fiber\await(Confirm::ask('Delete', "Delete {$name}?"))) { ... }`.
     *
     * @throws \LogicException when no program is running
     */
    public static function ask(string $title, string $text, string $yes = 'Yes', string $no = 'No'): object
    {
        return self::open(new self($text, $yes, $no, $title));
    }
}
