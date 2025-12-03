<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager;

use Closure;
use Uzbek\LaravelPartitionManager\Builders\PostgresPartitionBuilder;
use Uzbek\LaravelPartitionManager\Builders\QuickPartitionBuilder;
use Illuminate\Support\Facades\DB;

class Partition
{
    public static function create(string $table, Closure $callback): PostgresPartitionBuilder
    {
        $builder = new PostgresPartitionBuilder($table);
        $builder->defineTable($callback);

        return $builder;
    }

    public static function table(string $table, Closure $callback): PostgresPartitionBuilder
    {
        return static::create($table, $callback);
    }

    public static function dropIfExists(string $table): void
    {
        DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
    }

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
     * @return array<int, object>
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

    public static function generate(string $table): QuickPartitionBuilder
    {
        return QuickPartitionBuilder::table($table);
    }

    public static function monthly(string $table, string $column, int $count = 12): void
    {
        QuickPartitionBuilder::table($table)->by($column)->monthly($count);
    }

    public static function yearly(string $table, string $column, int $count = 5): void
    {
        QuickPartitionBuilder::table($table)->by($column)->yearly($count);
    }

    public static function daily(string $table, string $column, int $count = 30): void
    {
        QuickPartitionBuilder::table($table)->by($column)->daily($count);
    }

    public static function weekly(string $table, string $column, int $count = 12): void
    {
        QuickPartitionBuilder::table($table)->by($column)->weekly($count);
    }

    public static function quarterly(string $table, string $column, int $count = 8): void
    {
        QuickPartitionBuilder::table($table)->by($column)->quarterly($count);
    }
}