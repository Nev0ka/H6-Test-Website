<?php

declare(strict_types=1);

namespace App;

/**
 * Threshold → status rules per the design spec (README §Interactions & Behavior):
 * Kritisk when temp>=tempCrit OR disk>=95 OR cpu>=95; Advarsel when temp>=tempWarn
 * OR disk>=diskWarn OR cpu>=85; Offline when the agent has no recent sample; else OK.
 *
 * Colors travel as CSS custom-property references (not resolved hex/oklch) so the
 * browser does the actual color math — same values the stylesheet uses everywhere else.
 */
final class StatusEngine
{
    public const OK = 'var(--nf-success)';
    public const WARN = 'var(--nf-warning)';
    public const CRIT = 'var(--nf-error)';
    public const BLUE = 'var(--nf-primary)';
    public const GRAY = 'var(--nf-gray-500)';

    public static function hostStatus(array $metric, array $thresholds, bool $online): array
    {
        if (!$online) {
            return self::badge('Offline', self::GRAY, 'var(--nf-gray-100)', 'var(--nf-gray-600)');
        }

        $tempCrit = (float) $thresholds['temp_crit_c'];
        $tempWarn = (float) $thresholds['temp_warn_c'];
        $diskWarn = (float) $thresholds['disk_warn_pct'];

        if ($metric['cpu_temp_c'] >= $tempCrit || $metric['disk_used_pct'] >= 95 || $metric['cpu_pct'] >= 95) {
            return self::badge('Kritisk', self::CRIT, self::tint(self::CRIT), self::ink(self::CRIT));
        }
        if ($metric['cpu_temp_c'] >= $tempWarn || $metric['disk_used_pct'] >= $diskWarn || $metric['cpu_pct'] >= 85) {
            return self::badge('Advarsel', self::WARN, self::tint(self::WARN), self::ink(self::WARN));
        }
        return self::badge('OK', self::OK, self::tint(self::OK), self::ink(self::OK));
    }

    private static function badge(string $label, string $color, string $bg, string $fg): array
    {
        return ['label' => $label, 'color' => $color, 'bg' => $bg, 'fg' => $fg];
    }

    private static function tint(string $color): string
    {
        return "oklch(from {$color} 96% 0.045 h)";
    }

    private static function ink(string $color): string
    {
        return "oklch(from {$color} 0.45 c h)";
    }

    public static function ringColor(float $pct, float $warnAt, float $critAt, string $baseline = self::BLUE): string
    {
        if ($pct >= $critAt) {
            return self::CRIT;
        }
        if ($pct >= $warnAt) {
            return self::WARN;
        }
        return $baseline;
    }

    public static function processCpuColor(float $cpuPct): string
    {
        if ($cpuPct > 40) {
            return self::CRIT;
        }
        if ($cpuPct > 20) {
            return self::WARN;
        }
        return 'var(--nf-text-heading)';
    }

    public static function processStatePill(string $state): array
    {
        if ($state === 'Kører') {
            return ['bg' => self::tint(self::OK), 'fg' => self::ink(self::OK)];
        }
        return ['bg' => 'var(--nf-gray-100)', 'fg' => 'var(--nf-gray-600)'];
    }
}
