<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\ValueObjects\ListSubPartition;

class ListSubPartitionBuilder extends AbstractSubPartitionBuilder
{
    public function __construct(string $partitionColumn)
    {
        parent::__construct(PartitionType::LIST, $partitionColumn);
    }

    /**
     * Add a single list partition.
     *
     * @param array<int, mixed> $values
     */
    public function addListPartition(
        string $name,
        array $values,
        ?string $schema = null,
        ?AbstractSubPartitionBuilder $subPartitions = null
    ): self {
        $partition = ListSubPartition::create($name)->withValues($values);

        $this->applySchema($partition, $schema);

        if ($subPartitions !== null) {
            $partition->withSubPartitions($subPartitions);
        }

        $this->partitions[] = $partition;

        return $this;
    }
}
