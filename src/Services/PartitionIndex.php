<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

use Uzbek\LaravelPartitionManager\Exceptions\PartitionException;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Support\Facades\DB;

class PartitionIndex
{
    use SqlHelper;

    private const ALLOWED_INDEX_METHODS = ['btree', 'hash', 'gist', 'spgist', 'gin', 'brin'];

    public static function create(
        string $table,
        string $indexName,
        array $columns,
        bool $unique = false,
        ?string $method = null
    ): void {
        self::validateIndexMethod($method);

        $quotedTable = self::quoteIdentifier($table);
        $quotedColumns = implode(', ', array_map([self::class, 'quoteIdentifier'], $columns));
        $uniqueStr = $unique ? 'UNIQUE ' : '';
        $methodStr = $method ? 'USING ' . strtoupper($method) . ' ' : '';

        DB::statement("CREATE {$uniqueStr}INDEX IF NOT EXISTS {$indexName} ON {$quotedTable} {$methodStr}({$quotedColumns})");
    }

    public static function createConcurrently(
        string $table,
        string $indexName,
        array $columns,
        bool $unique = false,
        ?string $method = null
    ): void {
        self::validateIndexMethod($method);

        $quotedTable = self::quoteIdentifier($table);
        $quotedColumns = implode(', ', array_map([self::class, 'quoteIdentifier'], $columns));
        $uniqueStr = $unique ? 'UNIQUE ' : '';
        $methodStr = $method ? 'USING ' . strtoupper($method) . ' ' : '';

        DB::statement("CREATE {$uniqueStr}INDEX CONCURRENTLY IF NOT EXISTS {$indexName} ON {$quotedTable} {$methodStr}({$quotedColumns})");
    }

    public static function drop(string $indexName, bool $cascade = false): void
    {
        $sql = "DROP INDEX IF EXISTS " . self::quoteIdentifier($indexName);
        if ($cascade) {
            $sql .= " CASCADE";
        }
        DB::statement($sql);
    }

    public static function dropConcurrently(string $indexName): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS " . self::quoteIdentifier($indexName));
    }

    public static function list(string $partition): array
    {
        return DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = ?", [$partition]);
    }

    private static function validateIndexMethod(?string $method): void
    {
        if ($method === null) {
            return;
        }

        if (!in_array(strtolower($method), self::ALLOWED_INDEX_METHODS, true)) {
            throw new PartitionException(
                "Invalid index method: {$method}. Allowed: " . implode(', ', self::ALLOWED_INDEX_METHODS)
            );
        }
    }
}
