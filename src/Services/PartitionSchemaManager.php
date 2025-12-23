<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

/**
 * Manages schema assignments for partitions.
 *
 * This class maintains runtime schema mappings that can override
 * the config-based defaults. Use SchemaCreator for actual schema
 * creation and config-based resolution.
 */
class PartitionSchemaManager
{
    /**
     * Runtime schema mappings (partition type => schema name).
     *
     * @var array<string, string>
     */
    protected array $schemas = [];

    /**
     * Runtime default schema (overrides config).
     */
    protected ?string $defaultSchema = null;

    public function __construct()
    {
        // Load config mappings as initial values
        $this->loadFromConfig();
    }

    /**
     * Load schema mappings from config.
     *
     * @return self
     */
    public function loadFromConfig(): self
    {
        $this->defaultSchema = config('partition-manager.schemas.default');

        $mappings = config('partition-manager.schemas.mappings', []);
        if (is_array($mappings)) {
            $this->schemas = $mappings;
        }

        return $this;
    }

    /**
     * Set the default schema for all partitions.
     *
     * @param string $schema The schema name
     * @return self
     */
    public function setDefault(string $schema): self
    {
        $this->defaultSchema = $schema;

        return $this;
    }

    /**
     * Register a schema for a specific partition type.
     *
     * @param string $partitionType The partition type (e.g., 'monthly', 'yearly')
     * @param string $schema The schema name
     * @return self
     */
    public function register(string $partitionType, string $schema): self
    {
        $this->schemas[$partitionType] = $schema;

        return $this;
    }

    /**
     * Register multiple schema mappings at once.
     *
     * @param array<string, string> $schemas Map of partition type to schema name
     * @return self
     */
    public function registerMultiple(array $schemas): self
    {
        foreach ($schemas as $type => $schema) {
            $this->register($type, $schema);
        }

        return $this;
    }

    /**
     * Get the schema for a specific partition type.
     *
     * Falls back to the default schema if no mapping exists.
     *
     * @param string $partitionType The partition type
     * @return string|null The schema name or null
     */
    public function getSchemaFor(string $partitionType): ?string
    {
        return $this->schemas[$partitionType] ?? $this->defaultSchema;
    }

    /**
     * Check if a schema mapping exists for a partition type.
     *
     * @param string $partitionType The partition type
     * @return bool True if a mapping exists
     */
    public function hasSchemaFor(string $partitionType): bool
    {
        return isset($this->schemas[$partitionType]);
    }

    /**
     * Get the default schema.
     *
     * @return string|null The default schema or null
     */
    public function getDefault(): ?string
    {
        return $this->defaultSchema;
    }

    /**
     * Get all registered schema mappings.
     *
     * @return array<string, string> Map of partition type to schema name
     */
    public function getAllSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * Clear all runtime schema settings and reload from config.
     *
     * @return self
     */
    public function clear(): self
    {
        $this->schemas = [];
        $this->defaultSchema = null;

        return $this;
    }

    /**
     * Reset to config defaults.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->clear();
        $this->loadFromConfig();

        return $this;
    }
}