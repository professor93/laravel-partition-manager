<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\ValueObjects\HashSubPartition;
use Uzbek\LaravelPartitionManager\ValueObjects\ListSubPartition;
use Uzbek\LaravelPartitionManager\ValueObjects\RangeSubPartition;
use Uzbek\LaravelPartitionManager\ValueObjects\SubPartition;

class SubPartitionBuilder
{
    /** @var array<int, SubPartition> */
    protected array $partitions = [];

    protected PartitionType $partitionType;

    protected ?string $defaultSchema = null;

    public function __construct(
        PartitionType|string $type,
        protected readonly string $partitionColumn,
    ) {
        $this->partitionType = $type instanceof PartitionType
            ? $type
            : PartitionType::from(strtoupper($type));
    }

    public static function list(string $column): self
    {
        return new self(PartitionType::LIST, $column);
    }

    public static function range(string $column): self
    {
        return new self(PartitionType::RANGE, $column);
    }

    public static function hash(string $column): self
    {
        return new self(PartitionType::HASH, $column);
    }

    public function defaultSchema(string $schema): self
    {
        $this->defaultSchema = $schema;

        return $this;
    }

    public function add(SubPartition $partition): self
    {
        if ($this->defaultSchema !== null && $partition->getSchema() === null) {
            $partition->withSchema($this->defaultSchema);
        }

        $this->partitions[] = $partition;

        return $this;
    }

    /**
     * @param array<int, mixed> $values
     */
    public function addListPartition(string $name, array $values, ?string $schema = null): self
    {
        $partition = ListSubPartition::create($name)->withValues($values);

        $this->applySchema($partition, $schema);
        $this->partitions[] = $partition;

        return $this;
    }

    public function addRangePartition(string $name, mixed $from, mixed $to, ?string $schema = null): self
    {
        $partition = RangeSubPartition::create($name)->withRange($from, $to);

        $this->applySchema($partition, $schema);
        $this->partitions[] = $partition;

        return $this;
    }

    public function addHashPartition(string $name, int $modulus, int $remainder, ?string $schema = null): self
    {
        $partition = HashSubPartition::create($name)->withHash($modulus, $remainder);

        $this->applySchema($partition, $schema);
        $this->partitions[] = $partition;

        return $this;
    }

    /**
     * @return array<int, SubPartition>
     */
    public function getPartitions(): array
    {
        return $this->partitions;
    }

    /**
     * @return array{partition_by: array{type: string, column: string}, partitions: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'partition_by' => [
                'type' => $this->partitionType->value,
                'column' => $this->partitionColumn,
            ],
            'partitions' => array_map(
                static fn (SubPartition $partition): array => $partition->toArray(),
                $this->partitions
            ),
        ];
    }

    private function applySchema(SubPartition $partition, ?string $schema): void
    {
        if ($schema !== null) {
            $partition->withSchema($schema);
        } elseif ($this->defaultSchema !== null) {
            $partition->withSchema($this->defaultSchema);
        }
    }
}