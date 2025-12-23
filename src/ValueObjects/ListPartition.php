<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

use Uzbek\LaravelPartitionManager\Traits\SqlHelper;

class ListPartition extends PartitionDefinition
{
    use SqlHelper;

    /** @var array<int, mixed> */
    protected array $values = [];

    /** @param array<int, mixed> $values */
    public function withValues(array $values): self
    {
        $this->values = $values;

        return $this;
    }

    public function withValue(mixed $value): self
    {
        $this->values[] = $value;

        return $this;
    }

    /** @return array<int, mixed> */
    public function getValues(): array
    {
        return $this->values;
    }

    public function toSql(): string
    {
        $valueList = implode(', ', array_map(
            static fn (mixed $value): string => self::formatSqlValue($value),
            $this->values
        ));

        return "PARTITION {$this->getName()} FOR VALUES IN ({$valueList})";
    }
}
