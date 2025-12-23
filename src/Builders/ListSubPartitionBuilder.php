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
    public function addListPartition(string $name, array $values, ?string $schema = null): self
    {
        $partition = ListSubPartition::create($name)->withValues($values);

        $this->applySchema($partition, $schema);
        $this->partitions[] = $partition;

        return $this;
    }
}
