<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Traits;

use DateTime;
use DateTimeInterface;

/**
 * Trait for normalizing date inputs across all partition builders.
 *
 * Supports multiple input formats:
 * - Integer year: 2026 → 2026-01-01
 * - Year-month string: "2026-01" → 2026-01-01
 * - Full date string: "2026-01-15" → 2026-01-15
 * - DateTime/DateTimeInterface objects
 * - null with fromToday=true → current date
 * - null with fromToday=false → current date (default behavior)
 */
trait DateNormalizer
{
    /**
     * Normalize a date input to a DateTime object.
     *
     * @param int|string|DateTimeInterface|null $date The date input in various formats
     * @param bool $fromToday If true and date is null, use current date; if false, use current date anyway (for backwards compatibility)
     * @return DateTime
     */
    protected function normalizeDate(int|string|DateTimeInterface|null $date, bool $fromToday = false): DateTime
    {
        // If null, use current date
        if ($date === null) {
            return new DateTime();
        }

        // If already a DateTime, clone it
        if ($date instanceof DateTimeInterface) {
            return DateTime::createFromInterface($date);
        }

        // If integer, treat as year
        if (is_int($date)) {
            return new DateTime("{$date}-01-01");
        }

        // If string, parse various formats
        return $this->parseDateString($date);
    }

    /**
     * Parse a date string in various formats.
     *
     * Supports:
     * - "2026" → 2026-01-01
     * - "2026-01" or "2026/01" → 2026-01-01
     * - "2026-01-15" or "2026/01/15" → 2026-01-15
     * - Any other format parseable by DateTime
     *
     * @param string $date The date string to parse
     * @return DateTime
     */
    protected function parseDateString(string $date): DateTime
    {
        $date = trim($date);

        // Year only: "2026"
        if (preg_match('/^\d{4}$/', $date)) {
            return new DateTime("{$date}-01-01");
        }

        // Year-month: "2026-01" or "2026/01"
        if (preg_match('/^(\d{4})[-\/](\d{1,2})$/', $date, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            return new DateTime("{$year}-{$month}-01");
        }

        // Full date or other format - let DateTime parse it
        return new DateTime($date);
    }

    /**
     * Normalize a date and align it to a specific interval boundary.
     *
     * @param int|string|DateTimeInterface|null $date The date input
     * @param string $interval The interval type: daily, weekly, monthly, yearly, quarterly
     * @param bool $fromToday If true and date is null, start from today
     * @return DateTime Aligned DateTime
     */
    protected function normalizeDateForInterval(
        int|string|DateTimeInterface|null $date,
        string $interval,
        bool $fromToday = false
    ): DateTime {
        $normalized = $this->normalizeDate($date, $fromToday);
        $normalized->setTime(0, 0, 0);

        return match ($interval) {
            'daily' => $normalized,
            'weekly' => $normalized->modify('monday this week'),
            'monthly' => $normalized->modify('first day of this month'),
            'yearly' => $normalized, // Keep as-is for flexible yearly starts
            'quarterly' => $this->alignToQuarterStart($normalized),
            default => $normalized,
        };
    }

    /**
     * Align a date to the first day of its quarter.
     *
     * @param DateTime $date The date to align
     * @return DateTime
     */
    protected function alignToQuarterStart(DateTime $date): DateTime
    {
        $month = (int) $date->format('n');
        $quarterStart = match (true) {
            $month <= 3 => 1,
            $month <= 6 => 4,
            $month <= 9 => 7,
            default => 10,
        };

        $date->setDate((int) $date->format('Y'), $quarterStart, 1);
        return $date;
    }
}
