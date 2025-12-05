<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Service for PostgreSQL schema management.
 *
 * Handles schema creation for partitions with caching to avoid duplicate queries.
 */
class SchemaCreator
{
    use SqlHelper;

    /**
     * Track schemas that have been created in this request.
     *
     * @var array<string, bool>
     */
    protected static array $createdSchemas = [];

    /**
     * Ensure a schema exists, creating it if necessary.
     *
     * @param string $schema The schema name to ensure exists
     * @param Connection|null $connection Optional database connection
     * @return void
     */
    public static function ensure(string $schema, ?Connection $connection = null): void
    {
        $cacheKey = self::getCacheKey($schema, $connection);
        if (isset(self::$createdSchemas[$cacheKey])) {
            return;
        }

        $conn = $connection ?? DB::connection();
        $quotedSchema = self::quoteIdentifier($schema);

        $conn->statement("CREATE SCHEMA IF NOT EXISTS {$quotedSchema}");

        self::$createdSchemas[$cacheKey] = true;
    }

    /**
     * Ensure a schema exists and return the full table name with schema prefix.
     *
     * @param string $tableName The table name
     * @param string|null $schema The schema name (optional)
     * @param Connection|null $connection Optional database connection
     * @return string The full table name (schema.table or just table)
     */
    public static function ensureAndPrefix(string $tableName, ?string $schema, ?Connection $connection = null): string
    {
        if ($schema === null) {
            return $tableName;
        }

        self::ensure($schema, $connection);

        return "{$schema}.{$tableName}";
    }

    /**
     * Clear the created schemas cache.
     *
     * Useful for testing or when you need to force re-checking schemas.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$createdSchemas = [];
    }

    /**
     * Generate a cache key for a schema and connection.
     *
     * @param string $schema The schema name
     * @param Connection|null $connection The database connection
     * @return string The cache key
     */
    protected static function getCacheKey(string $schema, ?Connection $connection): string
    {
        $connectionName = $connection?->getName() ?? 'default';

        return "{$connectionName}.{$schema}";
    }
}
