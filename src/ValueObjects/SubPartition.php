<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

abstract class SubPartition
{
    protected ?string $schema = null;

    protected ?string $tablespace = null;

    public function __construct(
        protected readonly string $name,
    ) {}

    public function withSchema(string $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    public function withTablespace(string $tablespace): self
    {
        $this->tablespace = $tablespace;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSchema(): ?string
    {
        return $this->schema;
    }

    public function getTablespace(): ?string
    {
        return $this->tablespace;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}