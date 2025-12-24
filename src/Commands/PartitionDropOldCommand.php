<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Commands;

use Illuminate\Console\Command;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionRotation;

class PartitionDropOldCommand extends Command
{
    protected $signature = 'partition:drop-old
        {table : The partitioned table name}
        {--keep=12 : Number of partitions to keep}
        {--drop-schemas : Also drop empty schemas}
        {--dry-run : Show what would be dropped without actually dropping}
        {--force : Skip confirmation}
        {--connection= : Database connection to use}';

    protected $description = 'Drop old partitions, keeping the most recent ones';

    public function handle(): int
    {
        $table = $this->argument('table');
        $keep = (int) $this->option('keep');
        $dropSchemas = $this->option('drop-schemas');
        $dryRun = $this->option('dry-run');

        if (!Partition::isPartitioned($table)) {
            $this->error("Table '{$table}' is not a partitioned table.");
            return self::FAILURE;
        }

        $partitions = Partition::getPartitions($table);
        $totalCount = count($partitions);

        if ($totalCount <= $keep) {
            $this->info("Only {$totalCount} partitions exist, keeping all (threshold: {$keep}).");
            return self::SUCCESS;
        }

        $toDropCount = $totalCount - $keep;

        if ($dryRun) {
            $this->warn("[DRY RUN] Would drop {$toDropCount} partition(s):");
            $partitionsToShow = array_slice($partitions, 0, $toDropCount);
            foreach ($partitionsToShow as $partition) {
                $this->line("  - {$partition->partition_name}");
            }
            return self::SUCCESS;
        }

        if (!$this->option('force')) {
            if (!$this->confirm("This will drop {$toDropCount} partition(s). Continue?")) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        $dropped = PartitionRotation::rotate($table, $keep, $dropSchemas);

        $this->info(sprintf('Dropped %d partition(s):', count($dropped)));
        foreach ($dropped as $name) {
            $this->line("  - {$name}");
        }

        return self::SUCCESS;
    }
}
