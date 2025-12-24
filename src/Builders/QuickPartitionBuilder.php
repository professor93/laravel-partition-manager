<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use DateTime;
use DateTimeInterface;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\Services\SchemaCreator;
use Uzbek\LaravelPartitionManager\Traits\BuilderHelper;
use Uzbek\LaravelPartitionManager\Traits\DateNormalizer;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Database\Connection;

class QuickPartitionBuilder
{
    use SqlHelper;
    use BuilderHelper;
    use DateNormalizer;

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

    /**
     * Generate monthly partitions.
     *
     * @param int $count Number of partitions to create
     * @param int|string|DateTimeInterface|null $start Starting date. Accepts:
     *        - int: year (2026 → 2026-01-01)
     *        - string: "2026", "2026-01", "2026-01-15", or any parseable date
     *        - DateTimeInterface: used directly
     *        - null: uses current month
     * @param bool $fromToday If true and start is null, explicitly start from today
     */
    public function monthly(int $count = 12, int|string|DateTimeInterface|null $start = null, bool $fromToday = false): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateMonthly($count, $start, $fromToday);
    }

    /**
     * Generate yearly partitions.
     *
     * @param int $count Number of partitions to create
     * @param int|string|DateTimeInterface|null $start Starting date. Accepts:
     *        - int: year (2026 → 2026-01-01)
     *        - string: "2026", "2026-06", "2026-06-01", or any parseable date
     *        - DateTimeInterface: used directly
     *        - null: uses current date
     * @param bool $fromToday If true and start is null, explicitly start from today
     */
    public function yearly(int $count = 5, int|string|DateTimeInterface|null $start = null, bool $fromToday = false): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateYearly($count, $start, $fromToday);
    }

    /**
     * Generate daily partitions.
     *
     * @param int $count Number of partitions to create
     * @param int|string|DateTimeInterface|null $start Starting date. Accepts:
     *        - int: year (2026 → 2026-01-01)
     *        - string: "2026", "2026-01", "2026-01-15", or any parseable date
     *        - DateTimeInterface: used directly
     *        - null: uses current date
     * @param bool $fromToday If true and start is null, explicitly start from today
     */
    public function daily(int $count = 30, int|string|DateTimeInterface|null $start = null, bool $fromToday = false): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateDaily($count, $start, $fromToday);
    }

    /**
     * Generate weekly partitions.
     *
     * @param int $count Number of partitions to create
     * @param int|string|DateTimeInterface|null $start Starting date. Accepts:
     *        - int: year (2026 → 2026-01-01, aligned to Monday)
     *        - string: "2026", "2026-01", "2026-01-15", or any parseable date
     *        - DateTimeInterface: used directly
     *        - null: uses current week's Monday
     * @param bool $fromToday If true and start is null, explicitly start from today's week
     */
    public function weekly(int $count = 12, int|string|DateTimeInterface|null $start = null, bool $fromToday = false): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateWeekly($count, $start, $fromToday);
    }

    /**
     * Generate quarterly partitions.
     *
     * @param int $count Number of partitions to create
     * @param int|string|DateTimeInterface|null $start Starting date. Accepts:
     *        - int: year (2026 → 2026-01-01)
     *        - string: "2026", "2026-04", "2026-04-01", or any parseable date
     *        - DateTimeInterface: used directly
     *        - null: uses current quarter
     * @param bool $fromToday If true and start is null, explicitly start from today
     */
    public function quarterly(int $count = 8, int|string|DateTimeInterface|null $start = null, bool $fromToday = false): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateQuarterly($count, $start, $fromToday);
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

    protected function generateMonthly(int $count, int|string|DateTimeInterface|null $start, bool $fromToday = false): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $startDate = $this->normalizeDateForInterval($start, 'monthly', $fromToday);

        for ($i = 0; $i < $count; $i++) {
            $current = clone $startDate;
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

    protected function generateYearly(int $count, int|string|DateTimeInterface|null $start, bool $fromToday = false): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $startDate = $this->normalizeDateForInterval($start, 'yearly', $fromToday);

        for ($i = 0; $i < $count; $i++) {
            $current = clone $startDate;
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

    protected function generateDaily(int $count, int|string|DateTimeInterface|null $start, bool $fromToday = false): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $startDate = $this->normalizeDateForInterval($start, 'daily', $fromToday);

        for ($i = 0; $i < $count; $i++) {
            $current = clone $startDate;
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

    protected function generateWeekly(int $count, int|string|DateTimeInterface|null $start, bool $fromToday = false): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $startDate = $this->normalizeDateForInterval($start, 'weekly', $fromToday);

        for ($i = 0; $i < $count; $i++) {
            $current = clone $startDate;
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

    protected function generateQuarterly(int $count, int|string|DateTimeInterface|null $start, bool $fromToday = false): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $startDate = $this->normalizeDateForInterval($start, 'quarterly', $fromToday);

        for ($i = 0; $i < $count; $i++) {
            $current = clone $startDate;
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