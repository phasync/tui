<?php

namespace phasync\Tui\Component;

/**
 * A `LineChart` of points only: a marker for each `[x, y]` of a series, no lines between them.
 *
 *     new ScatterChart(['samples' => [[1.2, 3.4], [2.0, 2.8], [3.1, 5.0]]], xMin: 0, yMin: 0)
 *
 * Everything else is as for `LineChart` (axes that fit or are fixed, legend, colours, `marker:`).
 * Style: as `LineChart`, with the type `ScatterChart`.
 */
class ScatterChart extends LineChart
{
    /**
     * @param array<string, list<float|int|null|array{float|int, float|int|null}>> $series
     * @param list<string> $colors
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        array $series = [],
        bool $legend = true,
        ?float $xMin = null,
        ?float $xMax = null,
        ?float $yMin = null,
        ?float $yMax = null,
        array $colors = self::COLORS,
        string $marker = 'auto',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        parent::__construct($series, false, true, $legend, $xMin, $xMax, $yMin, $yMax, $colors, $marker, $key, $id, $class, $style);
    }
}
