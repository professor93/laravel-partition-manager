<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Commands;

use Illuminate\Console\Command;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionRotation;

class PartitionEnsureFutureCommand extends Command
{
    protected $signature = 'partition:ensure-future
        {table : The partitioned table name}
        {column : The partition column}
        {--count=3 : Number of future partitions to ensure}
        {--interval=monthly : Interval (daily, weekly, monthly, yearly)}
        {--schema= : Schema for new partitions}
        {--connection= : Database connection to use}';

    protected $description = 'Ensure future partitions exist for a table';

    public function handle(): int
    {
        $table = $this->argument('table');
        $column = $this->argument('column');
        $count = (int) $this->option('count');
        $interval = $this->option('interval');
        $schema = $this->option('schema');

        if (!Partition::isPartitioned($table)) {
            $this->error("Table '{$table}' is not a partitioned table.");
            return self::FAILURE;
        }

        $this->info("Ensuring {$count} future {$interval} partitions for '{$table}'...");

        $created = PartitionRotation::ensureFuture(
            $table,
            $column,
            $count,
            $interval,
            $schema
        );

        if ($created > 0) {
            $this->info("Created {$created} new partition(s).");
        } else {
            $this->info('All future partitions already exist.');
        }

        return self::SUCCESS;
    }
}
