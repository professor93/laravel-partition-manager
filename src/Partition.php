<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager;

use Closure;
use Uzbek\LaravelPartitionManager\Builders\PostgresPartitionBuilder;
use Uzbek\LaravelPartitionManager\Builders\QuickPartitionBuilder;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Support\Facades\DB;

class Partition
{
    use SqlHelper;

    public static function create(string $table, Closure $callback): PostgresPartitionBuilder
    {
        $builder = new PostgresPartitionBuilder($table);
        $builder->defineTable($callback);

        return $builder;
    }

    public static function for(string $table): QuickPartitionBuilder
    {
        return QuickPartitionBuilder::table($table);
    }

    public static function dropIfExists(string $table, bool $withSchema = false): void
    {
        $schemas = [];
        if ($withSchema) {
            foreach (self::getPartitions($table) as $partition) {
                $name = $partition->partition_name;
                if (str_contains($name, '.')) {
                    $schemas[explode('.', $name, 2)[0]] = true;
                }
            }
        }

        DB::statement("DROP TABLE IF EXISTS " . self::quoteIdentifier($table) . " CASCADE");

        foreach (array_keys($schemas) as $schema) {
            self::dropSchemaIfEmpty($schema);
        }
    }

    public static function dropSchemaIfEmpty(string $schema): void
    {
        $result = DB::select("
            SELECT COUNT(*) as count
            FROM pg_tables
            WHERE schemaname = ?
        ", [$schema]);

        if (($result[0]->count ?? 0) === 0) {
            self::dropSchemaIfExists($schema);
        }
    }

    public static function dropSchemaIfExists(string $schema): void
    {
        DB::statement("DROP SCHEMA IF EXISTS " . self::quoteIdentifier($schema) . " CASCADE");
    }

    public static function partitionExists(string $table, string $partitionName): bool
    {
        $fullName = "{$table}_{$partitionName}";
        $result = DB::select("
            SELECT EXISTS (
                SELECT FROM pg_tables
                WHERE tablename = ?
            ) as exists
        ", [$fullName]);

        return $result[0]->exists ?? false;
    }

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

    // Quick partition creation
    public static function monthly(string $table, string $column, int $count = 12): void
    {
        static::for($table)->by($column)->monthly($count);
    }

    public static function yearly(string $table, string $column, int $count = 5): void
    {
        static::for($table)->by($column)->yearly($count);
    }

    public static function daily(string $table, string $column, int $count = 30): void
    {
        static::for($table)->by($column)->daily($count);
    }

    public static function weekly(string $table, string $column, int $count = 12): void
    {
        static::for($table)->by($column)->weekly($count);
    }

    public static function quarterly(string $table, string $column, int $count = 8): void
    {
        static::for($table)->by($column)->quarterly($count);
    }

    public static function detachPartition(string $table, string $partitionName, bool $concurrently = false): void
    {
        $quotedTable = self::quoteIdentifier($table);
        $quotedPartition = self::quoteIdentifier($partitionName);

        $sql = "ALTER TABLE {$quotedTable} DETACH PARTITION {$quotedPartition}";
        if ($concurrently) {
            $sql .= " CONCURRENTLY";
        }

        DB::statement($sql);
    }

    public static function attachPartition(string $table, string $partitionName, mixed $from, mixed $to): void
    {
        $quotedTable = self::quoteIdentifier($table);
        $quotedPartition = self::quoteIdentifier($partitionName);
        $fromValue = self::formatSqlValue($from);
        $toValue = self::formatSqlValue($to);

        DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedPartition} FOR VALUES FROM ({$fromValue}) TO ({$toValue})");
    }

    /**
     * Attach a LIST partition.
     *
     * @param array<int, mixed> $values
     */
    public static function attachListPartition(string $table, string $partitionName, array $values): void
    {
        $quotedTable = self::quoteIdentifier($table);
        $quotedPartition = self::quoteIdentifier($partitionName);
        $valueList = implode(', ', array_map(
            static fn (mixed $v): string => self::formatSqlValue($v),
            $values
        ));

        DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedPartition} FOR VALUES IN ({$valueList})");
    }

    /**
     * Attach a HASH partition.
     */
    public static function attachHashPartition(string $table, string $partitionName, int $modulus, int $remainder): void
    {
        $quotedTable = self::quoteIdentifier($table);
        $quotedPartition = self::quoteIdentifier($partitionName);

        DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedPartition} FOR VALUES WITH (modulus {$modulus}, remainder {$remainder})");
    }
}
