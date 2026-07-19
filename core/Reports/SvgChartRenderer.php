<?php

declare(strict_types=1);

namespace CodeVault\Reports;

/**
 * Server-rendered inline SVG bar chart — no client-side charting library,
 * consistent with the rest of the app's "no external JS bundle" posture
 * (blueprint §5 R10 status note). Pure rendering: takes plain data in,
 * returns an SVG string; no DB access, no request/response coupling.
 */
final class SvgChartRenderer
{
    /**
     * @param array<int, array{label: string, value: float}> $points
     */
    public function bar(array $points, int $width = 640, int $height = 220): string
    {
        if ($points === []) {
            return '<svg class="cv-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="No data"></svg>';
        }

        $values = array_column($points, 'value');
        $max = max($values) > 0 ? max($values) : 1.0;

        $padTop = 12;
        $padBottom = 26;
        $padSide = 4;
        $plotWidth = $width - ($padSide * 2);
        $plotHeight = $height - $padTop - $padBottom;
        $count = count($points);
        $gap = $count > 1 ? 14 : 0;
        $barWidth = ($plotWidth - ($gap * ($count - 1))) / $count;

        $svg = '<svg class="cv-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Bar chart">';

        foreach ([0, 1, 2] as $i) {
            $y = $padTop + ($plotHeight / 2) * $i;
            $svg .= sprintf(
                '<line class="cv-chart__gridline" x1="%d" y1="%.1f" x2="%d" y2="%.1f" />',
                $padSide,
                $y,
                $width - $padSide,
                $y
            );
        }

        foreach ($points as $i => $point) {
            $barHeight = ($point['value'] / $max) * $plotHeight;
            $x = $padSide + $i * ($barWidth + $gap);
            $y = $padTop + $plotHeight - $barHeight;

            $svg .= sprintf(
                '<rect class="cv-chart__bar" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="4"><title>%s: %s</title></rect>',
                $x,
                $y,
                $barWidth,
                max(0, $barHeight),
                e($point['label']),
                e(number_format($point['value'], 2))
            );
            $svg .= sprintf(
                '<text class="cv-chart__axis-label" x="%.1f" y="%d" text-anchor="middle">%s</text>',
                $x + $barWidth / 2,
                $height - 8,
                e($point['label'])
            );
        }

        $svg .= '</svg>';

        return $svg;
    }
}
