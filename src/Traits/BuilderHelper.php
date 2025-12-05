<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Traits;

use Uzbek\LaravelPartitionManager\Exceptions\PartitionException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Provides common helper methods for partition builders.
 *
 * This trait centralizes connection resolution and validation logic
 * to avoid code duplication across partition builders.
 */
trait BuilderHelper
{
    protected ?string $connectionName = null;

    /**
     * Get the database connection.
     *
     * @return Connection
     */
    protected function getConnection(): Connection
    {
        return $this->connectionName !== null
            ? DB::connection($this->connectionName)
            : DB::connection();
    }

    /**
     * Ensure partition column is set before generating partitions.
     *
     * @throws PartitionException If partition column is not set
     */
    protected function ensurePartitionColumnSet(): void
    {
        $column = $this->partitionColumn ?? '';

        if ($column === '') {
            throw new PartitionException(
                "Partition column not specified. Use by() method first."
            );
        }
    }
}