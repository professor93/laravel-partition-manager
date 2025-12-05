<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

use DateTime;
use Exception;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\Exceptions\PartitionException;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Database\DatabaseManager;

class PartitionManager
{
    use SqlHelper;
    public function __construct(
        protected readonly DatabaseManager $db,
    ) {}

    /**
     * @return array<int, object>
     */
    public function getPartitions(string $table, ?string $connection = null): array
    {
        $conn = $this->resolveConnection($connection);

        return $this->db->connection($conn)->select("
            SELECT
                inhrelid::regclass AS partition_name,
                pg_get_expr(relpartbound, inhrelid) AS partition_expression,
                pg_size_pretty(pg_relation_size(inhrelid)) AS size,
                pg_stat_get_live_tuples(inhrelid) AS row_count
            FROM pg_inherits
            JOIN pg_class ON pg_inherits.inhrelid = pg_class.oid
            WHERE inhparent = ?::regclass
            ORDER BY inhrelid::regclass::text
        ", [$table]);
    }

    public function getPartitionInfo(string $table, string $partitionName, ?string $connection = null): ?object
    {
        $conn = $this->resolveConnection($connection);

        return $this->db->connection($conn)->selectOne("
            SELECT
                c.relname AS partition_name,
                pg_get_expr(c.relpartbound, c.oid) AS partition_expression,
                pg_size_pretty(pg_relation_size(c.oid)) AS size,
                pg_stat_get_live_tuples(c.oid) AS row_count,
                n.nspname AS schema_name,
                t.spcname AS tablespace
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            LEFT JOIN pg_tablespace t ON t.oid = c.reltablespace
            WHERE c.relname = ?
        ", [$partitionName]);
    }

    public function isPartitioned(string $table, ?string $connection = null): bool
    {
        $conn = $this->resolveConnection($connection);

        $result = $this->db->connection($conn)->select("
            SELECT relkind
            FROM pg_class
            WHERE relname = ?
            AND relkind = 'p'
        ", [$table]);

        return $result !== [];
    }

    public function getPartitionStrategy(string $table, ?string $connection = null): ?PartitionType
    {
        $conn = $this->resolveConnection($connection);

        $result = $this->db->connection($conn)->selectOne("
            SELECT partstrat
            FROM pg_partitioned_table pt
            JOIN pg_class c ON c.oid = pt.partrelid
            WHERE c.relname = ?
        ", [$table]);

        if ($result === null) {
            return null;
        }

        return PartitionType::fromPgStrategy($result->partstrat);
    }

    /**
     * @return array<int, object>
     */
    public function getPartitionColumns(string $table, ?string $connection = null): array
    {
        $conn = $this->resolveConnection($connection);

        return $this->db->connection($conn)->select("
            SELECT
                a.attname AS column_name,
                pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type
            FROM pg_partitioned_table pt
            JOIN pg_class c ON c.oid = pt.partrelid
            JOIN pg_attribute a ON a.attrelid = c.oid
            WHERE c.relname = ?
            AND a.attnum = ANY(pt.partattrs)
            ORDER BY a.attnum
        ", [$table]);
    }

    public function analyzePartition(string $partitionName, ?string $connection = null): void
    {
        $conn = $this->resolveConnection($connection);
        $quotedPartition = self::quoteIdentifier($partitionName);
        $this->db->connection($conn)->statement("ANALYZE {$quotedPartition}");
    }

    public function vacuumPartition(string $partitionName, bool $full = false, ?string $connection = null): void
    {
        $conn = $this->resolveConnection($connection);
        $quotedPartition = self::quoteIdentifier($partitionName);
        $sql = $full ? "VACUUM FULL {$quotedPartition}" : "VACUUM {$quotedPartition}";
        $this->db->connection($conn)->statement($sql);
    }

    /**
     * @return array<int, string>
     */
    public function dropOldPartitions(string $table, DateTime $before, ?string $connection = null): array
    {
        $conn = $this->resolveConnection($connection);
        $dropped = [];

        $partitions = $this->getPartitions($table, $conn);

        foreach ($partitions as $partition) {
            if ($this->shouldDropPartition($partition, $before)) {
                try {
                    $quotedPartition = self::quoteIdentifier($partition->partition_name);
                    $this->db->connection($conn)->statement(
                        "DROP TABLE IF EXISTS {$quotedPartition} CASCADE"
                    );
                    $dropped[] = $partition->partition_name;

                    if (config('partition-manager.defaults.vacuum_after_drop', true)) {
                        $this->vacuumPartition($table, false, $conn);
                    }
                } catch (Exception $e) {
                    throw new PartitionException(
                        "Failed to drop partition {$partition->partition_name}: " . $e->getMessage(),
                        previous: $e
                    );
                }
            }
        }

        return $dropped;
    }

    protected function shouldDropPartition(object $partition, DateTime $before): bool
    {
        if (preg_match('/FROM \(\'(\d{4}-\d{2}-\d{2})\'\)/', $partition->partition_expression, $matches)) {
            $partitionDate = new DateTime($matches[1]);

            return $partitionDate < $before;
        }

        return false;
    }

    public function getTableSize(string $table, ?string $connection = null): string
    {
        $conn = $this->resolveConnection($connection);

        $result = $this->db->connection($conn)->selectOne("
            SELECT pg_size_pretty(pg_total_relation_size(?::regclass)) AS size
        ", [$table]);

        return $result->size ?? '0 bytes';
    }

    public function getPartitionCount(string $table, ?string $connection = null): int
    {
        $conn = $this->resolveConnection($connection);

        $result = $this->db->connection($conn)->selectOne("
            SELECT COUNT(*) as count
            FROM pg_inherits
            WHERE inhparent = ?::regclass
        ", [$table]);

        return (int) ($result->count ?? 0);
    }

    public function getOldestPartition(string $table, ?string $connection = null): ?object
    {
        $partitions = $this->getPartitions($table, $connection);

        return $partitions[0] ?? null;
    }

    public function getNewestPartition(string $table, ?string $connection = null): ?object
    {
        $partitions = $this->getPartitions($table, $connection);

        $last = end($partitions);

        return $last !== false ? $last : null;
    }

    private function resolveConnection(?string $connection): string
    {
        return $connection ?? config('partition-manager.default_connection', 'pgsql');
    }
}