<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Builders;

use DateInterval;
use DateTime;
use Uzbek\LaravelPartitionManager\ValueObjects\RangePartition;

class DateRangeBuilder
{
    protected DateTime $startDate;

    protected ?DateTime $endDate = null;

    protected int $count = 12;

    protected string $interval = 'monthly';

    protected string $nameFormat = 'Y_m';

    protected ?string $defaultSchema = null;

    public function __construct()
    {
        $this->startDate = new DateTime('now');
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

    public function defaultSchema(string $schema): self
    {
        $this->defaultSchema = $schema;

        return $this;
    }

    protected function updateNameFormat(): void
    {
        $this->nameFormat = match ($this->interval) {
            'daily' => 'Y_m_d',
            'weekly' => 'Y_W',
            'monthly' => 'Y_m',
            'quarterly' => 'Y_\\QQ',
            'yearly' => 'Y',
            default => 'Y_m',
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
        $currentDate = clone $this->startDate;
        $interval = $this->getDateInterval();

        if ($this->endDate !== null) {
            while ($currentDate < $this->endDate) {
                $nextDate = clone $currentDate;
                $nextDate->add($interval);

                $partitions[] = $this->createPartition($prefix, $currentDate, $nextDate);
                $currentDate = $nextDate;
            }
        } else {
            for ($i = 0; $i < $this->count; $i++) {
                $nextDate = clone $currentDate;
                $nextDate->add($interval);

                $partitions[] = $this->createPartition($prefix, $currentDate, $nextDate);
                $currentDate = $nextDate;
            }
        }

        return $partitions;
    }

    protected function createPartition(string $prefix, DateTime $from, DateTime $to): RangePartition
    {
        $name = $prefix . $from->format($this->nameFormat);

        $partition = RangePartition::range($name)
            ->withRange($from->format('Y-m-d'), $to->format('Y-m-d'));

        if ($this->defaultSchema !== null) {
            $partition->withSchema($this->defaultSchema);
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