<?php

namespace phasync\Tui\Component;

use fiber\Deferred;
use phasync\Tui\Component;

class Dialog extends Component
{
    private ?Deferred $result = null;

    public function result(): object
    {
        return $this->result ??= new Deferred();
    }

    public function close(mixed $answer = null): void
    {
        $this->result()->resolve($answer);
        $this->emit('close', $this);
        $this->dispose();
    }
}
