<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\ValueObjects\ListSubPartition;

class ListSubPartitionBuilder extends AbstractSubPartitionBuilder
{
    public function __construct(string $partitionColumn)
    {
        parent::__construct(PartitionType::LIST, $partitionColumn);
    }

    /**
     * Add a single list partition.
     *
     * @param array<int, mixed> $values
     */
    public function addListPartition(
        string $name,
        array $values,
        ?string $schema = null,
        ?AbstractSubPartitionBuilder $subPartitions = null
    ): self {
        $partition = ListSubPartition::create($name)->withValues($values);

        $this->applySchema($partition, $schema);

        if ($subPartitions !== null) {
            if ($this->tableName !== null) {
                $subPartitions->table($this->tableName);
            }
            $partition->withSubPartitions($subPartitions);
        }

        $this->partitions[] = $partition;

        return $this;
    }

    /**
     * Add multiple list partitions at once.
     *
     * Accepts two formats:
     * 1. Simple array: ['new', 'void', 'used'] - each value becomes a partition with auto-generated name
     * 2. Associative array: [true => 'active', false => 'inactive'] or ['new' => '%_new', 'void' => '%_void']
     *    - Keys are the partition values
     *    - Values are partition names (use '%' as placeholder for baseName)
     *
     * @param array<mixed> $values Array of partition values or value => name mappings
     * @param string|null $schema Optional schema for all partitions
     */
    public function addListPartitions(array $values, ?string $schema = null): self
    {
        foreach ($values as $key => $value) {
            if (is_int($key)) {
                // Simple array format: ['new', 'void', 'used']
                // Value is the partition value, generate name from it
                $partitionValue = $value;
                $partitionName = $this->generateNameFromValue($partitionValue);
            } else {
                // Associative array format: ['new' => 'partition_name'] or [true => '%_active']
                // Key is the partition value, value is the partition name
                $partitionValue = $this->castValue($key);
                $partitionName = $this->resolvePartitionName($value);
            }

            $partition = ListSubPartition::create($partitionName)->withValues([$partitionValue]);
            $this->applySchema($partition, $schema);
            $this->partitions[] = $partition;
        }

        return $this;
    }

    /**
     * Cast string keys to appropriate types based on the column type.
     * Uses the database schema to determine the correct type.
     */
    private function castValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $columnType = $this->getColumnType();

        if ($columnType !== null) {
            return $this->castByColumnType($value, $columnType);
        }

        // Fallback: no type info available, return as-is
        return $value;
    }

    /**
     * Get the column type from the database schema.
     */
    private function getColumnType(): ?string
    {
        if ($this->tableName === null) {
            return null;
        }

        try {
            $column = Schema::getColumn($this->tableName, $this->partitionColumn);

            return $column['type_name'] ?? $column['type'] ?? null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Cast value based on the column type.
     */
    private function castByColumnType(string $value, string $columnType): mixed
    {
        $columnType = strtolower($columnType);

        // Boolean types
        if (in_array($columnType, ['bool', 'boolean'], true)) {
            return match (strtolower($value)) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off' => false,
                default => $value,
            };
        }

        // Integer types
        if (in_array($columnType, ['int', 'int2', 'int4', 'int8', 'integer', 'smallint', 'bigint', 'serial', 'bigserial', 'smallserial'], true)) {
            if (is_numeric($value)) {
                return (int) $value;
            }
            return $value;
        }

        // Float/decimal types
        if (in_array($columnType, ['float', 'float4', 'float8', 'double', 'decimal', 'numeric', 'real', 'double precision'], true)) {
            if (is_numeric($value)) {
                return (float) $value;
            }
            return $value;
        }

        // String types - return as-is
        return $value;
    }

    /**
     * Generate a partition name from a value.
     */
    private function generateNameFromValue(mixed $value): string
    {
        $suffix = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($value)),
            default => md5(serialize($value)),
        };

        if ($this->baseName !== null) {
            return "{$this->baseName}_{$suffix}";
        }

        return $suffix;
    }

    /**
     * Resolve partition name, replacing % with baseName if present.
     */
    private function resolvePartitionName(string $name): string
    {
        if (str_contains($name, '%')) {
            $baseName = $this->baseName ?? '';
            return str_replace('%', $baseName, $name);
        }

        return $name;
    }
}
