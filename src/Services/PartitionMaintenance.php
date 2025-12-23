<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

use Uzbek\LaravelPartitionManager\Builders\AbstractSubPartitionBuilder;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Support\Facades\DB;

class PartitionMaintenance
{
    use SqlHelper;

    public static function vacuum(string $partition, bool $full = false, bool $analyze = false): void
    {
        $quotedPartition = self::quoteIdentifier($partition);

        if ($full) {
            DB::statement("VACUUM FULL {$quotedPartition}");
        } elseif ($analyze) {
            DB::statement("VACUUM ANALYZE {$quotedPartition}");
        } else {
            DB::statement("VACUUM {$quotedPartition}");
        }
    }

    public static function analyze(string $partition): void
    {
        DB::statement("ANALYZE " . self::quoteIdentifier($partition));
    }

    public static function vacuumAll(string $table, bool $full = false, bool $analyze = false): void
    {
        foreach (Partition::getPartitions($table) as $partition) {
            self::vacuum($partition->partition_name, $full, $analyze);
        }
    }

    public static function analyzeAll(string $table): void
    {
        foreach (Partition::getPartitions($table) as $partition) {
            self::analyze($partition->partition_name);
        }
    }

    public static function reindex(string $partition, bool $concurrently = false): void
    {
        $quotedPartition = self::quoteIdentifier($partition);
        $concurrent = $concurrently ? 'CONCURRENTLY ' : '';
        DB::statement("REINDEX {$concurrent}TABLE {$quotedPartition}");
    }

    public static function reindexAll(string $table, bool $concurrently = false): void
    {
        foreach (Partition::getPartitions($table) as $partition) {
            self::reindex($partition->partition_name, $concurrently);
        }
    }

    public static function rebalanceHash(string $table, int $newModulus, ?string $schema = null): void
    {
        DB::transaction(function () use ($table, $newModulus, $schema) {
            $quotedTable = self::quoteIdentifier($table);
            $existingPartitions = Partition::getPartitions($table);

            $tempTable = "{$table}_rebalance_temp";
            $quotedTemp = self::quoteIdentifier($tempTable);
            DB::statement("CREATE TABLE {$quotedTemp} AS SELECT * FROM {$quotedTable}");

            foreach ($existingPartitions as $partition) {
                Partition::detachPartition($table, $partition->partition_name);
                DB::statement("DROP TABLE " . self::quoteIdentifier($partition->partition_name));
            }

            if ($schema) {
                DB::statement("CREATE SCHEMA IF NOT EXISTS " . self::quoteIdentifier($schema));
            }

            for ($i = 0; $i < $newModulus; $i++) {
                $partName = "{$table}_p{$i}";
                $fullName = $schema ? "{$schema}.{$partName}" : $partName;
                $quotedPart = self::quoteIdentifier($fullName);
                DB::statement("CREATE TABLE {$quotedPart} PARTITION OF {$quotedTable} FOR VALUES WITH (modulus {$newModulus}, remainder {$i})");
            }

            DB::statement("INSERT INTO {$quotedTable} SELECT * FROM {$quotedTemp}");
            DB::statement("DROP TABLE {$quotedTemp}");
        });
    }

    /**
     * Get the partition expression for a partition.
     *
     * @param string $table The parent partitioned table
     * @param string $partitionName The partition name
     * @return string|null The partition expression (e.g., "FOR VALUES IN ('active')") or null if not found
     */
    public static function getPartitionExpression(string $table, string $partitionName): ?string
    {
        $partitions = Partition::getPartitions($table);

        foreach ($partitions as $partition) {
            // Handle both schema-qualified and unqualified names
            $name = $partition->partition_name;
            if ($name === $partitionName || str_ends_with($name, ".{$partitionName}")) {
                return $partition->partition_expression;
            }
        }

        return null;
    }

    /**
     * Add sub-partitions to an existing partition.
     *
     * This is a complex operation that:
     * 1. Detaches the partition from its parent
     * 2. Renames it to a temporary name
     * 3. Creates a new partitioned table in its place
     * 4. Creates sub-partitions
     * 5. Moves data from temp table through the new partitioned table
     * 6. Re-attaches the new partitioned table to parent
     * 7. Drops the temporary table
     *
     * @param string $table The parent partitioned table
     * @param string $partitionName The partition to add sub-partitions to
     * @param AbstractSubPartitionBuilder $subPartitions The sub-partition configuration
     * @param string|null $partitionExpression The partition expression (auto-detected if null)
     */
    public static function addSubPartitions(
        string $table,
        string $partitionName,
        AbstractSubPartitionBuilder $subPartitions,
        ?string $partitionExpression = null
    ): void {
        // Auto-detect partition expression if not provided
        if ($partitionExpression === null) {
            $partitionExpression = self::getPartitionExpression($table, $partitionName);
            if ($partitionExpression === null) {
                throw new \InvalidArgumentException("Could not find partition '{$partitionName}' in table '{$table}'");
            }
        }

        DB::transaction(function () use ($table, $partitionName, $partitionExpression, $subPartitions) {
            $quotedTable = self::quoteIdentifier($table);
            $quotedPartition = self::quoteIdentifier($partitionName);
            $tempName = "{$partitionName}_temp_" . time();
            $quotedTemp = self::quoteIdentifier($tempName);

            // Step 1: Detach the partition
            DB::statement("ALTER TABLE {$quotedTable} DETACH PARTITION {$quotedPartition}");

            // Step 2: Rename to temp
            DB::statement("ALTER TABLE {$quotedPartition} RENAME TO " . self::quoteIdentifier($tempName));

            // Step 3: Create new partitioned table with same structure
            $partitionType = strtoupper($subPartitions->getPartitionType()->value);
            $partitionColumn = self::quoteIdentifier($subPartitions->getPartitionColumn());
            DB::statement("CREATE TABLE {$quotedPartition} (LIKE {$quotedTemp} INCLUDING ALL) PARTITION BY {$partitionType} ({$partitionColumn})");

            // Step 4: Create sub-partitions
            $subPartitionData = $subPartitions->toArray();
            foreach ($subPartitionData['partitions'] as $subPart) {
                self::createSubPartitionTable($partitionName, $subPart);
            }

            // Step 5: Move data through parent (PostgreSQL routes to correct sub-partitions)
            DB::statement("INSERT INTO {$quotedPartition} SELECT * FROM {$quotedTemp}");

            // Step 6: Re-attach the new partitioned partition to parent
            DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedPartition} {$partitionExpression}");

            // Step 7: Drop temp table
            DB::statement("DROP TABLE {$quotedTemp}");
        });
    }

    /**
     * Create a sub-partition table.
     *
     * @param string $parentTable The parent partition table name
     * @param array<string, mixed> $subPartition The sub-partition configuration
     */
    private static function createSubPartitionTable(string $parentTable, array $subPartition): void
    {
        $quotedParent = self::quoteIdentifier($parentTable);
        $subPartitionName = $subPartition['schema']
            ? "{$subPartition['schema']}.{$subPartition['name']}"
            : $subPartition['name'];
        $quotedSubPartition = self::quoteIdentifier($subPartitionName);

        $sql = "CREATE TABLE {$quotedSubPartition} PARTITION OF {$quotedParent} ";

        $sql .= match ($subPartition['type']) {
            'RANGE' => "FOR VALUES FROM (" . self::formatSqlValue($subPartition['from']) . ") TO (" . self::formatSqlValue($subPartition['to']) . ")",
            'LIST' => "FOR VALUES IN (" . implode(', ', array_map(fn($v) => self::formatSqlValue($v), $subPartition['values'])) . ")",
            'HASH' => "FOR VALUES WITH (modulus {$subPartition['modulus']}, remainder {$subPartition['remainder']})",
            default => throw new \InvalidArgumentException("Unknown partition type: {$subPartition['type']}"),
        };

        // Handle nested sub-partitions recursively
        if (!empty($subPartition['sub_partitions'])) {
            $nestedType = strtoupper($subPartition['sub_partitions']['partition_by']['type']);
            $nestedColumn = self::quoteIdentifier($subPartition['sub_partitions']['partition_by']['column']);
            $sql .= " PARTITION BY {$nestedType} ({$nestedColumn})";
        }

        DB::statement($sql);

        // Recursively create nested sub-partitions
        if (!empty($subPartition['sub_partitions']['partitions'])) {
            foreach ($subPartition['sub_partitions']['partitions'] as $nestedSubPartition) {
                self::createSubPartitionTable($subPartitionName, $nestedSubPartition);
            }
        }
    }

    // Returns commands for parallel execution via jobs/CLI
    public static function getParallelVacuumCommands(string $table, bool $full = false, bool $analyze = false): array
    {
        $commands = [];

        foreach (Partition::getPartitions($table) as $partition) {
            $quotedPartition = self::quoteIdentifier($partition->partition_name);

            $commands[$partition->partition_name] = match (true) {
                $full => "VACUUM FULL {$quotedPartition}",
                $analyze => "VACUUM ANALYZE {$quotedPartition}",
                default => "VACUUM {$quotedPartition}",
            };
        }

        return $commands;
    }

    public static function getParallelReindexCommands(string $table, bool $concurrently = false): array
    {
        $commands = [];
        $concurrent = $concurrently ? 'CONCURRENTLY ' : '';

        foreach (Partition::getPartitions($table) as $partition) {
            $quotedPartition = self::quoteIdentifier($partition->partition_name);
            $commands[$partition->partition_name] = "REINDEX {$concurrent}TABLE {$quotedPartition}";
        }

        return $commands;
    }

    public static function getParallelAnalyzeCommands(string $table): array
    {
        $commands = [];

        foreach (Partition::getPartitions($table) as $partition) {
            $quotedPartition = self::quoteIdentifier($partition->partition_name);
            $commands[$partition->partition_name] = "ANALYZE {$quotedPartition}";
        }

        return $commands;
    }

    public static function dryRun(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        DB::beginTransaction();

        try {
            $callback();
        } finally {
            DB::rollBack();
        }

        return $queries;
    }
}
