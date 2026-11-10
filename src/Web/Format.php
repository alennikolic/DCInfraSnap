<?php
// src/Web/Format.php
//
// Turning stored values into something readable.
//
// Measurements are stored in base units as whole numbers, which is right for
// the database and unreadable on a page. This is the one place that converts
// them, so 549755813888 reads as 512 GiB everywhere it appears rather than
// differently on each page.
//
// Nothing here escapes anything: these produce plain text, and the template
// puts it through $e() like any other value.

declare(strict_types=1);

namespace DcInfraSnap\Web;

final class Format
{
    /** Binary units, because that is what vCenter reports. */
    public static function bytes(mixed $bytes, int $decimals = 1): string
    {
        if ($bytes === null || $bytes === '' || !is_numeric($bytes)) {
            return '—';
        }

        $value = (float) $bytes;

        if ($value <= 0) {
            return '0';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $index <= 1 ? 0 : $decimals, '.', ' ') . ' ' . $units[$index];
    }

    /** Memory is stored in mebibytes for machines. */
    public static function mib(mixed $mib): string
    {
        if ($mib === null || !is_numeric($mib)) {
            return '—';
        }

        return self::bytes((int) $mib * 1024 * 1024);
    }

    public static function number(mixed $value): string
    {
        if ($value === null || !is_numeric($value)) {
            return '—';
        }

        return number_format((float) $value, 0, '.', ' ');
    }

    /**
     * A duration in seconds as "12d 4h".
     */
    public static function duration(mixed $seconds): string
    {
        if ($seconds === null || !is_numeric($seconds)) {
            return '—';
        }

        $seconds = (int) $seconds;

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    /**
     * How long ago, from a stored UTC timestamp.
     *
     * Relative rather than absolute because the question being asked of
     * last_seen is almost always "is this current?", and "3 minutes ago"
     * answers it without arithmetic.
     */
    public static function ago(mixed $utc): string
    {
        if (!is_string($utc) || $utc === '') {
            return '—';
        }

        $then = strtotime($utc . ' UTC');

        if ($then === false) {
            return (string) $utc;
        }

        $seconds = time() - $then;

        if ($seconds < 0) {
            return 'just now';
        }

        if ($seconds < 60) {
            return 'just now';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min ago';
        }

        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' h ago';
        }

        return intdiv($seconds, 86400) . ' d ago';
    }

    /**
     * A yes/no column stored as 0, 1 or NULL.
     */
    public static function flag(mixed $value, string $yes = 'Yes', string $no = 'No'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return (int) $value === 1 ? $yes : $no;
    }

    /**
     * POWERED_ON -> Powered on, for display only.
     */
    public static function state(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '—';
        }

        return ucfirst(strtolower(str_replace('_', ' ', $value)));
    }

    /**
     * How full something is, as a percentage of capacity.
     */
    public static function usedPercent(mixed $capacity, mixed $free): ?int
    {
        if (!is_numeric($capacity) || !is_numeric($free) || (float) $capacity <= 0) {
            return null;
        }

        $used = ((float) $capacity - (float) $free) / (float) $capacity * 100;

        return (int) round(max(0, min(100, $used)));
    }
}
