<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use Exception;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\Exceptions\PartitionException;
use Uzbek\LaravelPartitionManager\Services\PartitionSchemaManager;
use Uzbek\LaravelPartitionManager\ValueObjects\HashPartition;
use Uzbek\LaravelPartitionManager\ValueObjects\ListPartition;
use Uzbek\LaravelPartitionManager\ValueObjects\PartitionDefinition;
use Uzbek\LaravelPartitionManager\ValueObjects\RangePartition;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class PostgresPartitionBuilder
{
    protected ?Blueprint $blueprint = null;

    protected ?\Closure $tableCallback = null;

    protected ?string $connectionName = null;

    protected PartitionType $partitionType = PartitionType::RANGE;

    protected ?string $partitionColumn = null;

    /** @var array<int, PartitionDefinition> */
    protected array $partitions = [];

    /** @var array<int, mixed> */
    protected array $indexes = [];

    protected ?string $tablespace = null;

    protected PartitionSchemaManager $schemaManager;

    protected bool $enablePartitionPruning;

    protected ?PartitionDefinition $defaultPartition = null;

    /** @var array<string, string> */
    protected array $checkConstraints = [];

    protected bool $detachConcurrently;

    public function __construct(
        protected readonly string $table,
    ) {
        $this->schemaManager = new PartitionSchemaManager();
        $this->enablePartitionPruning = (bool) config('partition-manager.defaults.enable_partition_pruning', true);
        $this->detachConcurrently = (bool) config('partition-manager.defaults.detach_concurrently', true);
    }

    public function setBlueprint(Blueprint $blueprint): self
    {
        $this->blueprint = $blueprint;
        return $this;
    }

    public function defineTable(\Closure $callback): self
    {
        $this->tableCallback = $callback;
        return $this;
    }

    public function connection(string $connection): self
    {
        $this->connectionName = $connection;

        return $this;
    }

    public function partition(PartitionType|string $type): self
    {
        $this->partitionType = $type instanceof PartitionType
            ? $type
            : PartitionType::from(strtoupper($type));

        return $this;
    }

    public function range(): self
    {
        return $this->partition(PartitionType::RANGE);
    }

    public function list(): self
    {
        return $this->partition(PartitionType::LIST);
    }

    public function hash(): self
    {
        return $this->partition(PartitionType::HASH);
    }

    /**
     * @param string|array<int, string> $columns
     */
    public function partitionBy(string|array $columns): self
    {
        $this->partitionColumn = is_array($columns)
            ? implode(', ', $columns)
            : $columns;

        return $this;
    }

    public function partitionByExpression(string $expression): self
    {
        $this->partitionColumn = $expression;

        return $this;
    }

    public function partitionByYear(string $column): self
    {
        $this->partitionColumn = "EXTRACT(YEAR FROM {$column})";

        return $this;
    }

    public function partitionByMonth(string $column): self
    {
        $this->partitionColumn = "DATE_TRUNC('month', {$column})";

        return $this;
    }

    public function partitionByDay(string $column): self
    {
        $this->partitionColumn = "DATE_TRUNC('day', {$column})";

        return $this;
    }

    public function addPartition(PartitionDefinition $partition): self
    {
        $this->partitions[] = $partition;

        return $this;
    }

    public function addRangePartition(string $name, mixed $from, mixed $to, ?string $schema = null): self
    {
        $partition = RangePartition::range($name)->withRange($from, $to);

        if ($schema !== null) {
            $partition->withSchema($schema);
        }

        $this->partitions[] = $partition;

        return $this;
    }

    /**
     * @param array<int, mixed> $values
     */
    public function addListPartition(string $name, array $values, ?string $schema = null): self
    {
        $partition = ListPartition::list($name)->withValues($values);

        if ($schema !== null) {
            $partition->withSchema($schema);
        }

        $this->partitions[] = $partition;

        return $this;
    }

    public function addHashPartition(string $name, int $modulus, int $remainder, ?string $schema = null): self
    {
        $partition = HashPartition::hash($name)->withHash($modulus, $remainder);

        if ($schema !== null) {
            $partition->withSchema($schema);
        }

        $this->partitions[] = $partition;

        return $this;
    }

    public function withSubPartitions(string $partitionName, SubPartitionBuilder $builder): self
    {
        foreach ($this->partitions as $partition) {
            if ($partition->getName() === $partitionName) {
                $partition->withSubPartitions($builder);
                break;
            }
        }

        return $this;
    }

    public function generateMonthlyPartitions(): self
    {
        return $this->generatePartitions(DateRangeBuilder::monthly());
    }

    public function generateYearlyPartitions(): self
    {
        return $this->generatePartitions(DateRangeBuilder::yearly());
    }

    public function generateDailyPartitions(): self
    {
        return $this->generatePartitions(DateRangeBuilder::daily());
    }

    public function generateWeeklyPartitions(): self
    {
        return $this->generatePartitions(DateRangeBuilder::weekly());
    }

    public function generateQuarterlyPartitions(): self
    {
        return $this->generatePartitions(DateRangeBuilder::quarterly());
    }

    public function generatePartitions(DateRangeBuilder $builder): self
    {
        $partitions = $builder->build($this->table . '_');

        foreach ($partitions as $partition) {
            $this->addPartition($partition);
        }

        return $this;
    }

    public function withDateRange(DateRangeBuilder $builder): self
    {
        return $this->generatePartitions($builder);
    }

    public function hashPartitions(int $count, string $prefix = ''): self
    {
        $separator = (string) config('partition-manager.naming.separator', '_');

        for ($i = 0; $i < $count; $i++) {
            $partitionName = ($prefix !== '' ? $prefix : $this->table . '_part' . $separator) . $i;
            $this->addHashPartition($partitionName, $count, $i);
        }

        return $this;
    }

    public function withDefaultPartition(string $name = 'default'): self
    {
        $this->defaultPartition = PartitionDefinition::list($name);

        return $this;
    }

    public function tablespace(string $tablespace): self
    {
        $this->tablespace = $tablespace;

        return $this;
    }

    public function partitionSchema(string $schema): self
    {
        $this->schemaManager->setDefault($schema);

        return $this;
    }

    public function registerSchema(string $partitionType, string $schema): self
    {
        $this->schemaManager->register($partitionType, $schema);

        return $this;
    }

    /**
     * @param array<string, string> $schemas
     */
    public function registerSchemas(array $schemas): self
    {
        $this->schemaManager->registerMultiple($schemas);

        return $this;
    }

    public function check(string $name, string $expression): self
    {
        $this->checkConstraints[$name] = $expression;

        return $this;
    }

    public function enablePartitionPruning(bool $enable = true): self
    {
        $this->enablePartitionPruning = $enable;

        return $this;
    }

    public function detachConcurrently(bool $enable = true): self
    {
        $this->detachConcurrently = $enable;

        return $this;
    }

    public function create(): void
    {
        $connection = $this->getConnection();

        $connection->beginTransaction();

        try {
            $this->createPartitionedTable($connection);

            foreach ($this->partitions as $partition) {
                $this->createPartition($connection, $partition);
            }

            if ($this->defaultPartition !== null) {
                $this->createDefaultPartition($connection);
            }

            $this->createIndexes($connection);
            $this->addCheckConstraints($connection);

            if (config('partition-manager.defaults.analyze_after_create', true)) {
                $this->analyze();
            }

            $connection->commit();
        } catch (Exception $e) {
            $connection->rollBack();
            throw new PartitionException(
                "Failed to create partitioned table: " . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function execute(): void
    {
        $this->create();
    }

    protected function createPartitionedTable(Connection $connection): void
    {
        if ($this->blueprint === null && $this->tableCallback !== null) {
            $this->blueprint = new Blueprint($connection, $this->table);
            $this->blueprint->create();
            ($this->tableCallback)($this->blueprint);
        }

        if ($this->blueprint === null) {
            throw new PartitionException(
                "Blueprint not set. Use Partition::create() or Partition::table() to define the table structure."
            );
        }

        if ($this->partitionColumn === null) {
            throw new PartitionException(
                "Partition column not specified. Use partitionBy() to set the partition column."
            );
        }

        $statements = $this->blueprint->toSql($connection, $connection->getSchemaGrammar());

        if ($statements !== []) {
            $createStatement = $statements[0];

            $createStatement = rtrim($createStatement, ';');
            $createStatement = rtrim($createStatement, ')');

            $createStatement .= ") PARTITION BY {$this->partitionType->value} ({$this->partitionColumn})";

            if ($this->tablespace !== null) {
                $createStatement .= " TABLESPACE {$this->tablespace}";
            }

            $connection->statement($createStatement);

            $count = count($statements);
            for ($i = 1; $i < $count; $i++) {
                if (!str_contains(strtolower($statements[$i]), 'index')) {
                    $connection->statement($statements[$i]);
                }
            }
        }
    }

    protected function createPartition(Connection $connection, PartitionDefinition $partition): void
    {
        $partitionTable = $partition->getName();
        $separator = (string) config('partition-manager.naming.separator', '_');

        if (!str_starts_with($partitionTable, $this->table)) {
            $partitionTable = $this->table . $separator . $partition->getName();
        }

        $schema = $partition->getSchema() ?? $this->schemaManager->getDefault();

        if ($schema !== null) {
            $quotedSchema = self::quoteIdentifier($schema);
            $connection->statement("CREATE SCHEMA IF NOT EXISTS {$quotedSchema}");
            $partitionTable = $schema . '.' . $partitionTable;
        }

        $quotedPartitionTable = self::quoteIdentifier($partitionTable);
        $quotedMainTable = self::quoteIdentifier($this->table);
        $sql = "CREATE TABLE IF NOT EXISTS {$quotedPartitionTable} PARTITION OF {$quotedMainTable} ";

        if ($partition instanceof RangePartition) {
            $from = self::formatSqlValue($partition->getFrom());
            $to = self::formatSqlValue($partition->getTo());
            $sql .= "FOR VALUES FROM ({$from}) TO ({$to})";
        } elseif ($partition instanceof ListPartition) {
            $values = array_map(
                static fn (mixed $v): string => self::formatSqlValue($v),
                $partition->getValues()
            );
            $sql .= "FOR VALUES IN (" . implode(', ', $values) . ")";
        } elseif ($partition instanceof HashPartition) {
            $sql .= "FOR VALUES WITH (modulus {$partition->getModulus()}, remainder {$partition->getRemainder()})";
        }

        if ($partition->hasSubPartitions()) {
            $subPartitions = $partition->getSubPartitions()?->toArray() ?? [];
            $subPartitionType = strtoupper($subPartitions['partition_by']['type']);
            $subPartitionColumn = self::quoteIdentifier($subPartitions['partition_by']['column']);
            $sql .= " PARTITION BY {$subPartitionType} ({$subPartitionColumn})";
        }

        $connection->statement($sql);

        if ($partition->hasSubPartitions()) {
            $subPartitions = $partition->getSubPartitions()?->toArray() ?? [];
            foreach ($subPartitions['partitions'] as $subPartition) {
                $this->createSubPartition($connection, $partitionTable, $subPartition);
            }
        }
    }

    /**
     * @param array<string, mixed> $subPartition
     */
    protected function createSubPartition(Connection $connection, string $parentTable, array $subPartition): void
    {
        $subPartitionTable = $subPartition['name'];

        if (!empty($subPartition['schema'])) {
            $quotedSchema = self::quoteIdentifier($subPartition['schema']);
            $connection->statement("CREATE SCHEMA IF NOT EXISTS {$quotedSchema}");
            $subPartitionTable = $subPartition['schema'] . '.' . $subPartitionTable;
        }

        $sql = match ($subPartition['type']) {
            'RANGE' => $this->buildRangeSubPartitionSql($subPartitionTable, $parentTable, $subPartition),
            'LIST' => $this->buildListSubPartitionSql($subPartitionTable, $parentTable, $subPartition),
            default => throw new PartitionException("Unknown sub-partition type: {$subPartition['type']}"),
        };

        if (!empty($subPartition['tablespace'])) {
            $quotedTablespace = self::quoteIdentifier($subPartition['tablespace']);
            $sql .= " TABLESPACE {$quotedTablespace}";
        }

        $connection->statement($sql);
    }

    /**
     * @param array<string, mixed> $subPartition
     */
    private function buildRangeSubPartitionSql(string $tableName, string $parentTable, array $subPartition): string
    {
        $quotedTable = self::quoteIdentifier($tableName);
        $quotedParent = self::quoteIdentifier($parentTable);
        $sql = "CREATE TABLE IF NOT EXISTS {$quotedTable} PARTITION OF {$quotedParent} ";
        $from = self::formatSqlValue($subPartition['from']);
        $to = self::formatSqlValue($subPartition['to']);

        return $sql . "FOR VALUES FROM ({$from}) TO ({$to})";
    }

    /**
     * @param array<string, mixed> $subPartition
     */
    private function buildListSubPartitionSql(string $tableName, string $parentTable, array $subPartition): string
    {
        $quotedTable = self::quoteIdentifier($tableName);
        $quotedParent = self::quoteIdentifier($parentTable);
        $sql = "CREATE TABLE IF NOT EXISTS {$quotedTable} PARTITION OF {$quotedParent} ";
        $values = array_map(
            static fn (mixed $v): string => self::formatSqlValue($v),
            $subPartition['values']
        );

        return $sql . "FOR VALUES IN (" . implode(', ', $values) . ")";
    }

    protected function createDefaultPartition(Connection $connection): void
    {
        if ($this->defaultPartition === null) {
            return;
        }

        $separator = (string) config('partition-manager.naming.separator', '_');
        $partitionTable = $this->table . $separator . $this->defaultPartition->getName();

        $schema = $this->defaultPartition->getSchema() ?? $this->schemaManager->getDefault();

        if ($schema !== null) {
            $quotedSchema = self::quoteIdentifier($schema);
            $connection->statement("CREATE SCHEMA IF NOT EXISTS {$quotedSchema}");
            $partitionTable = $schema . '.' . $partitionTable;
        }

        $quotedPartitionTable = self::quoteIdentifier($partitionTable);
        $quotedMainTable = self::quoteIdentifier($this->table);
        $sql = "CREATE TABLE IF NOT EXISTS {$quotedPartitionTable} PARTITION OF {$quotedMainTable} DEFAULT";

        if ($this->tablespace !== null) {
            $quotedTablespace = self::quoteIdentifier($this->tablespace);
            $sql .= " TABLESPACE {$quotedTablespace}";
        }

        $connection->statement($sql);
    }

    protected function createIndexes(Connection $connection): void
    {
        if ($this->blueprint === null) {
            return;
        }

        $commands = $this->blueprint->getCommands();
        $quotedTable = self::quoteIdentifier($this->table);

        foreach ($commands as $command) {
            if (in_array($command->name, ['index', 'unique'], true)) {
                $indexName = $command->index ?? $this->table . '_' . implode('_', $command->columns) . '_index';
                $quotedIndexName = self::quoteIdentifier($indexName);
                $quotedColumns = implode(', ', array_map(
                    static fn (string $col): string => self::quoteIdentifier($col),
                    $command->columns
                ));

                $sql = "CREATE ";
                if ($command->name === 'unique') {
                    $sql .= "UNIQUE ";
                }
                $sql .= "INDEX IF NOT EXISTS {$quotedIndexName} ON {$quotedTable} ({$quotedColumns})";

                $connection->statement($sql);
            }
        }
    }

    protected function addCheckConstraints(Connection $connection): void
    {
        $quotedTable = self::quoteIdentifier($this->table);

        foreach ($this->checkConstraints as $name => $expression) {
            $quotedName = self::quoteIdentifier($name);
            $sql = "ALTER TABLE {$quotedTable} ADD CONSTRAINT {$quotedName} CHECK ({$expression})";
            $connection->statement($sql);
        }
    }

    public function attachPartition(string $tableName, string $partitionName, mixed $from, mixed $to): self
    {
        $connection = $this->getConnection();
        $fromValue = self::formatSqlValue($from);
        $toValue = self::formatSqlValue($to);

        $quotedTable = self::quoteIdentifier($this->table);
        $quotedPartition = self::quoteIdentifier($tableName);
        $sql = "ALTER TABLE {$quotedTable} ATTACH PARTITION {$quotedPartition} FOR VALUES FROM ({$fromValue}) TO ({$toValue})";

        $connection->statement($sql);

        return $this;
    }

    public function detachPartition(string $partitionName, ?bool $concurrently = null): self
    {
        $connection = $this->getConnection();
        $useConcurrently = $concurrently ?? $this->detachConcurrently;

        $quotedTable = self::quoteIdentifier($this->table);
        $quotedPartition = self::quoteIdentifier($partitionName);
        $sql = "ALTER TABLE {$quotedTable} DETACH PARTITION {$quotedPartition}";

        if ($useConcurrently) {
            $sql .= " CONCURRENTLY";
        }

        $connection->statement($sql);

        return $this;
    }

    public function dropPartition(string $partitionName): self
    {
        $connection = $this->getConnection();
        $quotedPartition = self::quoteIdentifier($partitionName);
        $connection->statement("DROP TABLE IF EXISTS {$quotedPartition} CASCADE");

        if (config('partition-manager.defaults.vacuum_after_drop', true)) {
            $this->vacuum();
        }

        return $this;
    }

    public function analyze(): self
    {
        $quotedTable = self::quoteIdentifier($this->table);
        $this->getConnection()->statement("ANALYZE {$quotedTable}");

        return $this;
    }

    public function vacuum(bool $full = false): self
    {
        $quotedTable = self::quoteIdentifier($this->table);
        $sql = $full ? "VACUUM FULL {$quotedTable}" : "VACUUM {$quotedTable}";
        $this->getConnection()->statement($sql);

        return $this;
    }

    private function getConnection(): Connection
    {
        return $this->connectionName !== null
            ? DB::connection($this->connectionName)
            : DB::connection();
    }

    private static function formatSqlValue(mixed $value): string
    {
        // Handle arrays for multi-column partitioning
        if (is_array($value)) {
            return implode(', ', array_map(
                static fn (mixed $v): string => self::formatSqlValue($v),
                $value
            ));
        }

        // Handle PostgreSQL partition bound keywords (must be unquoted)
        if ($value === 'MINVALUE' || $value === 'MAXVALUE') {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return "'" . $value . "'";
    }

    private static function quoteIdentifier(string $identifier): string
    {
        // Handle schema.table format
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(
                static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"',
                $parts
            ));
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}