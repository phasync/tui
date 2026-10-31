<?php

namespace phasync\Tui\Element;

use phasync\Tui\Element;
use phasync\Tui\Markup;

/**
 * Text in the element tree: styled, wrapped to its width, aligned.
 *
 *     new Text('Saved.')
 *     new Text('Disk almost full', class: 'error')
 *     new Text(['Name: ', new Span($name, style: ['bold' => true])])
 *     new Text("one\ntwo", wrap: false, style: ['align' => 'right'])
 *
 * `$content` is a string, or a list of strings and `Span`s for runs in other styles, or a
 * closure that returns either. Strings take markup (`'<b>3</b> files, <fg=red>1 failed</>'`,
 * see `Markup`) and may carry SGR escape sequences of their own (output of a highlighter,
 * `Ansi::styled()`); `Text::raw()` shows a string exactly as it is, tags and all. "\n" starts a
 * new line. With `$wrap` (the default; and the `wrap` style, `truncate`) lines wrap at spaces
 * to the width the text is given, else a line that does not fit is cut with "…". The `align`
 * style puts the lines left, centered or right. Without a `height` a text is as high as its
 * lines (`'auto'`): in a `Column` it takes the rows it needs. The text's style (`color`,
 * `background`, attributes) fills its whole rectangle. Text does not take the focus.
 *
 * **Dynamic text.** With a closure for content (`new Text(fn() => "Working… {$state->done}%")`)
 * the text is a small component of its own: the closure runs when it is shown and again
 * whenever a `State` it read changes, and only that text is drawn again; the component that
 * placed it does not render. So one value can change in a screen that is otherwise built once.
 */
final class Text extends Element
{
    /** @var list<string|Span>|\Closure(): (string|list<string|Span>) the runs, or the closure that gives them */
    public readonly array|\Closure $content;
    /** Shown as written: no markup (see `raw()`). */
    private bool $raw = false;

    /**
     * @param string|list<string|Span>|\Closure(): (string|list<string|Span>) $content
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        string|array|\Closure $content,
        public readonly bool $wrap = true,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
        bool $disabled = false,
    ) {
        $this->content = $content instanceof \Closure ? $content : self::runs($content);
        $this->with($key, $id, $class, $style, $width, $height, $disabled);
    }

    /**
     * A text shown exactly as written: `<` in it is no markup (a file name, a user's input, an
     * HTML snippet). Takes the same arguments as the constructor: `Text::raw($html, class: 'code')`.
     *
     * @param string|list<string|Span>|\Closure(): (string|list<string|Span>) $content
     */
    public static function raw(string|array|\Closure $content, mixed ...$arguments): self
    {
        $text = new self($content, ...$arguments);
        $text->raw = true;
        return $text;
    }

    /** True for a text made with `raw()`: its strings are not read as markup. */
    public function isRaw(): bool
    {
        return $this->raw;
    }

    /** The text without styles and markup (a closure's text as it gives it now). */
    public function plain(): string
    {
        $out = '';
        foreach ($this->content instanceof \Closure ? self::runs(($this->content)()) : $this->content as $run) {
            $text = \is_string($run) ? $run : $run->text;
            $out .= $this->raw ? $text : Markup::plain($text);
        }
        return preg_replace('/\e\[[0-9;:]*m/', '', $out);
    }

    /**
     * @internal This text with a closure's content as it is now: what the component that shows
     * a dynamic text renders.
     */
    public function resolved(): self
    {
        if (!$this->content instanceof \Closure) {
            return $this;
        }
        $text = new self(($this->content)(), $this->wrap, null, $this->attribute('id'), $this->attribute('class'), $this->attribute('style'), disabled: $this->attribute('disabled'));
        $text->raw = $this->raw;
        return $text;
    }

    /**
     * @param string|list<string|Span>|mixed $content
     * @return list<string|Span>
     */
    private static function runs(mixed $content): array
    {
        $runs = \is_string($content) ? [$content] : (\is_array($content) ? array_values($content) : [$content]);
        foreach ($runs as $i => $run) {
            if (\is_int($run) || \is_float($run) || $run instanceof \Stringable) {
                $runs[$i] = (string) $run;
            } elseif (!\is_string($run) && !$run instanceof Span) {
                throw new \InvalidArgumentException('Text takes strings and Spans, not ' . get_debug_type($run));
            }
        }
        return $runs;
    }
}
