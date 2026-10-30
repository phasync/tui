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
    public function __construct(string $question, string $yes = 'Yes', string $no = 'No', string $title = '')
    {
        parent::__construct($title, $question, [$yes => true, $no => false], false);
    }
}
