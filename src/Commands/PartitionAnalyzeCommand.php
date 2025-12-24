<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Commands;

use Illuminate\Console\Command;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionMaintenance;

class PartitionAnalyzeCommand extends Command
{
    protected $signature = 'partition:analyze
        {table : The partitioned table name}
        {--partition= : Specific partition to analyze (optional)}
        {--connection= : Database connection to use}';

    protected $description = 'Analyze partitions to update query planner statistics';

    public function handle(): int
    {
        $table = $this->argument('table');
        $specificPartition = $this->option('partition');

        if (!Partition::isPartitioned($table)) {
            $this->error("Table '{$table}' is not a partitioned table.");
            return self::FAILURE;
        }

        if ($specificPartition) {
            $this->info("Analyzing partition '{$specificPartition}'...");
            PartitionMaintenance::analyze($specificPartition);
            $this->info('Done.');
            return self::SUCCESS;
        }

        $partitions = Partition::getPartitions($table);
        $this->info(sprintf('Analyzing %d partition(s)...', count($partitions)));

        $bar = $this->output->createProgressBar(count($partitions));
        $bar->start();

        foreach ($partitions as $partition) {
            PartitionMaintenance::analyze($partition->partition_name);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Analysis completed.');

        return self::SUCCESS;
    }
}
