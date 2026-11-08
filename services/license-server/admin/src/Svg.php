<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

final class Svg
{
    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function bar(array $rows, string $label, string $value, string $title, string $fallback = 'Data table'): string
    {
        return self::render($rows, $label, $value, $title, false, $fallback);
    }
    public static function line(array $rows, string $label, string $value, string $title, string $fallback = 'Data table'): string
    {
        return self::render($rows, $label, $value, $title, true, $fallback);
    }
    public static function stackedBar(array $rows, string $label, string $value, string $title, string $fallback = 'Data table'): string
    {
        return self::render($rows, $label, $value, $title, false, $fallback, true);
    }
    public static function sparkline(array $rows, string $label, string $value, string $title, string $fallback = 'Data table'): string
    {
        return self::line($rows, $label, $value, $title, $fallback);
    }
    public static function heatTable(array $rows, string $label, string $value, string $title, string $fallback = 'Data table'): string
    {
        return self::bar($rows, $label, $value, $title, $fallback);
    }

    private static function render(array $rows, string $label, string $value, string $title, bool $line, string $fallback, bool $stack = false): string
    {
        static $id = 0;
        $id++;
        $uid = 'chart-' . $id;
        $max = 1;
        $groups = [];
        $series = [];
        foreach ($rows as $r) {
            $key = (string) ($r[$label] ?? '');
            $plan = (string) ($r['plan'] ?? '');
            $groups[$key][$plan] = ($groups[$key][$plan] ?? 0) + (float) ($r[$value] ?? 0);
            $series[$plan] = true;
        }
        foreach ($groups as $g) {
            $max = max($max, array_sum(array_map('abs', $g)));
        }
        $step = 700 / max(1, count($groups));
        $svg = '<svg class="chart" viewBox="0 0 760 240" role="img" aria-labelledby="' . $uid . '-title ' . $uid . '-desc"><title id="' . $uid . '-title">' . self::h($title) . '</title><desc id="' . $uid . '-desc">' . self::h($fallback) . '</desc><path class="axis" d="M40 10V190H750"/>';
        $i = 0;
        $points = [];
        $palette = array_flip(array_keys($series));
        foreach ($groups as $name => $g) {
            $x = 45 + $i * $step;
            $baseline = 190;
            foreach ($g as $plan => $v) {
                $height = abs($v) / $max * 160;
                $y = $baseline - $height;
                $class = 's' . (($palette[$plan] ?? 0)%6 + 1);
                $tip = self::h($name . ' ' . $plan . ': ' . $v);
                if ($line) {
                    $points[] = round($x, 2) . ',' . round($y, 2);
                    $svg.='<circle class="s1" cx="' . round($x, 2) . '" cy="' . round($y, 2) . '" r="3"><title>' . $tip . '</title></circle>';
                } else {
                    $svg.='<rect class="' . $class . '" x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round(max(1, $step - 8), 2) . '" height="' . round($height, 2) . '"><title>' . $tip . '</title></rect>';
                }
                if ($stack) {
                    $baseline = $y;
                }
            }
            if ($i%max(1, (int) ceil(count($groups) / 8)) === 0) {
                $svg.='<text class="axis-label" x="' . round($x, 2) . '" y="215">' . self::h((string) $name) . '</text>';
            }
            $i++;
        }
        if ($line) {
            $svg.='<polyline class="chart-line" points="' . implode(' ', $points) . '"/>';
        }
        $svg.='</svg><details><summary>' . self::h($fallback) . '</summary><div class="table-wrap"><table><thead><tr>';
        $cols = $rows === []?[$label, $value]: array_keys($rows[0]);
        foreach ($cols as $col) {
            $svg.='<th>' . self::h((string) $col) . '</th>';
        }
        $svg.='</tr></thead><tbody>';
        foreach ($rows as $r) {
            $svg.='<tr>';
            foreach ($cols as $col) {
                $svg.='<td>' . self::h((string) ($r[$col] ?? '')) . '</td>';
            }
            $svg.='</tr>';
        }
        return $svg . '</tbody></table></div></details>';
    }
}
