<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use DateTime;
use Uzbek\LaravelPartitionManager\Enums\PartitionType;
use Uzbek\LaravelPartitionManager\Exceptions\PartitionException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class QuickPartitionBuilder
{
    protected PartitionType $partitionType = PartitionType::RANGE;

    protected string $partitionColumn = '';

    protected ?string $connectionName = null;

    protected ?string $schema = null;

    public function __construct(
        protected readonly string $table,
    ) {}

    public static function table(string $table): self
    {
        return new self($table);
    }

    public function by(string $column): self
    {
        $this->partitionColumn = $column;

        return $this;
    }

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

    public function yearly(int $count = 5, ?int $startYear = null): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateYearly($count, $startYear);
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

    public function quarterly(int $count = 8, ?int $startYear = null): void
    {
        $this->partitionType = PartitionType::RANGE;
        $this->generateQuarterly($count, $startYear);
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

            $partitionName = $this->table . '_' . $current->format('Y_m');
            $this->createRangePartition(
                $connection,
                $partitionName,
                $current->format('Y-m-d'),
                $next->format('Y-m-d')
            );
        }
    }

    protected function generateYearly(int $count, ?int $startYear): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $year = $startYear ?? (int) date('Y');

        for ($i = 0; $i < $count; $i++) {
            $currentYear = $year + $i;
            $partitionName = $this->table . '_' . $currentYear;
            $this->createRangePartition(
                $connection,
                $partitionName,
                "{$currentYear}-01-01",
                ($currentYear + 1) . "-01-01"
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

            $partitionName = $this->table . '_' . $current->format('Y_m_d');
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

            $partitionName = $this->table . '_' . $current->format('Y_W');
            $this->createRangePartition(
                $connection,
                $partitionName,
                $current->format('Y-m-d'),
                $next->format('Y-m-d')
            );
        }
    }

    protected function generateQuarterly(int $count, ?int $startYear): void
    {
        $this->ensurePartitionColumnSet();

        $connection = $this->getConnection();
        $year = $startYear ?? (int) date('Y');
        $quarter = 1;

        for ($i = 0; $i < $count; $i++) {
            $fromMonth = ($quarter - 1) * 3 + 1;
            $toMonth = $fromMonth + 3;

            $partitionName = $this->table . '_' . $year . '_q' . $quarter;
            $fromDate = sprintf('%d-%02d-01', $year, $fromMonth);

            $toDate = $toMonth > 12
                ? sprintf('%d-01-01', $year + 1)
                : sprintf('%d-%02d-01', $year, $toMonth);

            $this->createRangePartition($connection, $partitionName, $fromDate, $toDate);

            $quarter++;
            if ($quarter > 4) {
                $quarter = 1;
                $year++;
            }
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
        $fullName = $this->schema !== null ? "{$this->schema}.{$name}" : $name;

        if ($this->schema !== null) {
            $connection->statement("CREATE SCHEMA IF NOT EXISTS {$this->schema}");
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$fullName} PARTITION OF {$this->table} ";
        $sql .= "FOR VALUES FROM ('{$from}') TO ('{$to}')";

        $connection->statement($sql);
    }

    /**
     * @param array<int, mixed> $values
     */
    protected function createListPartition(Connection $connection, string $name, array $values): void
    {
        $fullName = $this->schema !== null ? "{$this->schema}.{$name}" : $name;

        if ($this->schema !== null) {
            $connection->statement("CREATE SCHEMA IF NOT EXISTS {$this->schema}");
        }

        $valueList = array_map(
            static fn (mixed $v): string|int => is_numeric($v) ? $v : "'{$v}'",
            $values
        );

        $sql = "CREATE TABLE IF NOT EXISTS {$fullName} PARTITION OF {$this->table} ";
        $sql .= "FOR VALUES IN (" . implode(', ', $valueList) . ")";

        $connection->statement($sql);
    }

    protected function createHashPartition(Connection $connection, string $name, int $modulus, int $remainder): void
    {
        $fullName = $this->schema !== null ? "{$this->schema}.{$name}" : $name;

        if ($this->schema !== null) {
            $connection->statement("CREATE SCHEMA IF NOT EXISTS {$this->schema}");
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$fullName} PARTITION OF {$this->table} ";
        $sql .= "FOR VALUES WITH (modulus {$modulus}, remainder {$remainder})";

        $connection->statement($sql);
    }

    private function getConnection(): Connection
    {
        return $this->connectionName !== null
            ? DB::connection($this->connectionName)
            : DB::connection();
    }

    private function ensurePartitionColumnSet(): void
    {
        if ($this->partitionColumn === '') {
            throw new PartitionException("Partition column not specified. Use by() method first.");
        }
    }
}