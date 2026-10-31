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
     * Split $total cells between items. Fixed sizes (cells, percentages) first, in order (shrunk
     * from the end when they do not fit). The rest goes to the `['auto', n]` items (n: the size
     * their content needs) and the weights (`fr`) together: each auto item is a share like `1fr`
     * capped at its n, so an item that needs little gets what it needs and one that needs more
     * than its share (a table of 10,000 rows, a one-line field as wide as it is given) leaves
     * its siblings theirs. Parts add up exactly (largest remainder).
     *
     * @param list<array{string, float}> $sizes ['cells'|'percent'|'fr'|'auto', value]
     * @return list<int>
     */
    public static function distribute(array $sizes, int $total): array
    {
        $out = [];
        $used = 0;
        $flex = [];         // index => weight, for fr and auto items
        foreach ($sizes as $i => [$kind, $value]) {
            $out[$i] = 0;
            if ($kind === 'fr' || $kind === 'auto') {
                $flex[$i] = $kind === 'fr' ? $value : 1.0;
                continue;
            }
            $n = $kind === 'cells' ? (int) $value : (int) floor($total * $value / 100);
            $n = max(0, min($n, $total - $used));
            $out[$i] = $n;
            $used += $n;
        }
        $rest = $total - $used;
        // auto items that need no more than their share get what they need, until none does
        do {
            $settled = false;
            $weights = array_sum($flex);
            foreach ($flex as $i => $weight) {
                if ($sizes[$i][0] === 'auto' && ($need = max(0, (int) $sizes[$i][1])) <= $rest * $weight / $weights) {
                    $out[$i] = $need;
                    $rest -= $need;
                    unset($flex[$i]);
                    $settled = true;
                    break;
                }
            }
        } while ($settled && $flex !== []);
        $weights = array_sum($flex);
        if ($weights > 0 && $rest > 0) {
            $given = 0;
            $remainders = [];
            foreach ($flex as $i => $weight) {
                $exact = $rest * $weight / $weights;
                $out[$i] = (int) floor($exact);
                $given += $out[$i];
                $remainders[$i] = $exact - $out[$i];
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
