<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Commands;

use Illuminate\Console\Command;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionRotation;
use Uzbek\LaravelPartitionManager\Services\PartitionStats;

class PartitionEnsureFutureCommand extends Command
{
    protected $signature = 'partition:ensure-future
        {table : The partitioned table name}
        {--column= : The partition column (auto-detected if not specified)}
        {--count=3 : Number of future partitions to ensure from current date}
        {--interval= : Interval (daily, weekly, monthly, yearly). Auto-detected if not specified}
        {--schema= : Schema for new partitions}
        {--connection= : Database connection to use}';

    protected $description = 'Ensure future partitions exist for a table from current date';

    public function handle(): int
    {
        $table = $this->argument('table');
        $column = $this->option('column');
        $count = (int) $this->option('count');
        $interval = $this->option('interval');
        $schema = $this->option('schema');

        if (!Partition::isPartitioned($table)) {
            $this->error("Table '{$table}' is not a partitioned table.");
            return self::FAILURE;
        }

        // Show auto-detected values
        $actualColumn = $column ?? PartitionStats::getPartitionColumn($table);
        $actualInterval = $interval ?? PartitionStats::detectInterval($table);

        if ($actualColumn === null) {
            $this->error("Cannot determine partition column for table '{$table}'. Please specify with --column.");
            return self::FAILURE;
        }

        if ($actualInterval === null) {
            $this->error("Cannot detect partition interval for table '{$table}'. Please specify with --interval.");
            return self::FAILURE;
        }

        $this->info("Ensuring {$count} future {$actualInterval} partitions for '{$table}' (column: {$actualColumn})...");

        try {
            $created = PartitionRotation::ensureFuture(
                table: $table,
                count: $count,
                column: $column,
                interval: $interval,
                schema: $schema
            );

            if ($created > 0) {
                $this->info("Created {$created} new partition(s).");
            } else {
                $this->info('All future partitions already exist.');
            }

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
