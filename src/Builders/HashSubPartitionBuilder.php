<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\ValueObjects\HashSubPartition;

class HashSubPartitionBuilder extends AbstractSubPartitionBuilder
{
    public function __construct(string $partitionColumn)
    {
        parent::__construct(PartitionType::HASH, $partitionColumn);
    }

    /**
     * Add a single hash partition.
     */
    public function addHashPartition(
        string $name,
        int $modulus,
        int $remainder,
        ?string $schema = null,
        ?AbstractSubPartitionBuilder $subPartitions = null
    ): self {
        $partition = HashSubPartition::create($name)->withHash($modulus, $remainder);

        $this->applySchema($partition, $schema);

        if ($subPartitions !== null) {
            if ($this->tableName !== null) {
                $subPartitions->table($this->tableName);
            }
            $subPartitions->for($name);
            $partition->withSubPartitions($subPartitions);
        }

        $this->partitions[] = $partition;

        return $this;
    }

    /**
     * Add multiple hash partitions at once.
     *
     * @param int $modulus Number of partitions (modulus value)
     * @param string|null $prefix Optional name prefix. If null, auto-generates using baseName (set via for()) or 'p'
     * @param string|null $schema Optional schema for all partitions
     */
    public function addHashPartitions(int $modulus, ?string $prefix = null, ?string $schema = null): self
    {
        // If prefix is null and baseName is not yet set, defer partition creation
        if ($prefix === null && $this->baseName === null) {
            $this->addDeferredPartition([
                'type' => 'hash_batch',
                'modulus' => $modulus,
                'schema' => $schema,
            ]);

            return $this;
        }

        // Generate partitions immediately
        $this->createHashPartitions($modulus, $prefix, $schema);

        return $this;
    }

    /**
     * Actually create the hash partitions.
     */
    private function createHashPartitions(int $modulus, ?string $prefix, ?string $schema): void
    {
        $resolvedPrefix = $prefix !== null
            ? $this->resolvePrefix($prefix)
            : "{$this->baseName}_p";

        for ($remainder = 0; $remainder < $modulus; $remainder++) {
            $name = $resolvedPrefix . $remainder;
            $partition = HashSubPartition::create($name)->withHash($modulus, $remainder);

            $this->applySchema($partition, $schema);
            $this->partitions[] = $partition;
        }
    }

    /**
     * Generate deferred hash partitions.
     */
    protected function generateDeferredPartitions(): void
    {
        if (empty($this->deferredPartitions)) {
            return;
        }

        $deferred = $this->deferredPartitions;
        $this->deferredPartitions = [];

        foreach ($deferred as $config) {
            if ($config['type'] === 'hash_batch') {
                $this->createHashPartitions(
                    $config['modulus'],
                    null,
                    $config['schema']
                );
            }
        }
    }
}
