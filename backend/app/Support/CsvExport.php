<?php

namespace App\Support;

class CsvExport
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    public static function lines(array $headers, array $rows): array
    {
        $lines = [self::line($headers)];

        foreach ($rows as $row) {
            $lines[] = self::line(array_map(
                fn (string $header): mixed => $row[$header] ?? null,
                $headers,
            ));
        }

        return $lines;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    public static function line(array $values): string
    {
        $stream = fopen('php://temp', 'r+');

        fputcsv(
            $stream,
            array_map(fn (mixed $value): string => self::stringValue($value), $values),
            ',',
            '"',
            '',
            "\n",
        );

        rewind($stream);

        return rtrim((string) stream_get_contents($stream), "\r\n");
    }

    private static function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }
}
