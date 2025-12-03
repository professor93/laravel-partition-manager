<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

use Uzbek\LaravelPartitionManager\Builders\SubPartitionBuilder;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;

class PartitionDefinition
{
    protected ?string $schema = null;

    protected ?SubPartitionBuilder $subPartitions = null;

    private function __construct(
        protected readonly string $name,
        protected readonly PartitionType $type,
    ) {}

    public static function list(string $name): static
    {
        return new static($name, PartitionType::LIST);
    }

    public static function range(string $name): static
    {
        return new static($name, PartitionType::RANGE);
    }

    public static function hash(string $name): static
    {
        return new static($name, PartitionType::HASH);
    }

    public function withSchema(string $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    public function withSubPartitions(SubPartitionBuilder $builder): self
    {
        $this->subPartitions = $builder;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): PartitionType
    {
        return $this->type;
    }

    public function getSchema(): ?string
    {
        return $this->schema;
    }

    public function getSubPartitions(): ?SubPartitionBuilder
    {
        return $this->subPartitions;
    }

    public function hasSubPartitions(): bool
    {
        return $this->subPartitions !== null;
    }
}