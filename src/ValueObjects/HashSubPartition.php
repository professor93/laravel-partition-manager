<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

class HashSubPartition extends SubPartition
{
    protected int $modulus = 0;

    protected int $remainder = 0;

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function withHash(int $modulus, int $remainder): self
    {
        $this->modulus = $modulus;
        $this->remainder = $remainder;

        return $this;
    }

    public function getModulus(): int
    {
        return $this->modulus;
    }

    public function getRemainder(): int
    {
        return $this->remainder;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => 'HASH',
            'name' => $this->name,
            'modulus' => $this->modulus,
            'remainder' => $this->remainder,
            'schema' => $this->schema,
            'tablespace' => $this->tablespace,
        ];

        if ($this->hasSubPartitions()) {
            $data['sub_partitions'] = $this->subPartitions?->toArray($this->schema);
        }

        return $data;
    }
}