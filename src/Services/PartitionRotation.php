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

    public static function ensureFuture(
        string $table,
        string $column,
        string $interval = 'monthly',
        int $count = 3,
        ?string $schema = null
    ): int {
        $existingBoundaries = PartitionStats::boundaries($table);
        $maxTo = null;

        foreach ($existingBoundaries as $boundary) {
            if ($boundary->to_value && ($maxTo === null || $boundary->to_value > $maxTo)) {
                $maxTo = $boundary->to_value;
            }
        }

        $startDate = $maxTo ? new DateTime($maxTo) : new DateTime();
        $builder = Partition::for($table)->by($column);

        if ($schema) {
            $builder->schema($schema);
        }

        $createdCount = 0;
        $existingNames = array_column($existingBoundaries, 'partition_name');

        for ($i = 0; $i < $count; $i++) {
            $partitionName = match ($interval) {
                'daily' => "{$table}_d" . $startDate->format('Y_m_d'),
                'weekly' => "{$table}_w" . $startDate->format('Y_m_d'),
                'monthly' => "{$table}_m" . $startDate->format('Y_m'),
                'yearly' => "{$table}_y" . $startDate->format('Y'),
                default => "{$table}_" . $startDate->format('Y_m'),
            };

            if (!in_array($partitionName, $existingNames)) {
                match ($interval) {
                    'daily' => $builder->daily(1, $startDate->format('Y-m-d')),
                    'weekly' => $builder->weekly(1, $startDate->format('Y-m-d')),
                    'monthly' => $builder->monthly(1, $startDate->format('Y-m-d')),
                    'yearly' => $builder->yearly(1, (int) $startDate->format('Y')),
                    default => $builder->monthly(1, $startDate->format('Y-m-d')),
                };
                $createdCount++;
            }

            $startDate->modify(match ($interval) {
                'daily' => '+1 day',
                'weekly' => '+1 week',
                'monthly' => '+1 month',
                'yearly' => '+1 year',
                default => '+1 month',
            });
        }

        return $createdCount;
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
