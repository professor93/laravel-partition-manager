<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

/**
 * Factory for creating type-specific sub-partition builders.
 */
class SubPartitionBuilder
{
    public static function list(string $column): ListSubPartitionBuilder
    {
        return new ListSubPartitionBuilder($column);
    }

    public static function range(string $column): RangeSubPartitionBuilder
    {
        return new RangeSubPartitionBuilder($column);
    }

    public static function hash(string $column): HashSubPartitionBuilder
    {
        return new HashSubPartitionBuilder($column);
    }
}
