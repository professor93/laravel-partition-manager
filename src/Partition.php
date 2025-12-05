<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager;

use Closure;
use Uzbek\LaravelPartitionManager\Builders\PostgresPartitionBuilder;
use Uzbek\LaravelPartitionManager\Builders\QuickPartitionBuilder;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Support\Facades\DB;

/**
 * Main entry point for partition operations.
 *
 * Provides a clean, fluent API for creating and managing PostgreSQL partitioned tables.
 *
 * @example Creating a new partitioned table:
 * ```php
 * Partition::create('orders', function (Blueprint $table) {
 *     $table->id();
 *     $table->date('created_at');
 * })->by('created_at')->monthly();  // Terminal - executes immediately
 * ```
 *
 * @example Adding partitions to an existing table:
 * ```php
 * Partition::for('orders')->by('created_at')->monthly(12);
 * ```
 *
 * @example Quick partition creation:
 * ```php
 * Partition::monthly('orders', 'created_at', 12);
 * ```
 */
class Partition
{
    use SqlHelper;

    /**
     * Create a new partitioned table with full control over schema.
     *
     * This is the primary method for creating new partitioned tables.
     * Use the callback to define the table structure using Laravel's Blueprint.
     *
     * @param string $table The table name
     * @param Closure $callback Callback to define table structure
     * @return PostgresPartitionBuilder The builder for method chaining
     */
    public static function create(string $table, Closure $callback): PostgresPartitionBuilder
    {
        $builder = new PostgresPartitionBuilder($table);
        $builder->defineTable($callback);

        return $builder;
    }

    /**
     * Get a quick partition builder for an existing table.
     *
     * Use this to add partitions to a table that already exists.
     *
     * @param string $table The existing table name
     * @return QuickPartitionBuilder The builder for method chaining
     *
     * @example
     * ```php
     * Partition::for('orders')->by('created_at')->monthly(12);
     * ```
     */
    public static function for(string $table): QuickPartitionBuilder
    {
        return QuickPartitionBuilder::table($table);
    }

    /**
     * Drop a table if it exists (with CASCADE).
     *
     * @param string $table The table name to drop
     * @return void
     */
    public static function dropIfExists(string $table): void
    {
        $quotedTable = self::quoteIdentifier($table);
        DB::statement("DROP TABLE IF EXISTS {$quotedTable} CASCADE");
    }

    /**
     * Check if a specific partition exists.
     *
     * @param string $table The parent table name
     * @param string $partitionName The partition name (suffix)
     * @return bool True if the partition exists
     */
    public static function partitionExists(string $table, string $partitionName): bool
    {
        $fullName = "{$table}_{$partitionName}";
        /** @var array<int, object{exists: bool}> $result */
        $result = DB::select("
            SELECT EXISTS (
                SELECT FROM pg_tables
                WHERE tablename = ?
            ) as exists
        ", [$fullName]);

        return $result[0]->exists ?? false;
    }

    /**
     * Get all partitions for a table.
     *
     * @param string $table The parent table name
     * @return array<int, object> Array of partition information
     */
    public static function getPartitions(string $table): array
    {
        return DB::select("
            SELECT
                inhrelid::regclass AS partition_name,
                pg_get_expr(relpartbound, inhrelid) AS partition_expression
            FROM pg_inherits
            JOIN pg_class ON pg_inherits.inhrelid = pg_class.oid
            WHERE inhparent = ?::regclass
            ORDER BY inhrelid::regclass::text
        ", [$table]);
    }

    /**
     * Check if a table is partitioned.
     *
     * @param string $table The table name to check
     * @return bool True if the table is partitioned
     */
    public static function isPartitioned(string $table): bool
    {
        $result = DB::select("
            SELECT relkind
            FROM pg_class
            WHERE relname = ?
            AND relkind = 'p'
        ", [$table]);

        return $result !== [];
    }

    /**
     * Quick method to create monthly partitions.
     *
     * @param string $table The existing partitioned table name
     * @param string $column The partition column
     * @param int $count Number of monthly partitions to create
     * @return void
     */
    public static function monthly(string $table, string $column, int $count = 12): void
    {
        static::for($table)->by($column)->monthly($count);
    }

    /**
     * Quick method to create yearly partitions.
     *
     * @param string $table The existing partitioned table name
     * @param string $column The partition column
     * @param int $count Number of yearly partitions to create
     * @return void
     */
    public static function yearly(string $table, string $column, int $count = 5): void
    {
        static::for($table)->by($column)->yearly($count);
    }

    /**
     * Quick method to create daily partitions.
     *
     * @param string $table The existing partitioned table name
     * @param string $column The partition column
     * @param int $count Number of daily partitions to create
     * @return void
     */
    public static function daily(string $table, string $column, int $count = 30): void
    {
        static::for($table)->by($column)->daily($count);
    }

    /**
     * Quick method to create weekly partitions.
     *
     * @param string $table The existing partitioned table name
     * @param string $column The partition column
     * @param int $count Number of weekly partitions to create
     * @return void
     */
    public static function weekly(string $table, string $column, int $count = 12): void
    {
        static::for($table)->by($column)->weekly($count);
    }

    /**
     * Quick method to create quarterly partitions.
     *
     * @param string $table The existing partitioned table name
     * @param string $column The partition column
     * @param int $count Number of quarterly partitions to create
     * @return void
     */
    public static function quarterly(string $table, string $column, int $count = 8): void
    {
        static::for($table)->by($column)->quarterly($count);
    }
}