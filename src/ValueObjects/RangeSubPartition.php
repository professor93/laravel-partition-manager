<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

class RangeSubPartition extends SubPartition
{
    protected mixed $from = null;

    protected mixed $to = null;

    public static function create(string $name): self
    {
        return new self($name);
    }

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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'RANGE',
            'name' => $this->name,
            'from' => $this->from,
            'to' => $this->to,
            'schema' => $this->schema,
            'tablespace' => $this->tablespace,
        ];
    }
}