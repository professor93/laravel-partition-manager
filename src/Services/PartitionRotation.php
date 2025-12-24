<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

use DateTime;
use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Support\Facades\DB;

class PartitionRotation
{
    use SqlHelper;

    /**
     * Ensure future partitions exist from the current date.
     *
     * This method ensures that the next N partitions (based on count) exist
     * starting from today. For example, if count=4 and interval='monthly',
     * it will ensure partitions exist for the current month plus the next 3 months.
     *
     * The column, interval, and schema can be auto-detected from existing partitions if not provided:
     * - column: detected from pg_partitioned_table
     * - interval: detected by analyzing partition boundaries
     * - schema: detected from the last (most recent) partition's schema
     *
     * @param string $table Table name
     * @param int $count Number of future partitions to ensure from current date
     * @param string|null $column Partition column (auto-detected if null)
     * @param string|null $interval Interval: daily, weekly, monthly, yearly (auto-detected if null)
     * @param string|null $schema Schema for new partitions (auto-detected from last partition if null)
     * @return int Number of partitions created
     *
     * @throws \RuntimeException If column or interval cannot be determined
     */
    public static function ensureFuture(
        string $table,
        int $count = 3,
        ?string $column = null,
        ?string $interval = null,
        ?string $schema = null
    ): int {
        // Auto-detect column if not provided
        if ($column === null) {
            $column = PartitionStats::getPartitionColumn($table);
            if ($column === null) {
                throw new \RuntimeException("Cannot determine partition column for table '{$table}'. Please specify it explicitly.");
            }
        }

        // Auto-detect interval if not provided
        if ($interval === null) {
            $interval = PartitionStats::detectInterval($table);
            if ($interval === null) {
                throw new \RuntimeException("Cannot detect partition interval for table '{$table}'. Please specify it explicitly.");
            }
        }

        // Auto-detect schema from last partition if not provided
        if ($schema === null) {
            $schema = PartitionStats::getLastPartitionSchema($table);
        }

        $existingBoundaries = PartitionStats::boundaries($table);
        $existingNames = array_column($existingBoundaries, 'partition_name');

        // Start from current date, aligned to the interval boundary
        $startDate = self::alignDateToInterval(new DateTime(), $interval);

        $builder = Partition::for($table)->by($column);

        if ($schema) {
            $builder->schema($schema);
        }

        $createdCount = 0;

        for ($i = 0; $i < $count; $i++) {
            $partitionName = match ($interval) {
                'daily' => "{$table}_d" . $startDate->format('Y_m_d'),
                'weekly' => "{$table}_w" . $startDate->format('Y_m_d'),
                'monthly' => "{$table}_m" . $startDate->format('Y_m'),
                'yearly' => "{$table}_y" . $startDate->format('Y'),
                'quarterly' => "{$table}_q" . $startDate->format('Y') . '_Q' . ceil((int) $startDate->format('n') / 3),
                default => "{$table}_m" . $startDate->format('Y_m'),
            };

            if (!in_array($partitionName, $existingNames)) {
                match ($interval) {
                    'daily' => $builder->daily(1, $startDate->format('Y-m-d')),
                    'weekly' => $builder->weekly(1, $startDate->format('Y-m-d')),
                    'monthly' => $builder->monthly(1, $startDate->format('Y-m-d')),
                    'yearly' => $builder->yearly(1, (int) $startDate->format('Y')),
                    'quarterly' => $builder->quarterly(1, $startDate->format('Y-m-d')),
                    default => $builder->monthly(1, $startDate->format('Y-m-d')),
                };
                $createdCount++;
            }

            $startDate->modify(match ($interval) {
                'daily' => '+1 day',
                'weekly' => '+1 week',
                'monthly' => '+1 month',
                'yearly' => '+1 year',
                'quarterly' => '+3 months',
                default => '+1 month',
            });
        }

        return $createdCount;
    }

    /**
     * Align a date to the start of the interval period.
     *
     * For example:
     * - monthly: aligns to the first day of the month
     * - daily: keeps the date as-is
     * - weekly: aligns to Monday of the week
     * - yearly: aligns to January 1st
     * - quarterly: aligns to the first day of the quarter
     */
    protected static function alignDateToInterval(DateTime $date, string $interval): DateTime
    {
        $aligned = clone $date;
        $aligned->setTime(0, 0, 0);

        return match ($interval) {
            'daily' => $aligned,
            'weekly' => $aligned->modify('monday this week'),
            'monthly' => $aligned->modify('first day of this month'),
            'yearly' => $aligned->modify('first day of january this year'),
            'quarterly' => self::alignToQuarter($aligned),
            default => $aligned->modify('first day of this month'),
        };
    }

    /**
     * Align date to the first day of the current quarter.
     */
    protected static function alignToQuarter(DateTime $date): DateTime
    {
        $month = (int) $date->format('n');
        $quarterStart = match (true) {
            $month <= 3 => 1,
            $month <= 6 => 4,
            $month <= 9 => 7,
            default => 10,
        };

        $date->setDate((int) $date->format('Y'), $quarterStart, 1);
        return $date;
    }

    public static function rotate(string $table, int $keep, bool $dropSchemas = false): array
    {
        $boundaries = PartitionStats::boundaries($table);
        usort($boundaries, fn($a, $b) => strcmp($a->from_value ?? '', $b->from_value ?? ''));

        $dropped = [];
        $totalCount = count($boundaries);

        if ($totalCount <= $keep) {
            return $dropped;
        }

        $toDrop = array_slice($boundaries, 0, $totalCount - $keep);

        foreach ($toDrop as $partition) {
            $name = $partition->partition_name;
            Partition::detachPartition($table, $name);
            DB::statement("DROP TABLE " . self::quoteIdentifier($name));
            $dropped[] = $name;

            if ($dropSchemas && str_contains($name, '.')) {
                Partition::dropSchemaIfEmpty(explode('.', $name, 2)[0]);
            }
        }

        return $dropped;
    }

    public static function addMonthlyForYear(string $table, string $column, int $year, ?string $schema = null): void
    {
        $builder = Partition::for($table)->by($column);

        if ($schema !== null) {
            $builder->schema($schema);
        }

        $builder->monthly(12, "{$year}-01-01");
    }
}
