<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use DateTime;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\Services\SchemaCreator;
use Uzbek\LaravelPartitionManager\Traits\BuilderHelper;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Database\Connection;

class QuickPartitionBuilder
{
    use SqlHelper;
    use BuilderHelper;

    protected PartitionType $partitionType = PartitionType::RANGE;

    protected string $partitionColumn = '';

    protected ?string $schema = null;

    public function __construct(
        protected readonly string $table,
    ) {}

    public static function table(string $table): self
    {
        return new self($table);
    }

    /**
     * Set the partition column.
     *
     * @param string $column The column name to partition by
     * @return self
     */
    public function by(string $column): self
    {
        $this->partitionColumn = $column;

        return $this;
    }

    /**
     * Set the schema for generated partitions.
     *
     * @param string $schema The schema name
     * @return self
     */
    public function schema(string $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    public function connection(string $connection): self
    {
        $this->connectionName = $connection;

        return $this;
    }

    public function monthly(int $count = 12, ?string $startDate = null): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateMonthly($count, $startDate);
    }

    public function yearly(int $count = 5, ?string $startDate = null): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateYearly($count, $startDate);
    }

    public function daily(int $count = 30, ?string $startDate = null): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateDaily($count, $startDate);
    }

    public function weekly(int $count = 12, ?string $startDate = null): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateWeekly($count, $startDate);
    }

    public function quarterly(int $count = 8, ?string $startDate = null): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateQuarterly($count, $startDate);
    }

    /**
     * @param array<string, array<int, mixed>> $partitions
     */
    public function byList(string $column, array $partitions): void
    {
        $this->partitionType = PartitionType::LIST;
        $this->partitionColumn = $column;
        $this->generateList($partitions);
    }

    public function byHash(string $column, int $count = 4): void
    {
        $this->partitionType = PartitionType::HASH;
        $this->partitionColumn = $column;
        $this->generateHash($count);
    }

    protected function generateMonthly(int $count, ?string $startDate): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $start = $startDate !== null ? new DateTime($startDate) : new DateTime();
        $start->modify('first day of this month');

        for ($i = 0; $i < $count; $i++) {
            $current = clone $start;
            $current->modify("+{$i} months");
            $next = clone $current;
            $next->modify('+1 month');

            $partitionName = $this->table . '_m' . $current->format('Y_m');
            $this->createRangePartition(
                $connection,
                $partitionName,
                $current->format('Y-m-d'),
                $next->format('Y-m-d')
            );
        }
    }

    protected function generateYearly(int $count, ?string $startDate): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $start = $startDate !== null ? new DateTime($startDate) : new DateTime();

        for ($i = 0; $i < $count; $i++) {
            $current = clone $start;
            $current->modify("+{$i} years");
            $next = clone $current;
            $next->modify('+1 year');

            $partitionName = $this->table . '_y' . $current->format('Y');
            $this->createRangePartition(
                $connection,
                $partitionName,
                $current->format('Y-m-d'),
                $next->format('Y-m-d')
            );
        }
    }

    protected function generateDaily(int $count, ?string $startDate): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $start = $startDate !== null ? new DateTime($startDate) : new DateTime();

        for ($i = 0; $i < $count; $i++) {
            $current = clone $start;
            $current->modify("+{$i} days");
            $next = clone $current;
            $next->modify('+1 day');

            $partitionName = $this->table . '_d' . $current->format('Y_m_d');
            $this->createRangePartition(
                $connection,
                $partitionName,
                $current->format('Y-m-d'),
                $next->format('Y-m-d')
            );
        }
    }

    protected function generateWeekly(int $count, ?string $startDate): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $start = $startDate !== null ? new DateTime($startDate) : new DateTime();
        $start->modify('monday this week');

        for ($i = 0; $i < $count; $i++) {
            $current = clone $start;
            $current->modify("+{$i} weeks");
            $next = clone $current;
            $next->modify('+1 week');

            $partitionName = $this->table . '_w' . $current->format('Y_m_d');
            $this->createRangePartition(
                $connection,
                $partitionName,
                $current->format('Y-m-d'),
                $next->format('Y-m-d')
            );
        }
    }

    protected function generateQuarterly(int $count, ?string $startDate): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $start = $startDate !== null ? new DateTime($startDate) : new DateTime();

        // Align to first day of the current quarter
        $month = (int) $start->format('n');
        $quarterStart = match (true) {
            $month <= 3 => 1,
            $month <= 6 => 4,
            $month <= 9 => 7,
            default => 10,
        };
        $start->setDate((int) $start->format('Y'), $quarterStart, 1);

        for ($i = 0; $i < $count; $i++) {
            $current = clone $start;
            $current->modify("+{$i} quarters");
            $next = clone $current;
            $next->modify('+3 months');

            $quarter = (int) ceil((int) $current->format('n') / 3);
            $partitionName = $this->table . '_q' . $current->format('Y') . '_Q' . $quarter;

            $this->createRangePartition(
                $connection,
                $partitionName,
                $current->format('Y-m-d'),
                $next->format('Y-m-d')
            );
        }
    }

    /**
     * @param array<string, array<int, mixed>> $partitions
     */
    protected function generateList(array $partitions): void
    {
        $connection = $this->getConnection();

        foreach ($partitions as $name => $values) {
            $partitionName = $this->table . '_' . $name;
            $this->createListPartition($connection, $partitionName, (array) $values);
        }
    }

    protected function generateHash(int $count): void
    {
        $connection = $this->getConnection();

        for ($i = 0; $i < $count; $i++) {
            $partitionName = $this->table . '_part_' . $i;
            $this->createHashPartition($connection, $partitionName, $count, $i);
        }
    }

    protected function createRangePartition(Connection $connection, string $name, string $from, string $to): void
    {
        $fullName = SchemaCreator::ensureAndPrefix($name, $this->schema, $connection);

        $quotedFullName = self::quoteIdentifier($fullName);
        $quotedTable = self::quoteIdentifier($this->table);
        $sql = "CREATE TABLE IF NOT EXISTS {$quotedFullName} PARTITION OF {$quotedTable} ";
        $sql .= "FOR VALUES FROM ('{$from}') TO ('{$to}')";

        $connection->statement($sql);
    }

    /**
     * @param array<int, mixed> $values
     */
    protected function createListPartition(Connection $connection, string $name, array $values): void
    {
        $fullName = SchemaCreator::ensureAndPrefix($name, $this->schema, $connection);

        $valueList = array_map(
            static fn (mixed $v): string => self::formatSqlValue($v),
            $values
        );

        $quotedFullName = self::quoteIdentifier($fullName);
        $quotedTable = self::quoteIdentifier($this->table);
        $sql = "CREATE TABLE IF NOT EXISTS {$quotedFullName} PARTITION OF {$quotedTable} ";
        $sql .= "FOR VALUES IN (" . implode(', ', $valueList) . ")";

        $connection->statement($sql);
    }

    protected function createHashPartition(Connection $connection, string $name, int $modulus, int $remainder): void
    {
        $fullName = SchemaCreator::ensureAndPrefix($name, $this->schema, $connection);

        $quotedFullName = self::quoteIdentifier($fullName);
        $quotedTable = self::quoteIdentifier($this->table);
        $sql = "CREATE TABLE IF NOT EXISTS {$quotedFullName} PARTITION OF {$quotedTable} ";
        $sql .= "FOR VALUES WITH (modulus {$modulus}, remainder {$remainder})";

        $connection->statement($sql);
    }
}