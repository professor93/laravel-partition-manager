<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Uzbek\LaravelPartitionManager\Services\PartitionRotation;

/**
 * Fluent builder for scheduling partition maintenance tasks.
 *
 * Usage in Kernel.php:
 *
 *     // Simple usage - column and interval auto-detected from existing table:
 *     $schedule->partition('orders')
 *         ->ensureFuture(4)
 *         ->daily();
 *
 *     // With explicit column (for initial setup or custom scenarios):
 *     $schedule->partition('orders', 'created_at')
 *         ->ensureFuture(3, 'monthly')
 *         ->daily()
 *         ->at('02:00');
 *
 *     $schedule->partition('logs')
 *         ->rotate(keep: 12)
 *         ->monthly();
 */
class PartitionScheduleBuilder
{
    protected Schedule $schedule;
    protected string $table;
    protected ?string $column;
    protected ?string $schema = null;

    protected ?int $ensureFutureCount = null;
    protected ?string $ensureFutureInterval = null;
    protected ?int $rotateKeep = null;
    protected bool $rotateDropSchemas = false;

    protected ?Event $event = null;

    public function __construct(Schedule $schedule, string $table, ?string $column = null)
    {
        $this->schedule = $schedule;
        $this->table = $table;
        $this->column = $column;
    }

    /**
     * Set the schema for new partitions.
     */
    public function schema(string $schema): self
    {
        $this->schema = $schema;
        return $this;
    }

    /**
     * Ensure a number of future partitions exist from current date.
     *
     * When no interval is specified, it will be auto-detected from existing partitions.
     * When count is 4 and interval is 'monthly', this ensures partitions exist for
     * the next 4 months from today.
     *
     * @param int $count Number of future partitions to ensure from current date
     * @param string|null $interval Interval (daily, weekly, monthly, yearly). If null, auto-detected.
     */
    public function ensureFuture(int $count, ?string $interval = null): self
    {
        $this->ensureFutureCount = $count;
        $this->ensureFutureInterval = $interval;
        return $this;
    }

    /**
     * Rotate old partitions, keeping only the most recent.
     *
     * @param int $keep Number of partitions to keep
     * @param bool $dropSchemas Also drop empty schemas after dropping partitions
     */
    public function rotate(int $keep, bool $dropSchemas = false): self
    {
        $this->rotateKeep = $keep;
        $this->rotateDropSchemas = $dropSchemas;
        return $this;
    }

    /**
     * Run daily at a given time (e.g., "02:00").
     */
    public function daily(): self
    {
        $this->buildEvent()->daily();
        return $this;
    }

    /**
     * Run daily at a given time.
     */
    public function dailyAt(string $time): self
    {
        $this->buildEvent()->dailyAt($time);
        return $this;
    }

    /**
     * Run weekly on a given day and time.
     */
    public function weekly(): self
    {
        $this->buildEvent()->weekly();
        return $this;
    }

    /**
     * Run weekly on Sunday.
     */
    public function weeklyOn(int $dayOfWeek, string $time = '0:0'): self
    {
        $this->buildEvent()->weeklyOn($dayOfWeek, $time);
        return $this;
    }

    /**
     * Run monthly.
     */
    public function monthly(): self
    {
        $this->buildEvent()->monthly();
        return $this;
    }

    /**
     * Run monthly on a given day and time.
     */
    public function monthlyOn(int $dayOfMonth = 1, string $time = '0:0'): self
    {
        $this->buildEvent()->monthlyOn($dayOfMonth, $time);
        return $this;
    }

    /**
     * Set the time for the scheduled event.
     */
    public function at(string $time): self
    {
        $this->buildEvent()->at($time);
        return $this;
    }

    /**
     * Run every minute (mainly for testing).
     */
    public function everyMinute(): self
    {
        $this->buildEvent()->everyMinute();
        return $this;
    }

    /**
     * Run hourly.
     */
    public function hourly(): self
    {
        $this->buildEvent()->hourly();
        return $this;
    }

    /**
     * Set a custom cron expression.
     */
    public function cron(string $expression): self
    {
        $this->buildEvent()->cron($expression);
        return $this;
    }

    /**
     * Set a custom timezone.
     */
    public function timezone(string $timezone): self
    {
        $this->buildEvent()->timezone($timezone);
        return $this;
    }

    /**
     * Do not allow overlapping.
     */
    public function withoutOverlapping(int $expiresAt = 1440): self
    {
        $this->buildEvent()->withoutOverlapping($expiresAt);
        return $this;
    }

    /**
     * Run even in maintenance mode.
     */
    public function evenInMaintenanceMode(): self
    {
        $this->buildEvent()->evenInMaintenanceMode();
        return $this;
    }

    /**
     * Run on one server only (for load balanced environments).
     */
    public function onOneServer(): self
    {
        $this->buildEvent()->onOneServer();
        return $this;
    }

    /**
     * Run in the background.
     */
    public function runInBackground(): self
    {
        $this->buildEvent()->runInBackground();
        return $this;
    }

    /**
     * Add a callback to run on success.
     */
    public function onSuccess(callable $callback): self
    {
        $this->buildEvent()->onSuccess($callback);
        return $this;
    }

    /**
     * Add a callback to run on failure.
     */
    public function onFailure(callable $callback): self
    {
        $this->buildEvent()->onFailure($callback);
        return $this;
    }

    /**
     * Set the event name/description.
     */
    public function name(string $name): self
    {
        $this->buildEvent()->name($name);
        return $this;
    }

    /**
     * Get the underlying scheduled event.
     */
    public function getEvent(): Event
    {
        return $this->buildEvent();
    }

    /**
     * Build and return the scheduled event.
     */
    protected function buildEvent(): Event
    {
        if ($this->event !== null) {
            return $this->event;
        }

        $table = $this->table;
        $column = $this->column;
        $schema = $this->schema;
        $ensureFutureCount = $this->ensureFutureCount;
        $ensureFutureInterval = $this->ensureFutureInterval;
        $rotateKeep = $this->rotateKeep;
        $rotateDropSchemas = $this->rotateDropSchemas;

        $this->event = $this->schedule->call(function () use (
            $table,
            $column,
            $schema,
            $ensureFutureCount,
            $ensureFutureInterval,
            $rotateKeep,
            $rotateDropSchemas
        ) {
            $result = new PartitionScheduleResult();

            if ($ensureFutureCount !== null) {
                $result->partitionsCreated = PartitionRotation::ensureFuture(
                    table: $table,
                    count: $ensureFutureCount,
                    column: $column,
                    interval: $ensureFutureInterval,
                    schema: $schema
                );
            }

            if ($rotateKeep !== null) {
                $result->partitionsDropped = PartitionRotation::rotate(
                    $table,
                    $rotateKeep,
                    $rotateDropSchemas
                );
            }

            return $result;
        })->name("partition-maintenance:{$table}");

        return $this->event;
    }
}
