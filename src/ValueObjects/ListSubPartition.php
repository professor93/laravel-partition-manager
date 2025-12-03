<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

class ListSubPartition extends SubPartition
{
    /** @var array<int, mixed> */
    protected array $values = [];

    public static function create(string $name): self
    {
        return new self($name);
    }

    /**
     * @param array<int, mixed> $values
     */
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

    /**
     * @return array<int, mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'LIST',
            'name' => $this->name,
            'values' => $this->values,
            'schema' => $this->schema,
            'tablespace' => $this->tablespace,
        ];
    }
}