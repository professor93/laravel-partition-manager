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
     * Add multiple hash partitions at once.
     *
     * @param int $modulus Number of partitions (modulus value)
     * @param string|null $prefix Optional name prefix. If null, auto-generates using baseName (set via for()) or 'p'
     * @param string|null $schema Optional schema for all partitions
     */
    public function addHashPartitions(int $modulus, ?string $prefix = null, ?string $schema = null): self
    {
        // Auto-generate prefix if not provided, or resolve % placeholder
        $resolvedPrefix = $prefix !== null
            ? $this->resolvePrefix($prefix)
            : ($this->baseName !== null ? "{$this->baseName}_p" : 'p');

        for ($remainder = 0; $remainder < $modulus; $remainder++) {
            $name = $resolvedPrefix . $remainder;
            $partition = HashSubPartition::create($name)->withHash($modulus, $remainder);

            $this->applySchema($partition, $schema);
            $this->partitions[] = $partition;
        }

        return $this;
    }
}
