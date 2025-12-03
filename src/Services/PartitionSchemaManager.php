<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

class PartitionSchemaManager
{
    /**
     * @var array<string, string>
     */
    protected array $schemas = [];

    protected ?string $defaultSchema = null;

    public function setDefault(string $schema): self
    {
        $this->defaultSchema = $schema;

        return $this;
    }

    public function register(string $partitionType, string $schema): self
    {
        $this->schemas[$partitionType] = $schema;

        return $this;
    }

    /**
     * @param array<string, string> $schemas
     */
    public function registerMultiple(array $schemas): self
    {
        foreach ($schemas as $type => $schema) {
            $this->register($type, $schema);
        }

        return $this;
    }

    public function getSchemaFor(string $partitionType): ?string
    {
        return $this->schemas[$partitionType] ?? $this->defaultSchema;
    }

    public function hasSchemaFor(string $partitionType): bool
    {
        return isset($this->schemas[$partitionType]);
    }

    public function getDefault(): ?string
    {
        return $this->defaultSchema;
    }

    /**
     * @return array<string, string>
     */
    public function getAllSchemas(): array
    {
        return $this->schemas;
    }

    public function clear(): self
    {
        $this->schemas = [];
        $this->defaultSchema = null;

        return $this;
    }
}