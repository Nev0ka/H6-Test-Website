<?php

declare(strict_types=1);

namespace App;

/** da-DK number/date formatting — no dependency on ext-intl. */
final class Formatting
{
    public static function number(float $n, int $decimals = 0): string
    {
        return number_format($n, $decimals, ',', '.');
    }

    public static function dateTime(\DateTimeInterface $dt): string
    {
        return $dt->format('d.m.Y H:i');
    }

    public static function time(\DateTimeInterface $dt): string
    {
        return $dt->format('H:i');
    }

    public static function date(\DateTimeInterface $dt): string
    {
        return $dt->format('d.m.Y');
    }

    public static function uptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        return "{$days} dage {$hours} timer";
    }
}
