<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Commands;

use Illuminate\Console\Command;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionStats;

class PartitionTreeCommand extends Command
{
    protected $signature = 'partition:tree
        {table : The partitioned table name}
        {--depth=2 : Maximum depth to display}
        {--connection= : Database connection to use}';

    protected $description = 'Display partition hierarchy as a tree';

    public function handle(): int
    {
        $table = $this->argument('table');
        $maxDepth = (int) $this->option('depth');

        if (!Partition::isPartitioned($table)) {
            $this->error("Table '{$table}' is not a partitioned table.");
            return self::FAILURE;
        }

        $strategy = PartitionStats::getPartitionStrategy($table);
        $this->line("<fg=cyan>{$table}</> <fg=gray>(partitioned by {$strategy})</>");

        $this->displayPartitions($table, '', $maxDepth, 1);

        return self::SUCCESS;
    }

    protected function displayPartitions(string $parentTable, string $prefix, int $maxDepth, int $currentDepth): void
    {
        $partitions = Partition::getPartitions($parentTable);
        $count = count($partitions);

        foreach ($partitions as $index => $partition) {
            $isLast = ($index === $count - 1);
            $connector = $isLast ? '└── ' : '├── ';
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');

            $bounds = $this->formatBounds($partition->partition_expression);
            $this->line("{$prefix}{$connector}<fg=green>{$partition->partition_name}</> <fg=gray>{$bounds}</>");

            // Check for sub-partitions
            if ($currentDepth < $maxDepth) {
                $subPartitions = Partition::getPartitions($partition->partition_name);
                if (!empty($subPartitions)) {
                    $this->displayPartitions($partition->partition_name, $childPrefix, $maxDepth, $currentDepth + 1);
                }
            }
        }
    }

    protected function formatBounds(string $expression): string
    {
        // Shorten common patterns
        $expression = preg_replace("/FOR VALUES FROM \('([^']+)'\) TO \('([^']+)'\)/", '[$1 → $2]', $expression);
        $expression = preg_replace("/FOR VALUES IN \((.+)\)/", 'IN ($1)', $expression);
        $expression = preg_replace("/FOR VALUES WITH \(modulus (\d+), remainder (\d+)\)/", 'HASH($1, $2)', $expression);

        return $expression;
    }
}
