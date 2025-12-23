<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

use Uzbek\LaravelPartitionManager\Traits\SqlHelper;

class RangePartition extends PartitionDefinition
{
    use SqlHelper;

    protected mixed $from = null;

    protected mixed $to = null;

    public function withRange(mixed $from, mixed $to): self
    {
        $this->from = $from;
        $this->to = $to;

        return $this;
    }

    public function getFrom(): mixed
    {
        return $this->from;
    }

    public function getTo(): mixed
    {
        return $this->to;
    }

    public function toSql(): string
    {
        $fromValue = self::formatSqlValue($this->from);
        $toValue = self::formatSqlValue($this->to);

        return "PARTITION {$this->getName()} FOR VALUES FROM ({$fromValue}) TO ({$toValue})";
    }
}
