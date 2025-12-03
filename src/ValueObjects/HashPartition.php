<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

class HashPartition extends PartitionDefinition
{
    protected int $modulus = 0;

    protected int $remainder = 0;

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

    public function toSql(): string
    {
        $sql = "PARTITION {$this->getName()} FOR VALUES WITH (modulus {$this->modulus}, remainder {$this->remainder})";

        if ($this->schema !== null) {
            $sql .= " TABLESPACE {$this->schema}";
        }

        return $sql;
    }
}