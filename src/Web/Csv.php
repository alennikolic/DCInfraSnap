<?php
// src/Web/Csv.php
//
// CSV export, prepared for the spreadsheet it will actually be opened in.
//
// Four things that would otherwise go wrong in Excel:
//
//   BOM          without it, anything outside ASCII arrives mangled
//   sep=;        without it, some locales put the whole row in one cell
//   formulas     a cell starting with = + - or @ is EXECUTED on open, so a
//                machine named "=cmd" becomes a formula; prefixing with an
//                apostrophe stops that
//   raw numbers  measurements are exported in base units as whole numbers.
//                "4.5 GiB" arrives as text in a locale that uses a comma for
//                decimals, and then nothing can be calculated from it
//
// Rows are streamed rather than assembled. The export is the one page with
// no page size, so holding the whole inventory in memory and then a string
// built from it is how this falls over on the largest inventory — which is
// the one that most needs exporting.

declare(strict_types=1);

namespace DcInfraSnap\Web;

use Generator;

final class Csv
{
    /**
     * Send a CSV file to the browser and stop.
     *
     * @param list<string>                                   $headers
     * @param Generator<array<string, mixed>>|iterable        $rows
     * @param callable(array<string, mixed>): list<mixed>     $mapper
     */
    public static function send(string $filename, array $headers, iterable $rows, callable $mapper): never
    {
        // Any buffering in front of this would hold the whole file in memory
        // and defeat the streaming.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            exit;
        }

        // UTF-8 BOM, then the separator hint. Both are read by Excel and
        // ignored as data by everything else.
        fwrite($out, "\xEF\xBB\xBF");
        fwrite($out, "sep=;\n");

        fputcsv($out, $headers, ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(self::safe(...), $mapper($row)), ';', '"', '');
        }

        fclose($out);
        exit;
    }

    /**
     * A value that a spreadsheet will not execute.
     */
    private static function safe(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;

        if ($text !== '' && str_contains('=+-@', $text[0])) {
            return "'" . $text;
        }

        // Line breaks inside a cell are legal but make the file hard to
        // read; notes fields are full of them.
        return str_replace(["\r\n", "\r", "\n"], ' ', $text);
    }

    /**
     * A filename carrying the moment it was produced.
     */
    public static function filename(string $prefix): string
    {
        return $prefix . '-' . gmdate('Y-m-d-Hi') . '.csv';
    }
}
