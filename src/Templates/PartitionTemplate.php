<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Templates;

use InvalidArgumentException;
use Uzbek\LaravelPartitionManager\Builders\PostgresPartitionBuilder;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;

/**
 * Reusable partition configuration template.
 *
 * Templates can be defined in config or programmatically, and applied to multiple tables.
 * The % placeholder is replaced with the table name (e.g., '%_archive' → 'orders_archive').
 *
 * Config example:
 *     'templates' => [
 *         'monthly_archive' => [
 *             'type' => 'range',
 *             'column' => 'created_at',
 *             'interval' => 'monthly',
 *             'count' => 12,
 *             'schema' => '%_archive',  // % is replaced with table name
 *         ],
 *     ]
 *
 * Usage:
 *     Partition::create('orders', fn($t) => $t->id())
 *         ->fromTemplate('monthly_archive')
 *         ->generate();
 */
class PartitionTemplate
{
    protected string $name;
    protected ?PartitionType $type = null;
    protected string|array|null $columns = null;
    protected ?string $interval = null;
    protected int $count = 12;
    protected ?string $schema = null;
    protected ?string $tablespace = null;
    protected bool $defaultPartition = false;
    protected int $futurePartitions = 0;
    protected int $hashModulus = 0;
    protected ?string $prefix = null;

    /** @var array<int, mixed> */
    protected array $listValues = [];

    public function __construct(string $name = 'anonymous')
    {
        $this->name = $name;
    }

    /**
     * Create a new template with fluent interface.
     */
    public static function define(string $name = 'anonymous'): self
    {
        return new self($name);
    }

    /**
     * Load a template from configuration.
     *
     * @throws InvalidArgumentException If template not found
     */
    public static function fromConfig(string $name): self
    {
        $config = config("partition-manager.templates.{$name}");

        if ($config === null) {
            throw new InvalidArgumentException("Partition template '{$name}' not found in config.");
        }

        return self::fromArray($name, $config);
    }

    /**
     * Create a template from an array configuration.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $template = new self($name);

        if (isset($config['type'])) {
            $template->type = PartitionType::from(strtoupper($config['type']));
        }

        $template->columns = $config['column'] ?? $config['columns'] ?? null;
        $template->interval = $config['interval'] ?? null;
        $template->count = $config['count'] ?? 12;
        $template->schema = $config['schema'] ?? null;
        $template->tablespace = $config['tablespace'] ?? null;
        $template->defaultPartition = $config['default_partition'] ?? false;
        $template->futurePartitions = $config['future_partitions'] ?? 0;
        $template->hashModulus = $config['modulus'] ?? 0;
        $template->prefix = $config['prefix'] ?? null;
        $template->listValues = $config['values'] ?? [];

        return $template;
    }

    /**
     * Create a new template with same settings but overrides applied.
     *
     * @param array<string, mixed> $overrides
     */
    public function merge(array $overrides): self
    {
        $merged = clone $this;

        if (isset($overrides['type'])) {
            $merged->type = PartitionType::from(strtoupper($overrides['type']));
        }
        if (isset($overrides['column']) || isset($overrides['columns'])) {
            $merged->columns = $overrides['column'] ?? $overrides['columns'];
        }
        if (isset($overrides['interval'])) {
            $merged->interval = $overrides['interval'];
        }
        if (isset($overrides['count'])) {
            $merged->count = $overrides['count'];
        }
        if (isset($overrides['schema'])) {
            $merged->schema = $overrides['schema'];
        }
        if (isset($overrides['tablespace'])) {
            $merged->tablespace = $overrides['tablespace'];
        }
        if (isset($overrides['default_partition'])) {
            $merged->defaultPartition = $overrides['default_partition'];
        }
        if (isset($overrides['future_partitions'])) {
            $merged->futurePartitions = $overrides['future_partitions'];
        }
        if (isset($overrides['modulus'])) {
            $merged->hashModulus = $overrides['modulus'];
        }
        if (isset($overrides['prefix'])) {
            $merged->prefix = $overrides['prefix'];
        }
        if (isset($overrides['values'])) {
            $merged->listValues = $overrides['values'];
        }

        return $merged;
    }

    /**
     * Apply this template to a partition builder.
     *
     * @param PostgresPartitionBuilder $builder The builder to configure
     * @param string $tableName The table name (for % placeholder replacement)
     */
    public function applyTo(PostgresPartitionBuilder $builder, string $tableName): PostgresPartitionBuilder
    {
        // Set partition column
        if ($this->columns !== null) {
            $builder->by($this->columns);
        }

        // Set schema (with % placeholder replacement)
        if ($this->schema !== null) {
            $resolvedSchema = $this->resolvePlaceholder($this->schema, $tableName);
            $builder->schema($resolvedSchema);
        }

        // Set tablespace
        if ($this->tablespace !== null) {
            $builder->tablespace($this->tablespace);
        }

        // Resolve prefix with placeholder
        $resolvedPrefix = $this->prefix !== null
            ? $this->resolvePlaceholder($this->prefix, $tableName)
            : null;

        // Apply partition type and create partitions
        if ($this->type !== null) {
            match ($this->type) {
                PartitionType::RANGE => $this->applyRangePartitions($builder, $resolvedPrefix),
                PartitionType::LIST => $this->applyListPartitions($builder, $resolvedPrefix),
                PartitionType::HASH => $this->applyHashPartitions($builder, $resolvedPrefix),
            };
        }

        // Add default partition
        if ($this->defaultPartition) {
            $builder->withDefaultPartition();
        }

        return $builder;
    }

    /**
     * Replace % placeholder with the given value.
     */
    protected function resolvePlaceholder(string $value, string $replacement): string
    {
        return str_replace('%', $replacement, $value);
    }

    /**
     * Apply RANGE partition configuration.
     */
    protected function applyRangePartitions(PostgresPartitionBuilder $builder, ?string $prefix): void
    {
        $totalCount = $this->count + $this->futurePartitions;

        match ($this->interval) {
            'daily' => $builder->addDailyPartitions($totalCount, null, $prefix),
            'weekly' => $builder->addWeeklyPartitions($totalCount, null, $prefix),
            'monthly' => $builder->addMonthlyPartitions($totalCount, null, $prefix),
            'yearly' => $builder->addYearlyPartitions($totalCount, null, $prefix),
            'quarterly' => $builder->addMonthlyPartitions($totalCount * 3, null, $prefix), // Approximation
            default => throw new InvalidArgumentException("Unknown interval: {$this->interval}"),
        };
    }

    /**
     * Apply LIST partition configuration.
     */
    protected function applyListPartitions(PostgresPartitionBuilder $builder, ?string $prefix): void
    {
        if (!empty($this->listValues)) {
            $builder->addListPartitions($this->listValues);
        }
    }

    /**
     * Apply HASH partition configuration.
     */
    protected function applyHashPartitions(PostgresPartitionBuilder $builder, ?string $prefix): void
    {
        if ($this->hashModulus > 0) {
            $builder->addHashPartitions($this->hashModulus, $prefix);
        }
    }

    // ========================================
    // Fluent builder methods for programmatic definition
    // ========================================

    /**
     * Set partition type to RANGE with given column(s).
     */
    public function range(string|array $columns): self
    {
        $this->type = PartitionType::RANGE;
        $this->columns = $columns;
        return $this;
    }

    /**
     * Set partition type to LIST with given column.
     */
    public function list(string $column): self
    {
        $this->type = PartitionType::LIST;
        $this->columns = $column;
        return $this;
    }

    /**
     * Set partition type to HASH with given column and modulus.
     */
    public function hash(string $column, int $modulus): self
    {
        $this->type = PartitionType::HASH;
        $this->columns = $column;
        $this->hashModulus = $modulus;
        return $this;
    }

    /**
     * Set interval to daily with given count.
     */
    public function daily(int $count = 30): self
    {
        $this->interval = 'daily';
        $this->count = $count;
        return $this;
    }

    /**
     * Set interval to weekly with given count.
     */
    public function weekly(int $count = 12): self
    {
        $this->interval = 'weekly';
        $this->count = $count;
        return $this;
    }

    /**
     * Set interval to monthly with given count.
     */
    public function monthly(int $count = 12): self
    {
        $this->interval = 'monthly';
        $this->count = $count;
        return $this;
    }

    /**
     * Set interval to yearly with given count.
     */
    public function yearly(int $count = 5): self
    {
        $this->interval = 'yearly';
        $this->count = $count;
        return $this;
    }

    /**
     * Set list partition values.
     *
     * @param array<int, mixed> $values
     */
    public function withValues(array $values): self
    {
        $this->listValues = $values;
        return $this;
    }

    /**
     * Set the schema (supports % placeholder).
     */
    public function withSchema(string $schema): self
    {
        $this->schema = $schema;
        return $this;
    }

    /**
     * Set the tablespace.
     */
    public function withTablespace(string $tablespace): self
    {
        $this->tablespace = $tablespace;
        return $this;
    }

    /**
     * Set the partition name prefix (supports % placeholder).
     */
    public function withPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Enable default partition.
     */
    public function withDefaultPartition(bool $enabled = true): self
    {
        $this->defaultPartition = $enabled;
        return $this;
    }

    /**
     * Set number of additional future partitions to create.
     */
    public function withFuturePartitions(int $count): self
    {
        $this->futurePartitions = $count;
        return $this;
    }

    /**
     * Get template name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get partition type.
     */
    public function getType(): ?PartitionType
    {
        return $this->type;
    }

    /**
     * Get partition column(s).
     */
    public function getColumns(): string|array|null
    {
        return $this->columns;
    }

    /**
     * Get interval.
     */
    public function getInterval(): ?string
    {
        return $this->interval;
    }

    /**
     * Get partition count.
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * Get schema.
     */
    public function getSchema(): ?string
    {
        return $this->schema;
    }
}
