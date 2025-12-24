<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Commands;

use Illuminate\Console\Command;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Services\PartitionStats;

class PartitionHealthCommand extends Command
{
    protected $signature = 'partition:health
        {table : The partitioned table name}
        {--connection= : Database connection to use}
        {--json : Output as JSON}';

    protected $description = 'Run health check on a partitioned table';

    public function handle(): int
    {
        $table = $this->argument('table');

        if (!Partition::isPartitioned($table)) {
            $this->error("Table '{$table}' is not a partitioned table.");
            return self::FAILURE;
        }

        $health = PartitionStats::healthCheck($table);

        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $hasIssues = false;

        // Check gaps
        if (empty($health['gaps'])) {
            $this->info('✓ No gaps detected');
        } else {
            $this->error('✗ Gaps detected:');
            foreach ($health['gaps'] as $gap) {
                $this->line("  - Gap between {$gap['from']} and {$gap['to']}");
            }
            $hasIssues = true;
        }

        // Check overlaps
        if (empty($health['overlaps'])) {
            $this->info('✓ No overlaps detected');
        } else {
            $this->error('✗ Overlaps detected:');
            foreach ($health['overlaps'] as $overlap) {
                $this->line("  - Overlap: {$overlap['partition1']} and {$overlap['partition2']}");
            }
            $hasIssues = true;
        }

        // Check missing indexes
        if (empty($health['missing_indexes'])) {
            $this->info('✓ All partitions have indexes');
        } else {
            $this->warn('⚠ Missing indexes on partitions:');
            foreach ($health['missing_indexes'] as $partition) {
                $this->line("  - {$partition}");
            }
        }

        // Summary
        $this->newLine();
        if ($hasIssues) {
            $this->error('Health check completed with issues.');
            return self::FAILURE;
        }

        $this->info('Health check passed.');
        return self::SUCCESS;
    }
}
