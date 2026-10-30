<?php

namespace phasync\Tui\Internal;

/**
 * @internal Sizes of layout items: an int is cells, '25%' a share of the total, '2fr' a weight.
 */
final class Size
{
    /**
     * @return array{string, float} ['cells'|'percent'|'fr', value]
     * @throws \InvalidArgumentException for anything else
     */
    public static function parse(int|string $size): array
    {
        if (\is_int($size)) {
            return ['cells', (float) max(0, $size)];
        }
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(%|fr)\s*$/', $size, $m)) {
            return [$m[2] === '%' ? 'percent' : 'fr', (float) $m[1]];
        }
        throw new \InvalidArgumentException("Invalid size '$size': use a number of cells, '25%' or '1fr'");
    }

    /**
     * Split $total cells between items: cells and percentages first, the rest by weight
     * (largest remainder, so the parts add up exactly). Shrinks from the end when fixed sizes
     * do not fit.
     *
     * @param list<array{string, float}> $sizes
     * @return list<int>
     */
    public static function distribute(array $sizes, int $total): array
    {
        $out = [];
        $used = 0;
        $weights = 0.0;
        foreach ($sizes as $i => [$kind, $value]) {
            if ($kind === 'fr') {
                $weights += $value;
                $out[$i] = 0;
                continue;
            }
            $n = $kind === 'cells' ? (int) $value : (int) floor($total * $value / 100);
            $n = max(0, min($n, $total - $used));
            $out[$i] = $n;
            $used += $n;
        }
        $rest = $total - $used;
        if ($weights > 0 && $rest > 0) {
            $given = 0;
            $remainders = [];
            foreach ($sizes as $i => [$kind, $value]) {
                if ($kind === 'fr') {
                    $exact = $rest * $value / $weights;
                    $out[$i] = (int) floor($exact);
                    $given += $out[$i];
                    $remainders[$i] = $exact - $out[$i];
                }
            }
            arsort($remainders);
            foreach (array_keys($remainders) as $i) {
                if ($given >= $rest) {
                    break;
                }
                $out[$i]++;
                $given++;
            }
        }
        ksort($out);
        return array_values($out);
    }
}
