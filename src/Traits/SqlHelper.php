<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Traits;

use DateTime;
use DateTimeInterface;

/**
 * Provides common SQL utility methods for partition operations.
 *
 * This trait centralizes SQL formatting and quoting logic to avoid code
 * duplication across partition builders and managers.
 */
trait SqlHelper
{
    /**
     * Quote a PostgreSQL identifier to prevent SQL injection and handle special characters.
     *
     * Handles both simple identifiers and schema.table format.
     *
     * @param string $identifier The identifier to quote (table name, column name, schema, etc.)
     * @return string The quoted identifier
     *
     * @example
     * quoteIdentifier('users') → "users"
     * quoteIdentifier('public.users') → "public"."users"
     * quoteIdentifier('my"table') → "my""table"
     */
    protected static function quoteIdentifier(string $identifier): string
    {
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);

            return implode('.', array_map(
                static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"',
                $parts
            ));
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Format a value for use in SQL partition bounds.
     *
     * Handles various PHP types and converts them to PostgreSQL-compatible SQL values.
     * Supports arrays for multi-column partitioning.
     *
     * @param mixed $value The value to format
     * @return string The SQL-formatted value
     *
     * @example
     * formatSqlValue('2024-01-01') → '2024-01-01'
     * formatSqlValue(123) → 123
     * formatSqlValue(true) → true
     * formatSqlValue('MINVALUE') → MINVALUE
     * formatSqlValue(['2024-01-01', 100]) → '2024-01-01', 100
     * formatSqlValue(new DateTime('2024-01-01')) → '2024-01-01'
     */
    protected static function formatSqlValue(mixed $value): string
    {
        // Handle arrays for multi-column partitioning
        if (is_array($value)) {
            return implode(', ', array_map(
                static fn (mixed $v): string => static::formatSqlValue($v),
                $value
            ));
        }

        // Handle PostgreSQL partition bound keywords (must be unquoted)
        if ($value === 'MINVALUE' || $value === 'MAXVALUE') {
            return $value;
        }

        // Handle DateTime objects
        if ($value instanceof DateTimeInterface) {
            return "'" . $value->format('Y-m-d') . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        // Escape single quotes in string values
        $escaped = str_replace("'", "''", (string) $value);

        return "'" . $escaped . "'";
    }
}
