<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Scheduling;

/**
 * Result object for scheduled partition maintenance operations.
 */
class PartitionScheduleResult
{
    /**
     * Number of partitions created by ensureFuture().
     */
    public int $partitionsCreated = 0;

    /**
     * Array of partition names dropped by rotate().
     *
     * @var array<int, string>
     */
    public array $partitionsDropped = [];

    /**
     * Check if any partitions were created.
     */
    public function hasCreated(): bool
    {
        return $this->partitionsCreated > 0;
    }

    /**
     * Check if any partitions were dropped.
     */
    public function hasDropped(): bool
    {
        return !empty($this->partitionsDropped);
    }

    /**
     * Check if any changes were made.
     */
    public function hasChanges(): bool
    {
        return $this->hasCreated() || $this->hasDropped();
    }

    /**
     * Get a summary message.
     */
    public function summary(): string
    {
        $parts = [];

        if ($this->partitionsCreated > 0) {
            $parts[] = "created {$this->partitionsCreated} partition(s)";
        }

        if (!empty($this->partitionsDropped)) {
            $count = count($this->partitionsDropped);
            $parts[] = "dropped {$count} partition(s)";
        }

        if (empty($parts)) {
            return 'No changes made';
        }

        return 'Partition maintenance: ' . implode(', ', $parts);
    }
}
