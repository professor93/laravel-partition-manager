<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\ValueObjects;

use Uzbek\LaravelPartitionManager\Builders\AbstractSubPartitionBuilder;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;

class PartitionDefinition
{
    protected ?string $schema = null;

    protected ?AbstractSubPartitionBuilder $subPartitions = null;

    protected bool $explicitName = false;

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

    /**
     * Mark this partition as having an explicit name that should not be prefixed.
     */
    public function withExplicitName(): self
    {
        $this->explicitName = true;

        return $this;
    }

    public function withSubPartitions(AbstractSubPartitionBuilder $builder): self
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

    public function hasExplicitName(): bool
    {
        return $this->explicitName;
    }

    public function getSubPartitions(): ?AbstractSubPartitionBuilder
    {
        return $this->subPartitions;
    }

    public function hasSubPartitions(): bool
    {
        return $this->subPartitions !== null;
    }
}