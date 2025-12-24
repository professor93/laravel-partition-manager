<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Commands;

use Illuminate\Console\Command;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionStats;

class PartitionListCommand extends Command
{
    protected $signature = 'partition:list
        {table : The partitioned table name}
        {--connection= : Database connection to use}
        {--json : Output as JSON}';

    protected $description = 'List all partitions for a table';

    public function handle(): int
    {
        $table = $this->argument('table');

        if (!Partition::isPartitioned($table)) {
            $this->error("Table '{$table}' is not a partitioned table.");
            return self::FAILURE;
        }

        $partitions = Partition::getPartitions($table);

        if (empty($partitions)) {
            $this->warn("No partitions found for table '{$table}'.");
            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($partitions, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($partitions as $partition) {
            $stats = PartitionStats::partitionSize($partition->partition_name);
            $rows[] = [
                $partition->partition_name,
                $this->truncateExpression($partition->partition_expression),
                $stats->size ?? 'N/A',
                isset($stats->row_count) ? number_format($stats->row_count) : 'N/A',
            ];
        }

        $this->table(['Partition', 'Bounds', 'Size', 'Rows'], $rows);
        $this->info(sprintf('Total: %d partitions', count($partitions)));

        return self::SUCCESS;
    }

    protected function truncateExpression(string $expression, int $maxLength = 50): string
    {
        if (strlen($expression) <= $maxLength) {
            return $expression;
        }

        return substr($expression, 0, $maxLength - 3) . '...';
    }
}
