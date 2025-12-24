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
 *
 * IMPORTANT: For long-running processes (Swoole, Octane, RoadRunner), the cache
 * is automatically cleared between requests via the service provider. If you're
 * using a custom worker, call SchemaCreator::flush() between requests.
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
     * Whether cache flushing is enabled (for long-running processes).
     */
    protected static bool $flushEnabled = true;

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
     * This method should be called between requests in long-running processes
     * (Swoole, Octane, RoadRunner) to prevent stale cache issues.
     *
     * The service provider automatically registers this for Laravel Octane.
     *
     * @return void
     */
    public static function flush(): void
    {
        if (self::$flushEnabled) {
            self::$createdSchemas = [];
        }
    }

    /**
     * Clear the created schemas cache.
     *
     * @deprecated Use flush() instead
     * @return void
     */
    public static function clearCache(): void
    {
        self::$createdSchemas = [];
    }

    /**
     * Enable or disable automatic cache flushing.
     *
     * @param bool $enabled Whether to enable flushing
     * @return void
     */
    public static function setFlushEnabled(bool $enabled): void
    {
        self::$flushEnabled = $enabled;
    }

    /**
     * Check if the cache contains a specific schema.
     *
     * @param string $schema The schema name
     * @param Connection|null $connection The database connection
     * @return bool Whether the schema is in the cache
     */
    public static function isCached(string $schema, ?Connection $connection = null): bool
    {
        return isset(self::$createdSchemas[self::getCacheKey($schema, $connection)]);
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
