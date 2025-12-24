<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Commands;

use Illuminate\Console\Command;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionMaintenance;

class PartitionVacuumCommand extends Command
{
    protected $signature = 'partition:vacuum
        {table : The partitioned table name}
        {--partition= : Specific partition to vacuum (optional)}
        {--full : Run VACUUM FULL (locks table)}
        {--analyze : Run VACUUM ANALYZE}
        {--connection= : Database connection to use}';

    protected $description = 'Vacuum partitions to reclaim storage and update statistics';

    public function handle(): int
    {
        $table = $this->argument('table');
        $specificPartition = $this->option('partition');
        $full = $this->option('full');
        $analyze = $this->option('analyze');

        if (!Partition::isPartitioned($table)) {
            $this->error("Table '{$table}' is not a partitioned table.");
            return self::FAILURE;
        }

        if ($full) {
            $this->warn('Running VACUUM FULL - this will lock the table(s).');
        }

        if ($specificPartition) {
            $this->info("Vacuuming partition '{$specificPartition}'...");
            PartitionMaintenance::vacuum($specificPartition, $full, $analyze);
            $this->info('Done.');
            return self::SUCCESS;
        }

        $partitions = Partition::getPartitions($table);
        $this->info(sprintf('Vacuuming %d partition(s)...', count($partitions)));

        $bar = $this->output->createProgressBar(count($partitions));
        $bar->start();

        foreach ($partitions as $partition) {
            PartitionMaintenance::vacuum($partition->partition_name, $full, $analyze);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Vacuum completed.');

        return self::SUCCESS;
    }
}
