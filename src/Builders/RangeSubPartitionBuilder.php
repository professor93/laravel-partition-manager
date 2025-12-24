<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use Carbon\Carbon;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\ValueObjects\RangeSubPartition;

class RangeSubPartitionBuilder extends AbstractSubPartitionBuilder
{
    public function __construct(string $partitionColumn)
    {
        parent::__construct(PartitionType::RANGE, $partitionColumn);
    }

    /**
     * Get the end date of the last partition.
     */
    public function getLastPartitionEndDate(): ?Carbon
    {
        if (empty($this->partitions)) {
            return null;
        }

        $lastPartition = end($this->partitions);
        if ($lastPartition instanceof RangeSubPartition) {
            $to = $lastPartition->getTo();
            return $to ? Carbon::parse($to) : null;
        }

        return null;
    }

    /**
     * Get the schema of the last partition.
     */
    protected function getLastPartitionSchema(): ?string
    {
        if (empty($this->partitions)) {
            return null;
        }

        $lastPartition = end($this->partitions);
        return $lastPartition->getSchema();
    }

    /**
     * Resolve the start date - use provided date or last partition's end date.
     */
    protected function resolveStartDate(string|Carbon|null $startDate, string $interval): Carbon
    {
        if ($startDate !== null) {
            return $startDate instanceof Carbon ? $startDate->copy() : Carbon::parse($startDate);
        }

        // Use last partition's end date if available (no alignment - continue exactly from where it ended)
        if ($lastEnd = $this->getLastPartitionEndDate()) {
            return $lastEnd->copy();
        }

        // Default to current date aligned to interval
        $date = Carbon::now();
        return match ($interval) {
            'yearly' => $date->startOfYear(),
            'monthly' => $date->startOfMonth(),
            'weekly' => $date->startOfWeek(),
            'daily' => $date->startOfDay(),
            default => $date,
        };
    }

    /**
     * Resolve schema - use provided schema, last partition's schema, or builder's default.
     */
    protected function resolveSchema(?string $schema): ?string
    {
        return $schema ?? $this->getLastPartitionSchema() ?? $this->schema;
    }

    /**
     * Add a single range partition.
     */
    public function addRangePartition(
        string $name,
        mixed $from,
        mixed $to,
        ?string $schema = null,
        ?AbstractSubPartitionBuilder $subPartitions = null
    ): self {
        $partition = RangeSubPartition::create($name)->withRange($from, $to);

        $effectiveSchema = $this->resolveSchema($schema);
        if ($effectiveSchema !== null) {
            $partition->withSchema($effectiveSchema);
        }

        if ($subPartitions !== null) {
            if ($this->tableName !== null) {
                $subPartitions->table($this->tableName);
            }
            $subPartitions->for($name);
            $partition->withSubPartitions($subPartitions);
        }

        $this->partitions[] = $partition;

        return $this;
    }

    /**
     * Add multiple yearly range partitions.
     *
     * @param int $count Number of partitions to create
     * @param string|Carbon|null $startDate Starting date (defaults to last partition end or current year start)
     * @param string|null $prefix Optional name prefix. If null, auto-generates using baseName (set via for()) + '_y'
     * @param string|null $schema Optional schema (defaults to last partition's schema)
     */
    public function addYearlyPartitions(int $count, string|Carbon|null $startDate = null, ?string $prefix = null, ?string $schema = null): self
    {
        // If prefix is null and baseName is not yet set, defer partition creation
        if ($prefix === null && $this->baseName === null) {
            $this->addDeferredPartition([
                'type' => 'yearly',
                'count' => $count,
                'startDate' => $startDate,
                'schema' => $schema,
            ]);

            return $this;
        }

        $this->createYearlyPartitions($count, $startDate, $prefix, $schema);

        return $this;
    }

    /**
     * Actually create yearly partitions.
     */
    private function createYearlyPartitions(int $count, string|Carbon|null $startDate, ?string $prefix, ?string $schema): void
    {
        $date = $this->resolveStartDate($startDate, 'yearly');
        $effectiveSchema = $this->resolveSchema($schema);

        $resolvedPrefix = $prefix !== null
            ? $this->resolvePrefix($prefix)
            : "{$this->baseName}_y";

        for ($i = 0; $i < $count; $i++) {
            $from = $date->format('Y-m-d');
            $to = $date->copy()->addYear()->format('Y-m-d');
            $name = $resolvedPrefix . $date->format('Y');

            $partition = RangeSubPartition::create($name)->withRange($from, $to);
            if ($effectiveSchema !== null) {
                $partition->withSchema($effectiveSchema);
            }
            $this->partitions[] = $partition;

            $date->addYear();
        }
    }

    /**
     * Add multiple monthly range partitions.
     *
     * @param int $count Number of partitions to create
     * @param string|Carbon|null $startDate Starting date (defaults to last partition end or current month start)
     * @param string|null $prefix Optional name prefix. If null, auto-generates using baseName (set via for()) + '_m'
     * @param string|null $schema Optional schema (defaults to last partition's schema)
     */
    public function addMonthlyPartitions(int $count, string|Carbon|null $startDate = null, ?string $prefix = null, ?string $schema = null): self
    {
        // If prefix is null and baseName is not yet set, defer partition creation
        if ($prefix === null && $this->baseName === null) {
            $this->addDeferredPartition([
                'type' => 'monthly',
                'count' => $count,
                'startDate' => $startDate,
                'schema' => $schema,
            ]);

            return $this;
        }

        $this->createMonthlyPartitions($count, $startDate, $prefix, $schema);

        return $this;
    }

    /**
     * Actually create monthly partitions.
     */
    private function createMonthlyPartitions(int $count, string|Carbon|null $startDate, ?string $prefix, ?string $schema): void
    {
        $date = $this->resolveStartDate($startDate, 'monthly');
        $effectiveSchema = $this->resolveSchema($schema);

        $resolvedPrefix = $prefix !== null
            ? $this->resolvePrefix($prefix)
            : "{$this->baseName}_m";

        for ($i = 0; $i < $count; $i++) {
            $from = $date->format('Y-m-d');
            $to = $date->copy()->addMonth()->format('Y-m-d');
            $name = $resolvedPrefix . $date->format('Y_m');

            $partition = RangeSubPartition::create($name)->withRange($from, $to);
            if ($effectiveSchema !== null) {
                $partition->withSchema($effectiveSchema);
            }
            $this->partitions[] = $partition;

            $date->addMonth();
        }
    }

    /**
     * Add multiple weekly range partitions.
     *
     * @param int $count Number of partitions to create
     * @param string|Carbon|null $startDate Starting date (defaults to last partition end or current week start)
     * @param string|null $prefix Optional name prefix. If null, auto-generates using baseName (set via for()) + '_w'
     * @param string|null $schema Optional schema (defaults to last partition's schema)
     */
    public function addWeeklyPartitions(int $count, string|Carbon|null $startDate = null, ?string $prefix = null, ?string $schema = null): self
    {
        // If prefix is null and baseName is not yet set, defer partition creation
        if ($prefix === null && $this->baseName === null) {
            $this->addDeferredPartition([
                'type' => 'weekly',
                'count' => $count,
                'startDate' => $startDate,
                'schema' => $schema,
            ]);

            return $this;
        }

        $this->createWeeklyPartitions($count, $startDate, $prefix, $schema);

        return $this;
    }

    /**
     * Actually create weekly partitions.
     */
    private function createWeeklyPartitions(int $count, string|Carbon|null $startDate, ?string $prefix, ?string $schema): void
    {
        $date = $this->resolveStartDate($startDate, 'weekly');
        $effectiveSchema = $this->resolveSchema($schema);

        $resolvedPrefix = $prefix !== null
            ? $this->resolvePrefix($prefix)
            : "{$this->baseName}_w";

        for ($i = 0; $i < $count; $i++) {
            $from = $date->format('Y-m-d');
            $to = $date->copy()->addWeek()->format('Y-m-d');
            $name = $resolvedPrefix . $date->format('Y_m_d');

            $partition = RangeSubPartition::create($name)->withRange($from, $to);
            if ($effectiveSchema !== null) {
                $partition->withSchema($effectiveSchema);
            }
            $this->partitions[] = $partition;

            $date->addWeek();
        }
    }

    /**
     * Add multiple daily range partitions.
     *
     * @param int $count Number of partitions to create
     * @param string|Carbon|null $startDate Starting date (defaults to last partition end or today)
     * @param string|null $prefix Optional name prefix. If null, auto-generates using baseName (set via for()) + '_d'
     * @param string|null $schema Optional schema (defaults to last partition's schema)
     */
    public function addDailyPartitions(int $count, string|Carbon|null $startDate = null, ?string $prefix = null, ?string $schema = null): self
    {
        // If prefix is null and baseName is not yet set, defer partition creation
        if ($prefix === null && $this->baseName === null) {
            $this->addDeferredPartition([
                'type' => 'daily',
                'count' => $count,
                'startDate' => $startDate,
                'schema' => $schema,
            ]);

            return $this;
        }

        $this->createDailyPartitions($count, $startDate, $prefix, $schema);

        return $this;
    }

    /**
     * Actually create daily partitions.
     */
    private function createDailyPartitions(int $count, string|Carbon|null $startDate, ?string $prefix, ?string $schema): void
    {
        $date = $this->resolveStartDate($startDate, 'daily');
        $effectiveSchema = $this->resolveSchema($schema);

        $resolvedPrefix = $prefix !== null
            ? $this->resolvePrefix($prefix)
            : "{$this->baseName}_d";

        for ($i = 0; $i < $count; $i++) {
            $from = $date->format('Y-m-d');
            $to = $date->copy()->addDay()->format('Y-m-d');
            $name = $resolvedPrefix . $date->format('Y_m_d');

            $partition = RangeSubPartition::create($name)->withRange($from, $to);
            if ($effectiveSchema !== null) {
                $partition->withSchema($effectiveSchema);
            }
            $this->partitions[] = $partition;

            $date->addDay();
        }
    }

    /**
     * Generate deferred range partitions.
     */
    protected function generateDeferredPartitions(): void
    {
        if (empty($this->deferredPartitions)) {
            return;
        }

        $deferred = $this->deferredPartitions;
        $this->deferredPartitions = [];

        foreach ($deferred as $config) {
            match ($config['type']) {
                'yearly' => $this->createYearlyPartitions($config['count'], $config['startDate'], null, $config['schema']),
                'monthly' => $this->createMonthlyPartitions($config['count'], $config['startDate'], null, $config['schema']),
                'weekly' => $this->createWeeklyPartitions($config['count'], $config['startDate'], null, $config['schema']),
                'daily' => $this->createDailyPartitions($config['count'], $config['startDate'], null, $config['schema']),
                default => null,
            };
        }
    }
}
