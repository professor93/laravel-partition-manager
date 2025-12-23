<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

use DateTime;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Support\Facades\DB;

class PartitionConsolidator
{
    use SqlHelper;

    // Merge multiple range partitions into one (detach → create new → move data → attach → drop old)
    public static function merge(
        string $table,
        array $partitionNames,
        string $newPartitionName,
        mixed $from,
        mixed $to,
        ?string $schema = null
    ): void {
        DB::transaction(function () use ($table, $partitionNames, $newPartitionName, $from, $to, $schema) {
            $quotedTable = self::quoteIdentifier($table);

            foreach ($partitionNames as $partitionName) {
                Partition::detachPartition($table, $partitionName);
            }

            $newPartitionFullName = $schema ? "{$schema}.{$newPartitionName}" : $newPartitionName;

            if ($schema) {
                DB::statement("CREATE SCHEMA IF NOT EXISTS " . self::quoteIdentifier($schema));
            }

            $quotedNewPartition = self::quoteIdentifier($newPartitionFullName);
            $fromValue = self::formatSqlValue($from);
            $toValue = self::formatSqlValue($to);

            DB::statement("CREATE TABLE {$quotedNewPartition} (LIKE {$quotedTable} INCLUDING ALL)");

            foreach ($partitionNames as $partitionName) {
                $quotedOldPartition = self::quoteIdentifier($partitionName);
                DB::statement("INSERT INTO {$quotedNewPartition} SELECT * FROM {$quotedOldPartition}");
            }

            DB::statement("ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedNewPartition} FOR VALUES FROM ({$fromValue}) TO ({$toValue})");

            foreach ($partitionNames as $partitionName) {
                DB::statement("DROP TABLE " . self::quoteIdentifier($partitionName));
            }
        });
    }

    public static function monthlyToYearly(
        string $table,
        int $year,
        string $monthlyPrefix,
        ?string $yearlyPrefix = null,
        ?string $schema = null
    ): void {
        $yearlyPrefix = $yearlyPrefix ?? "{$table}_";

        $monthlyPartitions = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthlyPartitions[] = $monthlyPrefix . sprintf('%d_%02d', $year, $month);
        }

        $existingPartitions = array_column(Partition::getPartitions($table), 'partition_name');
        $partitionsToMerge = array_filter($monthlyPartitions, fn(string $p): bool => in_array($p, $existingPartitions, true));

        if (empty($partitionsToMerge)) {
            return;
        }

        self::merge(
            $table,
            $partitionsToMerge,
            $yearlyPrefix . "y{$year}",
            "{$year}-01-01",
            ($year + 1) . "-01-01",
            $schema
        );
    }

    public static function dailyToWeekly(
        string $table,
        string $weekStart,
        string $dailyPrefix,
        ?string $weeklyPrefix = null,
        ?string $schema = null
    ): void {
        $weeklyPrefix = $weeklyPrefix ?? "{$table}_";
        $startDate = new DateTime($weekStart);
        $endDate = (clone $startDate)->modify('+7 days');

        $dailyPartitions = [];
        $current = clone $startDate;
        while ($current < $endDate) {
            $dailyPartitions[] = $dailyPrefix . $current->format('Y_m_d');
            $current->modify('+1 day');
        }

        $existingPartitions = array_column(Partition::getPartitions($table), 'partition_name');
        $partitionsToMerge = array_values(array_filter($dailyPartitions, fn(string $p): bool => in_array($p, $existingPartitions, true)));

        if (empty($partitionsToMerge)) {
            return;
        }

        self::merge(
            $table,
            $partitionsToMerge,
            $weeklyPrefix . 'w' . $startDate->format('Y_m_d'),
            $weekStart,
            $endDate->format('Y-m-d'),
            $schema
        );
    }

    public static function dailyToMonthly(
        string $table,
        int $year,
        int $month,
        string $dailyPrefix,
        ?string $monthlyPrefix = null,
        ?string $schema = null
    ): void {
        $monthlyPrefix = $monthlyPrefix ?? "{$table}_";
        $startDate = new DateTime("{$year}-{$month}-01");
        $endDate = (clone $startDate)->modify('+1 month');

        $dailyPartitions = [];
        $current = clone $startDate;
        while ($current < $endDate) {
            $dailyPartitions[] = $dailyPrefix . $current->format('Y_m_d');
            $current->modify('+1 day');
        }

        $existingPartitions = array_column(Partition::getPartitions($table), 'partition_name');
        $partitionsToMerge = array_values(array_filter($dailyPartitions, fn(string $p): bool => in_array($p, $existingPartitions, true)));

        if (empty($partitionsToMerge)) {
            return;
        }

        self::merge(
            $table,
            $partitionsToMerge,
            $monthlyPrefix . 'm' . $startDate->format('Y_m'),
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $schema
        );
    }

    public static function weeklyToMonthly(
        string $table,
        int $year,
        int $month,
        string $weeklyPrefix,
        ?string $monthlyPrefix = null,
        ?string $schema = null
    ): void {
        $monthlyPrefix = $monthlyPrefix ?? "{$table}_";
        $startDate = new DateTime("{$year}-{$month}-01");
        $endDate = (clone $startDate)->modify('+1 month');

        $existingPartitions = Partition::getPartitions($table);
        $partitionsToMerge = [];

        foreach ($existingPartitions as $partition) {
            $name = $partition->partition_name;
            if (str_starts_with($name, $weeklyPrefix)) {
                $bounds = PartitionStats::parseBoundaries($partition->partition_expression);
                if ($bounds && $bounds['from'] >= $startDate->format('Y-m-d') && $bounds['to'] <= $endDate->format('Y-m-d')) {
                    $partitionsToMerge[] = $name;
                }
            }
        }

        if (empty($partitionsToMerge)) {
            return;
        }

        self::merge(
            $table,
            $partitionsToMerge,
            $monthlyPrefix . 'm' . $startDate->format('Y_m'),
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $schema
        );
    }

    // Merge all partitions within a date range into one
    public static function range(
        string $table,
        string $from,
        string $to,
        string $newPartitionName,
        ?string $schema = null
    ): void {
        $existingPartitions = Partition::getPartitions($table);
        $partitionsToMerge = [];

        foreach ($existingPartitions as $partition) {
            $bounds = PartitionStats::parseBoundaries($partition->partition_expression);
            if ($bounds && $bounds['from'] >= $from && $bounds['to'] <= $to) {
                $partitionsToMerge[] = $partition->partition_name;
            }
        }

        if (empty($partitionsToMerge)) {
            return;
        }

        self::merge($table, $partitionsToMerge, $newPartitionName, $from, $to, $schema);
    }
}
