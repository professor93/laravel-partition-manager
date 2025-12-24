<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Commands;

use Illuminate\Console\Command;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionMaintenance;

class PartitionReindexCommand extends Command
{
    protected $signature = 'partition:reindex
        {table : The partitioned table name}
        {--partition= : Specific partition to reindex (optional)}
        {--concurrently : Reindex without locking (slower but non-blocking)}
        {--connection= : Database connection to use}';

    protected $description = 'Reindex partitions to optimize query performance';

    public function handle(): int
    {
        $table = $this->argument('table');
        $specificPartition = $this->option('partition');
        $concurrently = $this->option('concurrently');

        if (!Partition::isPartitioned($table)) {
            $this->error("Table '{$table}' is not a partitioned table.");
            return self::FAILURE;
        }

        if (!$concurrently) {
            $this->warn('Running REINDEX - this may lock the table(s). Use --concurrently to avoid locks.');
        }

        if ($specificPartition) {
            $this->info("Reindexing partition '{$specificPartition}'...");
            PartitionMaintenance::reindex($specificPartition, $concurrently);
            $this->info('Done.');
            return self::SUCCESS;
        }

        $partitions = Partition::getPartitions($table);
        $this->info(sprintf('Reindexing %d partition(s)...', count($partitions)));

        $bar = $this->output->createProgressBar(count($partitions));
        $bar->start();

        foreach ($partitions as $partition) {
            PartitionMaintenance::reindex($partition->partition_name, $concurrently);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Reindex completed.');

        return self::SUCCESS;
    }
}
