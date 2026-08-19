<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_exelearning\local\xapi;

/**
 * Reconstructs eXeLearning's weighted package score from current iDevice state.
 *
 * The upstream emitter contract introduced by exelearning/exelearning#2302 exposes
 * both the effective 1..100 relative weight and a deterministic package-global
 * iDevice order. The order matters because eXeLearning normalises weights to 100
 * integer percentage points with largest-remainder allocation; equal fractional
 * remainders are awarded in publication order. A continuous weighted mean is close,
 * but it is not always identical to the package result.
 *
 * This class deliberately has no Moodle or database dependencies. The ingestor owns
 * persistence and passes the latest statement state for each stable iDevice id.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class weighted_score_calculator {
    /**
     * Calculate the exact current weighted score in the 0..100 range.
     *
     * Each item must contain `scorepct`, `weight`, and `ideviceorder`. Invalid rows
     * are ignored defensively; the statement normalizer already validates genuine
     * emitter input before it reaches this method.
     *
     * @param array $items Current per-iDevice rows.
     * @return float|null Score rounded to two decimals, or null when no usable row exists.
     */
    public static function calculate(array $items): ?float {
        $current = [];
        foreach ($items as $item) {
            if (
                !is_array($item)
                || !isset($item['scorepct'], $item['weight'], $item['ideviceorder'])
                || !is_numeric($item['scorepct'])
                || !is_numeric($item['weight'])
                || !is_numeric($item['ideviceorder'])
            ) {
                continue;
            }
            $weight = (float) $item['weight'];
            $order = (int) $item['ideviceorder'];
            if (!is_finite($weight) || $weight < 1.0 || $weight > 100.0 || $order < 1) {
                continue;
            }
            $current[] = [
                'scorepct' => max(0.0, min(100.0, (float) $item['scorepct'])),
                'weight' => $weight,
                'ideviceorder' => $order,
            ];
        }
        if ($current === []) {
            return null;
        }

        usort($current, static fn(array $a, array $b): int => $a['ideviceorder'] <=> $b['ideviceorder']);
        $weightsum = array_sum(array_column($current, 'weight'));
        $factor = 100.0 / $weightsum;
        $allocated = [];
        $floorsum = 0;

        foreach ($current as $index => $item) {
            $scaledweight = $item['weight'] * $factor;
            $floor = (int) floor($scaledweight);
            $allocated[$index] = [
                'index' => $index,
                'points' => $floor,
                'fraction' => $scaledweight - $floor,
                'ideviceorder' => $item['ideviceorder'],
            ];
            $floorsum += $floor;
        }

        usort($allocated, static function (array $a, array $b): int {
            $fraction = $b['fraction'] <=> $a['fraction'];
            return ($fraction !== 0) ? $fraction : ($a['ideviceorder'] <=> $b['ideviceorder']);
        });
        $remaining = 100 - $floorsum;
        for ($i = 0; $i < $remaining; $i++) {
            $allocated[$i]['points']++;
        }

        $score = 0.0;
        foreach ($allocated as $share) {
            $score += $current[$share['index']]['scorepct'] * $share['points'];
        }
        return round($score / 100.0, 2);
    }
}
