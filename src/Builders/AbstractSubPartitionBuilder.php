<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\ValueObjects\SubPartition;

abstract class AbstractSubPartitionBuilder
{
    /** @var array<int, SubPartition> */
    protected array $partitions = [];

    protected ?string $schema = null;

    protected ?string $baseName = null;

    protected ?string $tableName = null;

    public function __construct(
        protected readonly PartitionType $partitionType,
        protected readonly string $partitionColumn,
    ) {}

    /**
     * Set the table name for column type lookups.
     */
    public function table(string $tableName): static
    {
        $this->tableName = $tableName;

        return $this;
    }

    /**
     * Get the table name for column type lookups.
     */
    public function getTableName(): ?string
    {
        return $this->tableName;
    }

    /**
     * Set the base name for auto-generating partition names.
     * This is typically the parent partition name.
     */
    public function for(string $baseName): static
    {
        $this->baseName = $baseName;

        return $this;
    }

    /**
     * Get the base name for auto-generating partition names.
     */
    public function getBaseName(): ?string
    {
        return $this->baseName;
    }

    /**
     * Set the default schema for partitions in this builder.
     */
    public function schema(string $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * Get the schema set on this builder.
     */
    public function getSchema(): ?string
    {
        return $this->schema;
    }

    /**
     * @return array<int, SubPartition>
     */
    public function getPartitions(): array
    {
        return $this->partitions;
    }

    public function getPartitionType(): PartitionType
    {
        return $this->partitionType;
    }

    public function getPartitionColumn(): string
    {
        return $this->partitionColumn;
    }

    /**
     * Convert to array with schema inheritance.
     *
     * @param string|null $parentSchema Schema inherited from parent partition
     * @return array{partition_by: array{type: string, column: string}, partitions: array<int, array<string, mixed>>}
     */
    public function toArray(?string $parentSchema = null): array
    {
        $effectiveSchema = $this->schema ?? $parentSchema;

        return [
            'partition_by' => [
                'type' => $this->partitionType->value,
                'column' => $this->partitionColumn,
            ],
            'partitions' => array_map(
                static function (SubPartition $partition) use ($effectiveSchema): array {
                    $data = $partition->toArray();
                    // Apply inherited schema if partition has no explicit schema
                    if ($data['schema'] === null && $effectiveSchema !== null) {
                        $data['schema'] = $effectiveSchema;
                    }
                    return $data;
                },
                $this->partitions
            ),
        ];
    }

    protected function applySchema(SubPartition $partition, ?string $schema): void
    {
        if ($schema !== null) {
            $partition->withSchema($schema);
        } elseif ($this->schema !== null) {
            $partition->withSchema($this->schema);
        }
    }

    /**
     * Resolve prefix, replacing % with baseName if present.
     */
    protected function resolvePrefix(string $prefix): string
    {
        if (str_contains($prefix, '%')) {
            return str_replace('%', $this->baseName ?? '', $prefix);
        }

        return $prefix;
    }
}
