<?php

namespace phasync\Tui;

/**
 * An input event from the terminal: `Key`, `Mouse`, `Paste` or `Focus`.
 * Produced by `InputDecoder` from the bytes a terminal sends, delivered with `Surface::dispatch()`.
 */
interface Event
{
}
