<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Traits;

use Illuminate\Database\Eloquent\Builder;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionStats;

/**
 * Trait for Eloquent models backed by partitioned tables.
 *
 * Provides partition-aware query methods and access to partition metadata.
 *
 * Usage:
 *     class Order extends Model
 *     {
 *         use HasPartitions;
 *
 *         protected static string $partitionColumn = 'created_at';
 *     }
 *
 *     // Query specific partition
 *     Order::inPartition('orders_m2024_01')->get();
 *
 *     // Get partition info
 *     Order::getPartitions();
 *     Order::getPartitionStrategy();
 */
trait HasPartitions
{
    /**
     * Get the partition column name.
     *
     * Override this in your model if different from 'created_at'.
     */
    public static function getPartitionColumn(): string
    {
        return static::$partitionColumn ?? 'created_at';
    }

    /**
     * Check if the model's table is partitioned.
     */
    public static function isPartitioned(): bool
    {
        return Partition::isPartitioned((new static)->getTable());
    }

    /**
     * Get all partitions for this model's table.
     *
     * @return array<int, object>
     */
    public static function getPartitions(): array
    {
        return Partition::getPartitions((new static)->getTable());
    }

    /**
     * Get the partition strategy (RANGE, LIST, HASH).
     */
    public static function getPartitionStrategy(): ?string
    {
        return PartitionStats::getPartitionStrategy((new static)->getTable());
    }

    /**
     * Get partition statistics for this model's table.
     *
     * @return array<int, object>
     */
    public static function getPartitionStats(): array
    {
        return PartitionStats::get((new static)->getTable());
    }

    /**
     * Get partition boundaries for this model's table.
     *
     * @return array<int, object>
     */
    public static function getPartitionBoundaries(): array
    {
        return PartitionStats::boundaries((new static)->getTable());
    }

    /**
     * Run a health check on the model's partitions.
     *
     * @return array{gaps: array, overlaps: array, missing_indexes: array, orphan_data: bool}
     */
    public static function partitionHealthCheck(): array
    {
        return PartitionStats::healthCheck((new static)->getTable());
    }

    /**
     * Get estimated total row count across all partitions.
     */
    public static function estimateTotalRows(): int
    {
        return PartitionStats::estimateTotalRowCount((new static)->getTable());
    }

    /**
     * Scope query to a specific partition by name.
     *
     * This uses PostgreSQL's ONLY keyword to query only the specified partition,
     * bypassing the parent table and partition pruning.
     *
     * @param Builder $query
     * @param string $partitionName The partition name (e.g., 'orders_m2024_01')
     */
    public function scopeInPartition(Builder $query, string $partitionName): Builder
    {
        // Use raw FROM clause to query partition directly
        return $query->from($partitionName);
    }

    /**
     * Scope query to partitions within a date range (for RANGE partitions).
     *
     * This adds WHERE clause on the partition column to enable partition pruning.
     *
     * @param Builder $query
     * @param string $from Start date/datetime
     * @param string $to End date/datetime
     */
    public function scopeInPartitionRange(Builder $query, string $from, string $to): Builder
    {
        $column = static::getPartitionColumn();

        return $query->whereBetween($column, [$from, $to]);
    }

    /**
     * Scope query to a specific partition value (for LIST partitions).
     *
     * @param Builder $query
     * @param mixed $value The partition key value
     */
    public function scopeInPartitionValue(Builder $query, mixed $value): Builder
    {
        $column = static::getPartitionColumn();

        return $query->where($column, $value);
    }

    /**
     * Scope query to multiple partition values (for LIST partitions).
     *
     * @param Builder $query
     * @param array<int, mixed> $values The partition key values
     */
    public function scopeInPartitionValues(Builder $query, array $values): Builder
    {
        $column = static::getPartitionColumn();

        return $query->whereIn($column, $values);
    }

    /**
     * Get the partition name for a specific date (for RANGE partitions).
     *
     * Returns null if no matching partition is found.
     */
    public static function getPartitionForDate(string $date): ?string
    {
        $boundaries = static::getPartitionBoundaries();

        foreach ($boundaries as $boundary) {
            if ($boundary->partition_type !== 'RANGE') {
                continue;
            }

            if ($boundary->from_value <= $date && $date < $boundary->to_value) {
                return $boundary->partition_name;
            }
        }

        return null;
    }

    /**
     * Get the partition name for a specific value (for LIST partitions).
     *
     * Returns null if no matching partition is found.
     */
    public static function getPartitionForValue(mixed $value): ?string
    {
        $table = (new static)->getTable();
        $partitions = Partition::getPartitions($table);

        foreach ($partitions as $partition) {
            $bounds = PartitionStats::parseBoundaries($partition->partition_expression);

            if ($bounds === null || $bounds['type'] !== 'LIST') {
                continue;
            }

            // Parse the values string to check for match
            $valuesStr = $bounds['values'] ?? '';
            $stringValue = is_string($value) ? "'{$value}'" : (string) $value;

            if (str_contains($valuesStr, $stringValue)) {
                return $partition->partition_name;
            }
        }

        return null;
    }

    /**
     * Ensure a partition exists for the given date.
     *
     * If no partition exists for the date, this returns false.
     * Useful for checking before inserts.
     */
    public static function hasPartitionForDate(string $date): bool
    {
        return static::getPartitionForDate($date) !== null;
    }

    /**
     * Get a query builder that explains partition pruning for the query.
     *
     * Returns information about which partitions will be scanned.
     *
     * @param Builder|null $query Optional query to analyze (uses new query if null)
     * @return array{partitions_scanned: array, total_partitions: int, pruning_effective: bool, plan: string}
     */
    public static function explainPartitionPruning(?Builder $query = null): array
    {
        $query = $query ?? static::query();
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        return PartitionStats::explainPruning($sql, $bindings);
    }

    /**
     * Print the partition tree for this model's table.
     */
    public static function printPartitionTree(): string
    {
        return PartitionStats::printTree((new static)->getTable());
    }

    /**
     * Count rows in a specific partition.
     */
    public static function countInPartition(string $partitionName): int
    {
        return static::query()->from($partitionName)->count();
    }

    /**
     * Get the oldest partition (by boundary, for RANGE partitions).
     */
    public static function getOldestPartition(): ?object
    {
        $boundaries = static::getPartitionBoundaries();
        $oldest = null;

        foreach ($boundaries as $boundary) {
            if ($boundary->partition_type !== 'RANGE') {
                continue;
            }

            if ($oldest === null || $boundary->from_value < $oldest->from_value) {
                $oldest = $boundary;
            }
        }

        return $oldest;
    }

    /**
     * Get the newest partition (by boundary, for RANGE partitions).
     */
    public static function getNewestPartition(): ?object
    {
        $boundaries = static::getPartitionBoundaries();
        $newest = null;

        foreach ($boundaries as $boundary) {
            if ($boundary->partition_type !== 'RANGE') {
                continue;
            }

            if ($newest === null || $boundary->to_value > $newest->to_value) {
                $newest = $boundary;
            }
        }

        return $newest;
    }
}
