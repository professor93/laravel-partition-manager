<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

use DateTime;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Support\Facades\DB;

class PartitionSplitter
{
    use SqlHelper;

    public static function yearlyToMonthly(
        string $table,
        string $yearlyPartition,
        int $year,
        ?string $monthlyPrefix = null,
        ?string $schema = null
    ): void {
        $monthlyPrefix = $monthlyPrefix ?? "{$table}_";

        DB::transaction(function () use ($table, $yearlyPartition, $year, $monthlyPrefix, $schema) {
            Partition::detachPartition($table, $yearlyPartition);

            $quotedTable = self::quoteIdentifier($table);
            $quotedYearly = self::quoteIdentifier($yearlyPartition);

            if ($schema) {
                DB::statement("CREATE SCHEMA IF NOT EXISTS " . self::quoteIdentifier($schema));
            }

            // Create all monthly partitions first
            for ($month = 1; $month <= 12; $month++) {
                $startDate = sprintf('%d-%02d-01', $year, $month);
                $endDate = $month === 12 ? ($year + 1) . '-01-01' : sprintf('%d-%02d-01', $year, $month + 1);

                $monthlyName = $monthlyPrefix . 'm' . sprintf('%d_%02d', $year, $month);
                $fullName = $schema ? "{$schema}.{$monthlyName}" : $monthlyName;
                $quotedMonthly = self::quoteIdentifier($fullName);

                DB::statement("CREATE TABLE {$quotedMonthly} (LIKE {$quotedTable} INCLUDING ALL)");
                DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedMonthly} FOR VALUES FROM ('{$startDate}') TO ('{$endDate}')");
            }

            // Insert data through parent - PostgreSQL routes to correct partitions
            DB::statement("INSERT INTO {$quotedTable} SELECT * FROM {$quotedYearly}");

            DB::statement("DROP TABLE {$quotedYearly}");
        });
    }

    public static function yearlyToWeekly(
        string $table,
        string $yearlyPartition,
        int $year,
        ?string $weeklyPrefix = null,
        ?string $schema = null
    ): void {
        $weeklyPrefix = $weeklyPrefix ?? "{$table}_";

        DB::transaction(function () use ($table, $yearlyPartition, $year, $weeklyPrefix, $schema) {
            Partition::detachPartition($table, $yearlyPartition);

            $quotedTable = self::quoteIdentifier($table);
            $quotedYearly = self::quoteIdentifier($yearlyPartition);

            if ($schema) {
                DB::statement("CREATE SCHEMA IF NOT EXISTS " . self::quoteIdentifier($schema));
            }

            $current = new DateTime("{$year}-01-01");
            $yearEnd = new DateTime(($year + 1) . "-01-01");

            // Create all weekly partitions first
            while ($current < $yearEnd) {
                $weekStart = clone $current;
                $weekEnd = min((clone $current)->modify('+7 days'), $yearEnd);

                $weeklyName = $weeklyPrefix . 'w' . $weekStart->format('Y_m_d');
                $fullName = $schema ? "{$schema}.{$weeklyName}" : $weeklyName;
                $quotedWeekly = self::quoteIdentifier($fullName);

                DB::statement("CREATE TABLE {$quotedWeekly} (LIKE {$quotedTable} INCLUDING ALL)");
                DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedWeekly} FOR VALUES FROM ('{$weekStart->format('Y-m-d')}') TO ('{$weekEnd->format('Y-m-d')}')");

                $current = $weekEnd;
            }

            // Insert data through parent - PostgreSQL routes to correct partitions
            DB::statement("INSERT INTO {$quotedTable} SELECT * FROM {$quotedYearly}");

            DB::statement("DROP TABLE {$quotedYearly}");
        });
    }

    public static function monthlyToDaily(
        string $table,
        string $monthlyPartition,
        int $year,
        int $month,
        ?string $dailyPrefix = null,
        ?string $schema = null
    ): void {
        $dailyPrefix = $dailyPrefix ?? "{$table}_";

        DB::transaction(function () use ($table, $monthlyPartition, $year, $month, $dailyPrefix, $schema) {
            Partition::detachPartition($table, $monthlyPartition);

            $quotedTable = self::quoteIdentifier($table);
            $quotedMonthly = self::quoteIdentifier($monthlyPartition);

            if ($schema) {
                DB::statement("CREATE SCHEMA IF NOT EXISTS " . self::quoteIdentifier($schema));
            }

            $monthStart = new DateTime("{$year}-{$month}-01");
            $monthEnd = (clone $monthStart)->modify('+1 month');
            $current = clone $monthStart;

            // Create all daily partitions first
            while ($current < $monthEnd) {
                $dayStart = clone $current;
                $dayEnd = (clone $current)->modify('+1 day');

                $dailyName = $dailyPrefix . 'd' . $dayStart->format('Y_m_d');
                $fullName = $schema ? "{$schema}.{$dailyName}" : $dailyName;
                $quotedDaily = self::quoteIdentifier($fullName);

                DB::statement("CREATE TABLE {$quotedDaily} (LIKE {$quotedTable} INCLUDING ALL)");
                DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedDaily} FOR VALUES FROM ('{$dayStart->format('Y-m-d')}') TO ('{$dayEnd->format('Y-m-d')}')");

                $current = $dayEnd;
            }

            // Insert data through parent - PostgreSQL routes to correct partitions
            DB::statement("INSERT INTO {$quotedTable} SELECT * FROM {$quotedMonthly}");

            DB::statement("DROP TABLE {$quotedMonthly}");
        });
    }

    public static function monthlyToWeekly(
        string $table,
        string $monthlyPartition,
        int $year,
        int $month,
        ?string $weeklyPrefix = null,
        ?string $schema = null
    ): void {
        $weeklyPrefix = $weeklyPrefix ?? "{$table}_";

        DB::transaction(function () use ($table, $monthlyPartition, $year, $month, $weeklyPrefix, $schema) {
            Partition::detachPartition($table, $monthlyPartition);

            $quotedTable = self::quoteIdentifier($table);
            $quotedMonthly = self::quoteIdentifier($monthlyPartition);

            if ($schema) {
                DB::statement("CREATE SCHEMA IF NOT EXISTS " . self::quoteIdentifier($schema));
            }

            $monthStart = new DateTime("{$year}-{$month}-01");
            $monthEnd = (clone $monthStart)->modify('+1 month');
            $current = clone $monthStart;

            // Create all weekly partitions first
            while ($current < $monthEnd) {
                $weekStart = clone $current;
                $weekEnd = min((clone $current)->modify('+7 days'), $monthEnd);

                $weeklyName = $weeklyPrefix . 'w' . $weekStart->format('Y_m_d');
                $fullName = $schema ? "{$schema}.{$weeklyName}" : $weeklyName;
                $quotedWeekly = self::quoteIdentifier($fullName);

                DB::statement("CREATE TABLE {$quotedWeekly} (LIKE {$quotedTable} INCLUDING ALL)");
                DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedWeekly} FOR VALUES FROM ('{$weekStart->format('Y-m-d')}') TO ('{$weekEnd->format('Y-m-d')}')");

                $current = $weekEnd;
            }

            // Insert data through parent - PostgreSQL routes to correct partitions
            DB::statement("INSERT INTO {$quotedTable} SELECT * FROM {$quotedMonthly}");

            DB::statement("DROP TABLE {$quotedMonthly}");
        });
    }

    // Split with custom ranges
    public static function custom(
        string $table,
        string $partitionToSplit,
        array $newPartitions,
        ?string $schema = null
    ): void {
        DB::transaction(function () use ($table, $partitionToSplit, $newPartitions, $schema) {
            Partition::detachPartition($table, $partitionToSplit);

            $quotedTable = self::quoteIdentifier($table);
            $quotedSource = self::quoteIdentifier($partitionToSplit);

            if ($schema) {
                DB::statement("CREATE SCHEMA IF NOT EXISTS " . self::quoteIdentifier($schema));
            }

            // Create all partitions first and attach them
            foreach ($newPartitions as $name => $range) {
                $fullName = $schema ? "{$schema}.{$name}" : $name;
                $quotedNew = self::quoteIdentifier($fullName);
                $fromValue = self::formatSqlValue($range['from']);
                $toValue = self::formatSqlValue($range['to']);

                DB::statement("CREATE TABLE {$quotedNew} (LIKE {$quotedTable} INCLUDING ALL)");
                DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedNew} FOR VALUES FROM ({$fromValue}) TO ({$toValue})");
            }

            // Insert data through parent - PostgreSQL routes to correct partitions
            DB::statement("INSERT INTO {$quotedTable} SELECT * FROM {$quotedSource}");

            DB::statement("DROP TABLE {$quotedSource}");
        });
    }
}
