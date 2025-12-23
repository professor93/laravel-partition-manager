<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

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
