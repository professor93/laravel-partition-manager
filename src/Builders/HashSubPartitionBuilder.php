<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\ValueObjects\HashSubPartition;

class HashSubPartitionBuilder extends AbstractSubPartitionBuilder
{
    public function __construct(string $partitionColumn)
    {
        parent::__construct(PartitionType::HASH, $partitionColumn);
    }

    /**
     * Add a single hash partition.
     */
    public function addHashPartition(
        string $name,
        int $modulus,
        int $remainder,
        ?string $schema = null,
        ?AbstractSubPartitionBuilder $subPartitions = null
    ): self {
        $partition = HashSubPartition::create($name)->withHash($modulus, $remainder);

        $this->applySchema($partition, $schema);

        if ($subPartitions !== null) {
            $partition->withSubPartitions($subPartitions);
        }

        $this->partitions[] = $partition;

        return $this;
    }

    /**
     * Add multiple hash partitions at once.
     *
     * @param string $prefix Name prefix for partitions (e.g., 'pool_p' generates pool_p0, pool_p1, ...)
     * @param int $modulus Number of partitions (modulus value)
     * @param string|null $schema Optional schema for all partitions
     */
    public function addHashPartitions(string $prefix, int $modulus, ?string $schema = null): self
    {
        for ($remainder = 0; $remainder < $modulus; $remainder++) {
            $name = $prefix . $remainder;
            $partition = HashSubPartition::create($name)->withHash($modulus, $remainder);

            $this->applySchema($partition, $schema);
            $this->partitions[] = $partition;
        }

        return $this;
    }
}
