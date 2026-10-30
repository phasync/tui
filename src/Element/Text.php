<?php

namespace phasync\Tui\Element;

use phasync\Tui\Element;

/**
 * Text in the element tree: styled, wrapped to its width, aligned.
 *
 *     new Text('Saved.')
 *     new Text('Disk almost full', class: 'error')
 *     new Text(['Name: ', new Span($name, style: ['bold' => true])])
 *     new Text("one\ntwo", wrap: false, style: ['align' => 'right'])
 *
 * `$content` is a string, or a list of strings and `Span`s for runs in other styles. Strings
 * may carry SGR escape sequences of their own (output of a highlighter, `Ansi::styled()`).
 * "\n" starts a new line. With `$wrap` (the default) lines wrap at spaces to the width the text
 * is given, else a line that does not fit is cut with "…". The `align` style puts the lines
 * left, centered or right. Without a `height` a text is as high as its lines (`'auto'`): in a
 * `Column` it takes the rows it needs. The text's style (`color`, `background`, attributes)
 * fills its whole rectangle. Text does not take the focus.
 */
final class Text extends Element
{
    /** @var list<string|Span> */
    public readonly array $content;

    /**
     * @param string|list<string|Span> $content
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        string|array $content,
        public readonly bool $wrap = true,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
        bool $disabled = false,
    ) {
        $runs = \is_string($content) ? [$content] : array_values($content);
        foreach ($runs as $run) {
            if (!\is_string($run) && !$run instanceof Span) {
                throw new \InvalidArgumentException('Text takes strings and Spans, not ' . get_debug_type($run));
            }
        }
        $this->content = $runs;
        $this->with($key, $id, $class, $style, $width, $height, $disabled);
    }

    /** The text without styles. */
    public function plain(): string
    {
        $out = '';
        foreach ($this->content as $run) {
            $out .= \is_string($run) ? $run : $run->text;
        }
        return preg_replace('/\e\[[0-9;:]*m/', '', $out);
    }
}
