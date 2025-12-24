<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Uzbek\LaravelPartitionManager\Commands\PartitionAnalyzeCommand;
use Uzbek\LaravelPartitionManager\Commands\PartitionDropOldCommand;
use Uzbek\LaravelPartitionManager\Commands\PartitionEnsureFutureCommand;
use Uzbek\LaravelPartitionManager\Commands\PartitionHealthCommand;
use Uzbek\LaravelPartitionManager\Commands\PartitionListCommand;
use Uzbek\LaravelPartitionManager\Commands\PartitionReindexCommand;
use Uzbek\LaravelPartitionManager\Commands\PartitionTreeCommand;
use Uzbek\LaravelPartitionManager\Commands\PartitionVacuumCommand;
use Uzbek\LaravelPartitionManager\Scheduling\PartitionScheduleBuilder;
use Uzbek\LaravelPartitionManager\Services\PartitionManager;
use Uzbek\LaravelPartitionManager\Services\SchemaCreator;

class PartitionManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/partition-manager.php',
            'partition-manager'
        );

        $this->app->singleton('partition-manager', static function (Application $app): PartitionManager {
            return new PartitionManager($app->make('db'));
        });

        $this->app->alias('partition-manager', PartitionManager::class);
    }

    public function boot(): void
    {
        $this->registerBlueprintMacros();
        $this->registerOctaneListeners();
        $this->registerSchedulerMacro();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PartitionListCommand::class,
                PartitionHealthCommand::class,
                PartitionEnsureFutureCommand::class,
                PartitionDropOldCommand::class,
                PartitionVacuumCommand::class,
                PartitionReindexCommand::class,
                PartitionAnalyzeCommand::class,
                PartitionTreeCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/partition-manager.php' => config_path('partition-manager.php'),
            ], 'partition-manager-config');
        }
    }

    /**
     * Register the partition() macro on Laravel's Schedule class.
     *
     * The column parameter is optional - if not provided, it will be auto-detected
     * from the existing partitioned table structure when ensureFuture() is called.
     */
    protected function registerSchedulerMacro(): void
    {
        if (!class_exists(Schedule::class)) {
            return;
        }

        Schedule::macro('partition', function (string $table, ?string $column = null): PartitionScheduleBuilder {
            /** @var Schedule $this */
            return new PartitionScheduleBuilder($this, $table, $column);
        });
    }

    /**
     * Register listeners for Laravel Octane and other long-running processes.
     *
     * This ensures the schema cache is cleared between requests to prevent
     * stale data issues.
     */
    protected function registerOctaneListeners(): void
    {
        // Listen for Octane request termination
        if (class_exists('Laravel\Octane\Events\RequestTerminated')) {
            Event::listen('Laravel\Octane\Events\RequestTerminated', static function (): void {
                SchemaCreator::flush();
            });
        }

        // Listen for Octane task termination
        if (class_exists('Laravel\Octane\Events\TaskTerminated')) {
            Event::listen('Laravel\Octane\Events\TaskTerminated', static function (): void {
                SchemaCreator::flush();
            });
        }

        // Listen for Octane tick termination
        if (class_exists('Laravel\Octane\Events\TickTerminated')) {
            Event::listen('Laravel\Octane\Events\TickTerminated', static function (): void {
                SchemaCreator::flush();
            });
        }
    }

    protected function registerBlueprintMacros(): void
    {
        Blueprint::macro('pgEnum', function (string $column, array $values, ?string $type = null): ColumnDefinition {
            /** @var Blueprint $this */
            $type = $type ?? Str::singular($this->getTable()) . '_' . $column . '_enum';
            $quotedType = '"' . str_replace('"', '""', $type) . '"';
            $quotedValues = implode(', ', array_map(
                fn (string $v): string => "'" . str_replace("'", "''", $v) . "'",
                $values
            ));

            DB::statement("DO $$ BEGIN CREATE TYPE {$quotedType} AS ENUM ({$quotedValues}); EXCEPTION WHEN duplicate_object THEN null; END $$");

            // Register the custom type with the grammar so it can compile columns of this type
            $grammar = DB::connection()->getSchemaGrammar();
            $methodName = 'type' . ucfirst($type);
            if ($grammar !== null && !method_exists($grammar, $methodName)) {
                $grammar::macro($methodName, fn (): string => $quotedType);
            }

            return $this->addColumn($type, $column);
        });

        /**
         * Create a conditional (partial) index with optional column ordering.
         *
         * Supports PostgreSQL partial indexes with WHERE clauses and column ordering.
         * Follows Laravel's convention: columns first, name last (optional).
         *
         * Examples:
         *   // Raw WHERE string
         *   $table->conditionalIndex(['code'], "status = 'void'");
         *   $table->conditionalIndex(['code'], "status = 'void'", 'idx_pc_void');
         *
         *   // Laravel-style closure for WHERE
         *   $table->conditionalIndex(['source_type', 'source_id'], function ($query) {
         *       $query->whereIn('status', ['new', 'dispensed'])
         *             ->whereNotNull('source_type');
         *   });
         *
         *   // With column ordering
         *   $table->conditionalIndex(['date', 'points' => 'DESC']);
         *   $table->conditionalIndex(['col1' => 'ASC', 'col2' => 'DESC'], fn($q) => $q->where('active', true));
         *
         *   // Custom index method
         *   $table->conditionalIndex(['data'], method: 'gin');
         *
         * @param array<int|string, string> $columns Columns with optional ordering.
         *        - String value: column name (e.g., 'code' or 'points DESC')
         *        - Key-value pair: column => 'ASC' or column => 'DESC'
         * @param string|\Closure|null $where WHERE clause: raw string or Laravel-style closure
         * @param string|null $name Index name (auto-generated if null: {table}_{columns}_idx)
         * @param string $method Index method (btree, hash, gist, gin, brin). Defaults to btree.
         */
        Blueprint::macro('conditionalIndex', function (
            array $columns,
            string|\Closure|null $where = null,
            ?string $name = null,
            string $method = 'btree'
        ): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $quotedTable = '"' . str_replace('"', '""', $table) . '"';

            // Build column list with ordering and collect column names for auto-naming
            $columnParts = [];
            $columnNames = [];
            foreach ($columns as $key => $value) {
                if (is_int($key)) {
                    // Numeric key: value is column name, check if it contains ordering
                    if (preg_match('/^(\S+)\s+(ASC|DESC)$/i', $value, $matches)) {
                        $colName = $matches[1];
                        $col = '"' . str_replace('"', '""', $colName) . '"';
                        $order = strtoupper($matches[2]);
                        $columnParts[] = "{$col} {$order}";
                        $columnNames[] = $colName;
                    } else {
                        $col = '"' . str_replace('"', '""', $value) . '"';
                        $columnParts[] = $col;
                        $columnNames[] = $value;
                    }
                } else {
                    // String key: key is column name, value is ordering
                    $col = '"' . str_replace('"', '""', $key) . '"';
                    $order = strtoupper($value);
                    if (!in_array($order, ['ASC', 'DESC'], true)) {
                        throw new \InvalidArgumentException("Invalid order '{$value}' for column '{$key}'. Use 'ASC' or 'DESC'.");
                    }
                    $columnParts[] = "{$col} {$order}";
                    $columnNames[] = $key;
                }
            }

            // Auto-generate index name if not provided
            if ($name === null) {
                $name = $table . '_' . implode('_', $columnNames) . '_idx';
            }

            $quotedName = '"' . str_replace('"', '""', $name) . '"';
            $columnList = implode(', ', $columnParts);
            $sql = "CREATE INDEX {$quotedName} ON {$quotedTable} USING {$method} ({$columnList})";

            // Build WHERE clause
            if ($where !== null) {
                if ($where instanceof \Closure) {
                    // Use Laravel's query builder to generate the WHERE clause
                    $query = DB::table($table);
                    $where($query);

                    // Extract the WHERE clause from the query
                    $grammar = $query->getGrammar();
                    $whereClause = $grammar->compileWheres($query);

                    if (!empty($whereClause)) {
                        // Remove the leading 'where ' that compileWheres adds
                        $whereClause = preg_replace('/^where\s+/i', '', $whereClause);
                        $sql .= " WHERE {$whereClause}";
                    }
                } else {
                    $sql .= " WHERE {$where}";
                }
            }

            DB::statement($sql);
        });
    }

    /** @return array<int, string> */
    public function provides(): array
    {
        return ['partition-manager', PartitionManager::class];
    }
}
