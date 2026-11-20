<?php

namespace App\Support;

use Illuminate\Http\Response;

/** A private CSV attachment built in memory, so tenant-scoped rows are read inside the request's transaction. */
class CsvDownload
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function make(string $filename, array $header, iterable $rows): Response
    {
        $file = fopen('php://temp', 'w+');
        fputcsv($file, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($file, array_map(self::cell(...), $row), escape: '');
        }
        rewind($file);
        $csv = (string) stream_get_contents($file);
        fclose($file);

        return response($csv)->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    /** Spreadsheet formula characters at the start of a value are neutralized. */
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
