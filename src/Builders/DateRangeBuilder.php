<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use DateInterval;
use DateTime;
use Uzbek\LaravelPartitionManager\ValueObjects\PartitionDefinition;
use Uzbek\LaravelPartitionManager\ValueObjects\RangePartition;

class DateRangeBuilder
{
    protected ?DateTime $startDate = null;

    protected ?DateTime $endDate = null;

    protected int $count = 12;

    protected string $interval = 'monthly';

    protected string $nameFormat = 'Y_m';

    protected string $typePrefix = 'm';

    protected ?string $schema = null;

    /** @var array<int, PartitionDefinition> */
    protected array $existingPartitions = [];

    public function __construct()
    {
        // Don't set default startDate - will be resolved in build()
    }

    public static function monthly(): self
    {
        return (new self())->interval('monthly');
    }

    public static function yearly(): self
    {
        return (new self())->interval('yearly');
    }

    public static function daily(): self
    {
        return (new self())->interval('daily');
    }

    public static function weekly(): self
    {
        return (new self())->interval('weekly');
    }

    public static function quarterly(): self
    {
        return (new self())->interval('quarterly');
    }

    public function from(DateTime|string $date): self
    {
        $this->startDate = $date instanceof DateTime ? $date : new DateTime($date);

        return $this;
    }

    public function to(DateTime|string $date): self
    {
        $this->endDate = $date instanceof DateTime ? $date : new DateTime($date);

        return $this;
    }

    public function count(int $count): self
    {
        $this->count = $count;
        $this->endDate = null;

        return $this;
    }

    public function interval(string $interval): self
    {
        $this->interval = $interval;
        $this->updateNameFormat();

        return $this;
    }

    public function nameFormat(string $format): self
    {
        $this->nameFormat = $format;

        return $this;
    }

    public function schema(string $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * Set existing partitions to continue from.
     *
     * @param array<int, PartitionDefinition> $partitions
     */
    public function continueFrom(array $partitions): self
    {
        $this->existingPartitions = $partitions;

        return $this;
    }

    /**
     * Get the end date from the last existing partition.
     */
    protected function getLastPartitionEndDate(): ?DateTime
    {
        if (empty($this->existingPartitions)) {
            return null;
        }

        $lastPartition = end($this->existingPartitions);
        if ($lastPartition instanceof RangePartition) {
            $to = $lastPartition->getTo();
            return $to ? new DateTime($to) : null;
        }

        return null;
    }

    /**
     * Get the schema from the last existing partition.
     */
    protected function getLastPartitionSchema(): ?string
    {
        if (empty($this->existingPartitions)) {
            return null;
        }

        $lastPartition = end($this->existingPartitions);
        return $lastPartition->getSchema();
    }

    /**
     * Resolve the start date based on explicit setting, existing partitions, or current date.
     */
    protected function resolveStartDate(): DateTime
    {
        // Use explicit start date if set
        if ($this->startDate !== null) {
            return clone $this->startDate;
        }

        // Use last partition's end date if available
        if ($lastEnd = $this->getLastPartitionEndDate()) {
            return clone $lastEnd;
        }

        // Default to current date aligned to interval
        $date = new DateTime('now');
        return $this->alignToInterval($date);
    }

    /**
     * Align a date to the interval boundary.
     */
    protected function alignToInterval(DateTime $date): DateTime
    {
        $aligned = clone $date;

        return match ($this->interval) {
            'daily' => $aligned->setTime(0, 0, 0),
            'weekly' => $aligned->modify('monday this week')->setTime(0, 0, 0),
            'monthly' => $aligned->modify('first day of this month')->setTime(0, 0, 0),
            'quarterly' => $this->alignToQuarter($aligned),
            'yearly' => $aligned->modify('first day of january this year')->setTime(0, 0, 0),
            default => $aligned,
        };
    }

    protected function alignToQuarter(DateTime $date): DateTime
    {
        $month = (int) $date->format('n');
        $quarterMonth = ((int) (($month - 1) / 3) * 3) + 1;
        $date->setDate((int) $date->format('Y'), $quarterMonth, 1)->setTime(0, 0, 0);

        return $date;
    }

    protected function updateNameFormat(): void
    {
        [$this->typePrefix, $this->nameFormat] = match ($this->interval) {
            'daily' => ['d', 'Y_m_d'],
            'weekly' => ['w', 'Y_m_d'],
            'monthly' => ['m', 'Y_m'],
            'quarterly' => ['q', 'Y_m'],
            'yearly' => ['y', 'Y'],
            default => ['m', 'Y_m'],
        };
    }

    protected function getDateInterval(): DateInterval
    {
        return match ($this->interval) {
            'daily' => new DateInterval('P1D'),
            'weekly' => new DateInterval('P1W'),
            'monthly' => new DateInterval('P1M'),
            'quarterly' => new DateInterval('P3M'),
            'yearly' => new DateInterval('P1Y'),
            default => new DateInterval('P1M'),
        };
    }

    /**
     * @return array<int, RangePartition>
     */
    public function build(string $prefix = ''): array
    {
        $partitions = [];
        $currentDate = $this->resolveStartDate();
        $interval = $this->getDateInterval();

        // Use last partition's schema if no explicit schema set
        $effectiveSchema = $this->schema ?? $this->getLastPartitionSchema();

        if ($this->endDate !== null) {
            while ($currentDate < $this->endDate) {
                $nextDate = clone $currentDate;
                $nextDate->add($interval);

                $partitions[] = $this->createPartition($prefix, $currentDate, $nextDate, $effectiveSchema);
                $currentDate = $nextDate;
            }
        } else {
            for ($i = 0; $i < $this->count; $i++) {
                $nextDate = clone $currentDate;
                $nextDate->add($interval);

                $partitions[] = $this->createPartition($prefix, $currentDate, $nextDate, $effectiveSchema);
                $currentDate = $nextDate;
            }
        }

        return $partitions;
    }

    protected function createPartition(string $prefix, DateTime $from, DateTime $to, ?string $schema = null): RangePartition
    {
        $name = $prefix . $this->typePrefix . $from->format($this->nameFormat);

        $partition = RangePartition::range($name)
            ->withRange($from->format('Y-m-d'), $to->format('Y-m-d'));

        if ($schema !== null) {
            $partition->withSchema($schema);
        }

        return $partition;
    }

    /**
     * @return array<int, mixed>
     */
    public function generate(?callable $callback = null): array
    {
        $partitions = $this->build();

        if ($callback !== null) {
            $partitions = array_map($callback, $partitions);
        }

        return $partitions;
    }
}
