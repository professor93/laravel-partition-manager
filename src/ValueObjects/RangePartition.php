<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

use DateTime;

class RangePartition extends PartitionDefinition
{
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
        $fromValue = $this->formatValue($this->from);
        $toValue = $this->formatValue($this->to);

        $sql = "PARTITION {$this->getName()} FOR VALUES FROM ({$fromValue}) TO ({$toValue})";

        if ($this->schema !== null) {
            $sql .= " TABLESPACE {$this->schema}";
        }

        return $sql;
    }

    private function formatValue(mixed $value): string
    {
        if ($value === 'MINVALUE' || $value === 'MAXVALUE') {
            return $value;
        }

        if ($value instanceof DateTime) {
            return "'" . $value->format('Y-m-d') . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return "'" . $value . "'";
    }
}