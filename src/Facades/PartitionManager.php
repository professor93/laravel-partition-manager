<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getPartitions(string $table, ?string $connection = null)
 * @method static object|null getPartitionInfo(string $table, string $partitionName, ?string $connection = null)
 * @method static bool isPartitioned(string $table, ?string $connection = null)
 * @method static string|null getPartitionStrategy(string $table, ?string $connection = null)
 * @method static array getPartitionColumns(string $table, ?string $connection = null)
 * @method static void analyzePartition(string $partitionName, ?string $connection = null)
 * @method static void vacuumPartition(string $partitionName, bool $full = false, ?string $connection = null)
 * @method static array dropOldPartitions(string $table, \DateTime $before, ?string $connection = null)
 * @method static string getTableSize(string $table, ?string $connection = null)
 * @method static int getPartitionCount(string $table, ?string $connection = null)
 * @method static object|null getOldestPartition(string $table, ?string $connection = null)
 * @method static object|null getNewestPartition(string $table, ?string $connection = null)
 *
 * @see \Uzbek\LaravelPartitionManager\Services\PartitionManager
 */
class PartitionManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'partition-manager';
    }
}