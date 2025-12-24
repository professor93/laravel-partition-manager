# Laravel Partition Manager

A powerful Laravel package for managing PostgreSQL partitioned tables. Supports RANGE, LIST, and HASH partitioning strategies with multi-level sub-partitioning, automatic partition generation, and schema management.

## Requirements

- PHP 8.3 or higher
- Laravel 12.0 or higher
- PostgreSQL 14 or higher

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Features](#features)
- [Partition Types](#partition-types)
  - [Range Partitioning](#range-partitioning)
  - [List Partitioning](#list-partitioning)
  - [Hash Partitioning](#hash-partitioning)
- [Automatic Partition Generation](#automatic-partition-generation)
  - [Terminal Methods](#terminal-methods)
  - [Quick Generation for Existing Tables](#quick-generation-for-existing-tables)
  - [DateRangeBuilder](#daterangebuilder)
- [Schema Management](#schema-management)
- [Sub-Partitioning](#sub-partitioning)
- [Partition Management](#partition-management)
- [Artisan Commands](#artisan-commands)
- [Scheduler Integration](#scheduler-integration)
- [Partition Templates](#partition-templates)
- [Model Trait](#model-trait)
- [Advanced Features](#advanced-features)
- [Configuration](#configuration)
- [Service Classes](#service-classes)
- [API Reference](#api-reference)
- [License](#license)

## Installation

Install the package via Composer:

```bash
composer require uzbek/laravel-partition-manager
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=partition-manager-config
```

## Quick Start

```php
use Uzbek\LaravelPartitionManager\Partition;

// Create a partitioned table with 12 monthly partitions
Partition::create('logs', function($table) {
    $table->id();
    $table->string('level');
    $table->text('message');
    $table->timestamp('created_at');
})
->by('created_at')
->monthly();

// Add partitions to an existing table
Partition::for('events')
    ->by('created_at')
    ->daily(30);
```

## Features

- **Three Partitioning Strategies**: RANGE, LIST, and HASH partitioning
- **Automatic Partition Generation**: Monthly, yearly, daily, weekly, quarterly partitions
- **Multi-Level Sub-Partitioning**: Create hierarchical partition structures
- **Schema Management**: Per-partition schema assignment with auto-creation
- **Transaction Safety**: All operations wrapped in transactions with automatic rollback
- **Partition Lifecycle Management**: Attach, detach, drop, analyze, vacuum operations
- **Advanced Options**: Default partitions, check constraints, custom tablespaces, partition pruning
- **Multi-Column Partitioning**: Partition by multiple columns or custom expressions
- **Artisan Commands**: 8 CLI commands for partition management and maintenance
- **Scheduler Integration**: Schedule partition rotation and creation via Laravel's scheduler
- **Partition Templates**: Reusable partition configurations with placeholder support
- **Model Trait**: Partition-aware Eloquent queries with scopes and helper methods
- **Laravel Octane Support**: Proper cache management for long-running processes

## Partition Types

### Range Partitioning

Divide data based on ranges of values, ideal for date-based partitioning.

```php
// Simple syntax with terminal method
Partition::create('orders', function($table) {
    $table->id();
    $table->decimal('amount');
    $table->date('order_date');
})
->by('order_date')
->monthly(24, '2024-01-01');  // 24 partitions from Jan 2024

// Manual partition definition
Partition::create('orders', function($table) {
    $table->id();
    $table->decimal('amount');
    $table->date('order_date');
})
->by('order_date')
->addRangePartition('orders_2024_q1', '2024-01-01', '2024-04-01')
->addRangePartition('orders_2024_q2', '2024-04-01', '2024-07-01')
->generate();  // Required when using addRangePartition()
```

### List Partitioning

Partition data based on discrete values.

```php
Partition::create('users', function($table) {
    $table->id();
    $table->string('country');
    $table->string('email');
})
->by('country')
->addListPartition('users_us', ['US', 'CA'])
->addListPartition('users_eu', ['DE', 'FR', 'IT', 'ES'])
->addListPartition('users_asia', ['JP', 'CN', 'KR'])
->generate();

// Or use addListPartitions for bulk creation
Partition::create('orders', function($table) {
    $table->id();
    $table->string('status');
})
->by('status')
->addListPartitions(['pending', 'processing', 'shipped', 'delivered'])
->generate();
```

### Hash Partitioning

Distribute data evenly across partitions using a hash function.

```php
Partition::create('events', function($table) {
    $table->id();
    $table->string('event_type');
    $table->jsonb('payload');
})
->by('id')
->addHashPartitions(4)
->generate();
```

## Automatic Partition Generation

### Terminal Methods

Terminal methods create the table and partitions immediately (no `generate()` call needed):

```php
Partition::create('logs', function($table) { /* ... */ })
->by('created_at')
->monthly();           // 12 monthly partitions from current month
->monthly(24);         // 24 monthly partitions
->monthly(12, '2024-01-01');  // From specific date

->yearly();            // 5 yearly partitions from current year
->yearly(10, 2020);    // 10 partitions starting from 2020

->daily();             // 30 daily partitions from today
->daily(7, '2024-01-01');

->weekly();            // 12 weekly partitions
->quarterly();         // 8 quarterly partitions
```

### Quick Generation for Existing Tables

```php
// One-liner methods
Partition::monthly('logs', 'created_at', 12);
Partition::yearly('reports', 'year', 5);
Partition::daily('metrics', 'recorded_at', 30);
Partition::weekly('events', 'event_date', 12);
Partition::quarterly('sales', 'sale_date', 8);

// Using the builder for more control
Partition::for('logs')
    ->by('created_at')
    ->schema('log_partitions')
    ->monthly(24);

// List partitions
Partition::for('regions')
    ->byList('country', [
        'us' => ['US', 'CA'],
        'eu' => ['DE', 'FR', 'ES'],
        'asia' => ['JP', 'CN', 'KR']
    ]);

// Hash partitions
Partition::for('users')
    ->byHash('id', 8);
```

### DateRangeBuilder

For advanced date range configurations:

```php
use Uzbek\LaravelPartitionManager\Builders\DateRangeBuilder;

Partition::create('metrics', function($table) {
    $table->id();
    $table->float('value');
    $table->timestamp('recorded_at');
})
->by('recorded_at')
->dateRange(
    DateRangeBuilder::daily()
        ->from('2024-01-01')
        ->count(30)
        ->defaultSchema('daily_metrics')
)
->generate();
```

## Schema Management

### Per-Partition Schemas

```php
// Default schema for all partitions
Partition::create('logs', function($table) { /* ... */ })
->by('logged_at')
->schema('log_partitions')
->monthly();

// Different schemas per partition
Partition::create('logs', function($table) { /* ... */ })
->by('logged_at')
->addRangePartition('logs_archive', '2023-01-01', '2024-01-01', 'archive_schema')
->addRangePartition('logs_current', '2024-01-01', '2025-01-01', 'current_schema')
->generate();
```

### SchemaCreator Service

```php
use Uzbek\LaravelPartitionManager\Services\SchemaCreator;

// Ensure schema exists
SchemaCreator::ensure('my_schema');
SchemaCreator::ensure('my_schema', $connection);

// Ensure schema and return prefixed table name
$fullName = SchemaCreator::ensureAndPrefix('my_table', 'my_schema');
// Returns: 'my_schema.my_table'
```

### PartitionSchemaManager Service

```php
use Uzbek\LaravelPartitionManager\Services\PartitionSchemaManager;

$schemaManager = new PartitionSchemaManager();

$schemaManager->setDefault('default_partitions');
$schemaManager->register('error', 'error_log_schema');
$schemaManager->registerMultiple([
    'active' => 'active_schema',
    'archived' => 'archive_schema'
]);

$schema = $schemaManager->getSchemaFor('error');
$hasSchema = $schemaManager->hasSchemaFor('info');
```

## Sub-Partitioning

Create hierarchical partition structures with support for unlimited nesting levels.

### Inline Sub-Partitions (Recommended)

The cleanest way to define sub-partitions - no need to repeat partition names:

```php
use Uzbek\LaravelPartitionManager\Builders\SubPartitionBuilder;

Partition::create('events', function($table) {
    $table->id();
    $table->string('type');
    $table->integer('user_id');
    $table->timestamp('created_at');
})
->by('created_at')
->addRangePartition('events_2024_01', '2024-01-01', '2024-02-01',
    subPartitions: SubPartitionBuilder::list('type')
        ->addListPartition('events_2024_01_user', ['login', 'logout'])
        ->addListPartition('events_2024_01_system', ['error', 'warning'])
)
->generate();
```

### Separate Declaration

You can also declare sub-partitions separately using `withSubPartitions()`:

```php
Partition::create('events', function($table) { /* ... */ })
->by('created_at')
->addRangePartition('events_2024_01', '2024-01-01', '2024-02-01')
->withSubPartitions('events_2024_01',
    SubPartitionBuilder::list('type')
        ->addListPartition('events_2024_01_user', ['login', 'logout'])
        ->addListPartition('events_2024_01_system', ['error', 'warning'])
)
->generate();
```

### Apply Same Sub-Partition to All Partitions

Use `addSubPartitionToAll()` to apply the same sub-partition configuration to all existing partitions:

```php
Partition::create('logs', function($table) {
    $table->id();
    $table->integer('user_id');
    $table->timestamp('created_at');
})
->by('created_at')
->addMonthlyPartitions(12, '2024-01-01')
->addSubPartitionToAll(
    SubPartitionBuilder::hash('user_id')
        ->addHashPartitions(4, '%_shard_')  // % = partition name
        // Creates: logs_m2024_01_shard_0, logs_m2024_01_shard_1, etc.
)
->generate();
```

The `%` placeholder in prefixes is replaced with the parent partition name, making it easy to create hierarchical naming.

### Multi-Level Nesting

Sub-partitions can have their own sub-partitions, enabling complex hierarchical structures:

```php
// Example: LIST → HASH → RANGE (3 levels deep)
Partition::create('orders', function($table) {
    $table->id();
    $table->string('status');
    $table->integer('user_id');
    $table->timestamp('created_at');
})
->list()
->by('status')
->addListPartition('orders_active', ['pending', 'processing'],
    subPartitions: SubPartitionBuilder::hash('user_id')
        ->addHashPartition('active_shard_0', 4, 0,
            subPartitions: SubPartitionBuilder::range('created_at')
                ->addMonthlyPartitions('m_', 12)
        )
        ->addHashPartition('active_shard_1', 4, 1,
            subPartitions: SubPartitionBuilder::range('created_at')
                ->addMonthlyPartitions('m_', 12)
        )
        ->addHashPartition('active_shard_2', 4, 2)
        ->addHashPartition('active_shard_3', 4, 3)
)
->addListPartition('orders_completed', ['shipped', 'delivered'])
->generate();
```

### Using Value Objects

```php
use Uzbek\LaravelPartitionManager\ValueObjects\RangePartition;
use Uzbek\LaravelPartitionManager\ValueObjects\ListSubPartition;

$partition = RangePartition::range('data_2024_01')
    ->withRange('2024-01-01', '2024-02-01')
    ->withSchema('monthly_data')
    ->withSubPartitions(
        SubPartitionBuilder::list('status')
            ->add(ListSubPartition::create('data_active')
                ->withValues(['active', 'pending'])
                ->withSchema('active_data'))
    );

$builder->addPartition($partition);
```

### Add Sub-Partitions to Existing Partitions

Convert an existing non-partitioned partition into a partitioned one:

```php
use Uzbek\LaravelPartitionManager\Services\PartitionMaintenance;

// Add sub-partitions to an existing partition (auto-detects partition expression)
PartitionMaintenance::addSubPartitions(
    'orders',                    // parent table
    'orders_active',             // existing partition to sub-partition
    SubPartitionBuilder::hash('user_id')->addHashPartitions('shard_', 8)
);

// Or manually specify the partition expression
PartitionMaintenance::addSubPartitions(
    'orders',
    'orders_active',
    SubPartitionBuilder::hash('user_id')->addHashPartitions('shard_', 8),
    "FOR VALUES IN ('active', 'pending')"  // optional, auto-detected if omitted
);
```

## Partition Management

### Runtime Operations

```php
use Uzbek\LaravelPartitionManager\Services\PartitionManager;

$manager = app(PartitionManager::class);

// List all partitions
$partitions = $manager->getPartitions('logs');

// Get partition details
$info = $manager->getPartitionInfo('logs', 'logs_2024_01');

// Get partition strategy
$strategy = $manager->getPartitionStrategy('logs');
// Returns: PartitionType::RANGE, LIST, or HASH

// Check if table is partitioned
if ($manager->isPartitioned('logs')) { /* ... */ }

// Get statistics
$size = $manager->getTableSize('logs');
$count = $manager->getPartitionCount('logs');
$oldest = $manager->getOldestPartition('logs');
$newest = $manager->getNewestPartition('logs');

// Drop old partitions
$dropped = $manager->dropOldPartitions('logs', new DateTime('-6 months'));

// Maintenance
$manager->analyzePartition('logs_2024_01');
$manager->vacuumPartition('logs_2024_01', full: true);
```

### Table Operations

```php
use Uzbek\LaravelPartitionManager\Builders\PostgresPartitionBuilder;

$builder = new PostgresPartitionBuilder('orders');

// Attach existing table as partition
$builder->attachPartition('old_orders', 'orders_2023', '2023-01-01', '2024-01-01');

// Detach partition (optionally with CONCURRENTLY)
$builder->detachPartition('orders_2023', concurrently: true);

// Drop partition
$builder->dropPartition('orders_2023');

// Maintenance
$builder->analyze();
$builder->vacuum();
$builder->vacuum(full: true);
```

### Using Facades

```php
use Uzbek\LaravelPartitionManager\Facades\PartitionManager;

$partitions = PartitionManager::getPartitions('logs');
$isPartitioned = PartitionManager::isPartitioned('logs');
$strategy = PartitionManager::getPartitionStrategy('logs');
```

## Artisan Commands

The package provides 8 Artisan commands for managing partitions from the command line:

### partition:list

List all partitions for a table with size and row count:

```bash
php artisan partition:list orders

# Output:
# +------------------+------------+----------+
# | Partition        | Rows       | Size     |
# +------------------+------------+----------+
# | orders_m2024_01  | 15,234     | 2.1 MB   |
# | orders_m2024_02  | 18,456     | 2.5 MB   |
# | orders_m2024_03  | 12,890     | 1.8 MB   |
# +------------------+------------+----------+
```

### partition:tree

Display partition hierarchy as a tree:

```bash
php artisan partition:tree orders --depth=2

# Output:
# orders (partitioned by RANGE)
# ├── orders_2024_01 [2024-01-01 → 2024-02-01]
# ├── orders_2024_02 [2024-02-01 → 2024-03-01]
# └── orders_2024_03 [2024-03-01 → 2024-04-01]
```

### partition:health

Run a health check on partitions:

```bash
php artisan partition:health orders

# Checks for:
# - Gaps between partitions
# - Overlapping ranges
# - Missing indexes
# - Orphan data in default partition
```

### partition:ensure-future

Create future partitions proactively:

```bash
# Ensure 3 monthly partitions exist
php artisan partition:ensure-future orders created_at --count=3 --interval=monthly

# With custom schema
php artisan partition:ensure-future orders created_at --count=6 --interval=monthly --schema=archive
```

### partition:drop-old

Drop partitions older than a threshold:

```bash
# Drop partitions older than 6 months
php artisan partition:drop-old logs --keep=6

# Preview without dropping
php artisan partition:drop-old logs --keep=6 --dry-run
```

### partition:vacuum

Run VACUUM on partitions:

```bash
# Vacuum specific partition
php artisan partition:vacuum orders_2024_01

# Vacuum all partitions of a table
php artisan partition:vacuum orders --all

# Full vacuum (reclaims more space, locks table)
php artisan partition:vacuum orders_2024_01 --full

# Vacuum with analyze
php artisan partition:vacuum orders_2024_01 --analyze
```

### partition:reindex

Rebuild indexes on partitions:

```bash
# Reindex specific partition
php artisan partition:reindex orders_2024_01

# Reindex concurrently (non-blocking)
php artisan partition:reindex orders_2024_01 --concurrently

# Reindex all partitions
php artisan partition:reindex orders --all
```

### partition:analyze

Update statistics for query planner:

```bash
# Analyze specific partition
php artisan partition:analyze orders_2024_01

# Analyze all partitions
php artisan partition:analyze orders --all
```

## Scheduler Integration

Schedule partition maintenance tasks using Laravel's scheduler with a fluent API:

### Basic Usage

In your `app/Console/Kernel.php` or `routes/console.php`:

```php
use Illuminate\Console\Scheduling\Schedule;

$schedule->partition('orders', 'created_at')
    ->ensureFuture(3, 'monthly')
    ->daily();

$schedule->partition('logs', 'created_at')
    ->rotate(keep: 12)
    ->monthly();
```

### Combined Operations

Run both creation and rotation in a single scheduled task:

```php
$schedule->partition('events', 'created_at')
    ->ensureFuture(3, 'monthly')
    ->rotate(keep: 24)
    ->schema('event_partitions')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();
```

### Available Methods

```php
$schedule->partition(string $table, string $column)
    // Partition operations
    ->ensureFuture(int $count, string $interval = 'monthly')  // Create future partitions
    ->rotate(int $keep, bool $dropSchemas = false)            // Drop old partitions
    ->schema(string $schema)                                   // Schema for new partitions

    // Scheduling options (all Laravel scheduler methods available)
    ->daily()
    ->dailyAt('02:00')
    ->weekly()
    ->weeklyOn(1, '03:00')  // Monday at 3 AM
    ->monthly()
    ->monthlyOn(1, '04:00')
    ->cron('0 2 * * *')
    ->timezone('America/New_York')

    // Constraints
    ->withoutOverlapping()
    ->onOneServer()
    ->evenInMaintenanceMode()
    ->runInBackground()

    // Callbacks
    ->onSuccess(fn($result) => Log::info($result->summary()))
    ->onFailure(fn($e) => Log::error($e->getMessage()))
    ->name('partition-maintenance:orders');
```

### Result Object

The scheduled task returns a `PartitionScheduleResult` object:

```php
$schedule->partition('orders', 'created_at')
    ->ensureFuture(3, 'monthly')
    ->rotate(keep: 12)
    ->daily()
    ->onSuccess(function (PartitionScheduleResult $result) {
        if ($result->hasChanges()) {
            Log::info($result->summary());
            // Output: "Partition maintenance: created 2 partition(s), dropped 1 partition(s)"
        }

        echo $result->partitionsCreated;      // Number created
        echo count($result->partitionsDropped); // Names of dropped partitions
    });
```

## Partition Templates

Templates provide reusable partition configurations that can be applied to multiple tables. The `%` placeholder is replaced with the table name.

### Configuration

Define templates in `config/partition-manager.php`:

```php
'templates' => [
    'monthly_archive' => [
        'type' => 'range',
        'column' => 'created_at',
        'interval' => 'monthly',
        'count' => 12,
        'schema' => '%_archive',        // orders → orders_archive
        'default_partition' => true,
        'future_partitions' => 3,
    ],

    'tenant_hash' => [
        'type' => 'hash',
        'column' => 'tenant_id',
        'modulus' => 16,
        'schema' => 'tenants',
    ],

    'status_list' => [
        'type' => 'list',
        'column' => 'status',
        'values' => ['pending', 'active', 'completed', 'cancelled'],
        'default_partition' => true,
    ],
],
```

### Using Templates in Migrations

```php
use Uzbek\LaravelPartitionManager\Partition;

// Apply template from config
Partition::create('orders', function($table) {
    $table->id();
    $table->decimal('amount');
    $table->timestamp('created_at');
})
->fromTemplate('monthly_archive')
->generate();

// Override template settings
Partition::create('events', function($table) {
    $table->id();
    $table->timestamp('created_at');
})
->fromTemplate('monthly_archive', [
    'count' => 24,
    'schema' => 'event_archive',
])
->generate();
```

### Programmatic Templates

Create templates programmatically:

```php
use Uzbek\LaravelPartitionManager\Templates\PartitionTemplate;

$template = PartitionTemplate::define('custom')
    ->range('created_at')
    ->monthly(12)
    ->withSchema('%_partitions')
    ->withDefaultPartition()
    ->withFuturePartitions(3);

Partition::create('logs', fn($t) => $t->id()->timestamps())
    ->fromTemplate($template)
    ->generate();
```

### Template Methods

```php
PartitionTemplate::define(string $name)
    // Partition type
    ->range(string|array $columns)
    ->list(string $column)
    ->hash(string $column, int $modulus)

    // Intervals (for RANGE)
    ->daily(int $count = 30)
    ->weekly(int $count = 12)
    ->monthly(int $count = 12)
    ->yearly(int $count = 5)

    // LIST values
    ->withValues(array $values)

    // Options
    ->withSchema(string $schema)           // Supports % placeholder
    ->withTablespace(string $tablespace)
    ->withPrefix(string $prefix)           // Supports % placeholder
    ->withDefaultPartition(bool $enabled = true)
    ->withFuturePartitions(int $count);
```

## Model Trait

The `HasPartitions` trait adds partition-aware methods to your Eloquent models:

### Basic Setup

```php
use Illuminate\Database\Eloquent\Model;
use Uzbek\LaravelPartitionManager\Traits\HasPartitions;

class Order extends Model
{
    use HasPartitions;

    // Optional: specify the partition column (default: 'created_at')
    protected static string $partitionColumn = 'created_at';
}
```

### Query Scopes

Query specific partitions directly:

```php
// Query a specific partition by name
Order::inPartition('orders_m2024_01')->where('status', 'pending')->get();

// Query by date range (enables partition pruning)
Order::inPartitionRange('2024-01-01', '2024-03-31')->get();

// Query by value (for LIST partitions)
Order::inPartitionValue('active')->get();
Order::inPartitionValues(['pending', 'processing'])->get();
```

### Partition Information

```php
// Check if table is partitioned
if (Order::isPartitioned()) {
    // Get all partitions
    $partitions = Order::getPartitions();

    // Get partition strategy (RANGE, LIST, HASH)
    $strategy = Order::getPartitionStrategy();

    // Get statistics
    $stats = Order::getPartitionStats();

    // Get boundaries
    $boundaries = Order::getPartitionBoundaries();
}
```

### Finding Partitions

```php
// Find partition for a specific date (RANGE)
$partitionName = Order::getPartitionForDate('2024-06-15');
// Returns: 'orders_m2024_06' or null

// Check if partition exists for date
if (Order::hasPartitionForDate('2024-12-01')) {
    // Safe to insert
}

// Find partition for a value (LIST)
$partitionName = Order::getPartitionForValue('active');
```

### Health and Statistics

```php
// Run health check
$health = Order::partitionHealthCheck();
// Returns: ['gaps' => [...], 'overlaps' => [...], 'missing_indexes' => [...], 'orphan_data' => bool]

// Estimate total rows
$totalRows = Order::estimateTotalRows();

// Count rows in specific partition
$count = Order::countInPartition('orders_m2024_01');

// Get oldest/newest partition
$oldest = Order::getOldestPartition();
$newest = Order::getNewestPartition();
```

### Query Analysis

```php
// Explain partition pruning for a query
$query = Order::where('created_at', '>=', '2024-01-01')
    ->where('created_at', '<', '2024-04-01');

$analysis = Order::explainPartitionPruning($query);
// Returns:
// [
//     'partitions_scanned' => ['orders_m2024_01', 'orders_m2024_02', 'orders_m2024_03'],
//     'total_partitions' => 12,
//     'pruning_effective' => true,
//     'plan' => '...'
// ]

// Print partition tree
echo Order::printPartitionTree();
```

### All Trait Methods

```php
// Static methods
Order::getPartitionColumn(): string
Order::isPartitioned(): bool
Order::getPartitions(): array
Order::getPartitionStrategy(): ?string
Order::getPartitionStats(): array
Order::getPartitionBoundaries(): array
Order::partitionHealthCheck(): array
Order::estimateTotalRows(): int
Order::getPartitionForDate(string $date): ?string
Order::getPartitionForValue(mixed $value): ?string
Order::hasPartitionForDate(string $date): bool
Order::explainPartitionPruning(?Builder $query = null): array
Order::printPartitionTree(): string
Order::countInPartition(string $partitionName): int
Order::getOldestPartition(): ?object
Order::getNewestPartition(): ?object

// Query scopes
Order::inPartition(string $partitionName)
Order::inPartitionRange(string $from, string $to)
Order::inPartitionValue(mixed $value)
Order::inPartitionValues(array $values)
```

## Advanced Features

### PostgreSQL Enum Types

The package provides a `pgEnum` Blueprint macro for creating PostgreSQL native enum types:

```php
use Uzbek\LaravelPartitionManager\Partition;

Partition::create('orders', function($table) {
    $table->id();
    $table->pgEnum('status', ['pending', 'processing', 'shipped', 'delivered']);
    $table->pgEnum('priority', ['low', 'medium', 'high'], 'order_priority'); // custom type name
    $table->timestamp('created_at');
})
->list()
->by('status')
->addListPartition('orders_pending', ['pending', 'processing'])
->addListPartition('orders_shipped', ['shipped', 'delivered'])
->generate();
```

The macro:
- Creates a PostgreSQL ENUM type with the specified values
- Registers the type with Laravel's grammar for proper SQL compilation
- Auto-generates type name as `{singular_table}_{column}_enum` if not specified
- Handles duplicate type creation gracefully (won't error if type already exists)

### Default Partitions

Catch rows that don't match any defined partition:

```php
->withDefaultPartition('others')
```

### Check Constraints

```php
->check('positive_amount', 'amount > 0')
->check('valid_status', "status IN ('pending', 'completed')")
```

### Partition Pruning

```php
->enablePartitionPruning()      // Enable query optimization
->detachConcurrently()          // Non-blocking detach operations
```

### Custom Tablespaces

```php
->tablespace('fast_ssd')
```

### Custom Partition Expressions

```php
->partitionByExpression("DATE_TRUNC('week', event_time AT TIME ZONE timezone)")
->partitionByYear('order_date')
->partitionByMonth('created_at')
->partitionByDay('logged_at')
```

### Multi-Column Partitioning

```php
Partition::create('sales', function($table) {
    $table->id();
    $table->string('region');
    $table->integer('year');
    $table->decimal('amount');
})
->by(['region', 'year'])
->addRangePartition('sales_us_2024', ['US', 2024], ['US', 2025])
->generate();
```

## Configuration

```php
// config/partition-manager.php
return [
    'default_connection' => env('DB_CONNECTION', 'pgsql'),

    'defaults' => [
        'enable_partition_pruning' => true,
        'detach_concurrently' => true,
        'analyze_after_create' => true,
        'vacuum_after_drop' => true,
    ],

    'naming' => [
        'prefix' => '',
        'suffix' => '',
        'separator' => '_',
        'date_format' => 'Y_m',
        'day_format' => 'Y_m_d',
    ],

    'schemas' => [
        'auto_create' => [],
        'default' => null,
        'mappings' => [],
    ],

    'logging' => [
        'enabled' => env('PARTITION_LOGGING', true),
        'channel' => env('PARTITION_LOG_CHANNEL', 'daily'),
    ],
];
```

## Service Classes

The package provides standalone service classes for specific partition operations:

### PartitionStats

Get statistics and health information about partitions:

```php
use Uzbek\LaravelPartitionManager\Services\PartitionStats;

// Get partition statistics (row count, size)
$stats = PartitionStats::get('orders');
// Returns: partition_name, row_count, size_bytes, size_pretty

// Get partition boundaries
$boundaries = PartitionStats::boundaries('orders');
// Returns: partition_name, partition_type, from_value, to_value

// Estimate row counts
$rowCount = PartitionStats::estimateRowCount('orders');
$totalRows = PartitionStats::estimateTotalRowCount('orders');

// Health check
$health = PartitionStats::healthCheck('orders');
// Returns: gaps, overlaps, missing_indexes, orphan_data

// Find specific issues
$gaps = PartitionStats::findGaps('orders');
$overlaps = PartitionStats::findOverlaps('orders');
$missingIndexes = PartitionStats::findMissingIndexes('orders');

// Analyze query pruning
$pruning = PartitionStats::explainPruning(
    "SELECT * FROM orders WHERE created_at >= '2024-01-01'"
);
// Returns: partitions_scanned, total_partitions, pruning_effective, plan

// Tree visualization
$tree = PartitionStats::getTree('orders');
echo PartitionStats::printTree('orders');
// Output:
// orders
// ├── orders_2024_01 [2024-01-01 → 2024-02-01]
// ├── orders_2024_02 [2024-02-01 → 2024-03-01]
// └── orders_2024_03 [2024-03-01 → 2024-04-01]
```

### PartitionMaintenance

Perform maintenance operations on partitions:

```php
use Uzbek\LaravelPartitionManager\Services\PartitionMaintenance;
use Uzbek\LaravelPartitionManager\Builders\SubPartitionBuilder;

// Vacuum partition (reclaim storage)
PartitionMaintenance::vacuum('orders_2024_01');
PartitionMaintenance::vacuum('orders_2024_01', full: true);
PartitionMaintenance::vacuum('orders_2024_01', analyze: true);

// Vacuum all partitions of a table
PartitionMaintenance::vacuumAll('orders');
PartitionMaintenance::vacuumAll('orders', full: true);

// Analyze partition (update statistics)
PartitionMaintenance::analyze('orders_2024_01');
PartitionMaintenance::analyzeAll('orders');

// Reindex partition
PartitionMaintenance::reindex('orders_2024_01');
PartitionMaintenance::reindex('orders_2024_01', concurrently: true);
PartitionMaintenance::reindexAll('orders');

// Rebalance hash partitions (change modulus)
PartitionMaintenance::rebalanceHash('events', newModulus: 16);
PartitionMaintenance::rebalanceHash('events', newModulus: 16, schema: 'partitions');

// Add sub-partitions to an existing partition
PartitionMaintenance::addSubPartitions(
    'orders',
    'orders_active',
    SubPartitionBuilder::hash('user_id')->addHashPartitions('shard_', 8)
);

// Get partition expression for an existing partition
$expr = PartitionMaintenance::getPartitionExpression('orders', 'orders_active');
// Returns: "FOR VALUES IN ('active', 'pending')"

// Get commands for parallel execution via jobs/CLI
$commands = PartitionMaintenance::getParallelVacuumCommands('orders');
$commands = PartitionMaintenance::getParallelReindexCommands('orders', concurrently: true);
$commands = PartitionMaintenance::getParallelAnalyzeCommands('orders');

// Dry run mode - returns SQL without executing
$queries = PartitionMaintenance::dryRun(function() {
    PartitionMaintenance::vacuum('orders_2024_01');
    PartitionMaintenance::analyze('orders_2024_01');
});
```

### PartitionConsolidator

Merge multiple partitions into larger ones:

```php
use Uzbek\LaravelPartitionManager\Services\PartitionConsolidator;

// Merge specific partitions
PartitionConsolidator::merge(
    'orders',
    ['orders_2024_01', 'orders_2024_02', 'orders_2024_03'],
    'orders_2024_q1',
    '2024-01-01',
    '2024-04-01'
);

// Consolidate monthly partitions into yearly
PartitionConsolidator::monthlyToYearly('orders', 2024, 'orders_m');

// Consolidate daily partitions into weekly
PartitionConsolidator::dailyToWeekly('logs', '2024-01-01', 'logs_d');

// Consolidate daily partitions into monthly
PartitionConsolidator::dailyToMonthly('logs', 2024, 1, 'logs_d');

// Consolidate weekly partitions into monthly
PartitionConsolidator::weeklyToMonthly('events', 2024, 1, 'events_w');

// Merge all partitions within a date range
PartitionConsolidator::range(
    'orders',
    '2024-01-01',
    '2024-07-01',
    'orders_2024_h1'
);
```

### PartitionSplitter

Split partitions into smaller granularity:

```php
use Uzbek\LaravelPartitionManager\Services\PartitionSplitter;

// Split yearly partition into monthly
PartitionSplitter::yearlyToMonthly('orders', 'orders_y2024', 2024);

// Split yearly partition into weekly
PartitionSplitter::yearlyToWeekly('events', 'events_y2024', 2024);

// Split monthly partition into daily
PartitionSplitter::monthlyToDaily('logs', 'logs_m2024_01', 2024, 1);

// Split monthly partition into weekly
PartitionSplitter::monthlyToWeekly('events', 'events_m2024_01', 2024, 1);

// Custom split with specific ranges
PartitionSplitter::custom('orders', 'orders_q1', [
    'orders_jan' => ['from' => '2024-01-01', 'to' => '2024-02-01'],
    'orders_feb' => ['from' => '2024-02-01', 'to' => '2024-03-01'],
    'orders_mar' => ['from' => '2024-03-01', 'to' => '2024-04-01'],
]);
```

### PartitionRotation

Manage partition lifecycle with rotation policies:

```php
use Uzbek\LaravelPartitionManager\Services\PartitionRotation;

// Ensure future partitions exist
PartitionRotation::ensureFuture('orders', 'created_at', 3, 'monthly');

// Rotate old partitions (drop older than threshold)
$dropped = PartitionRotation::rotate('logs', new DateTime('-6 months'));

// Add monthly partitions for an entire year
PartitionRotation::addMonthlyForYear('orders', 2025, 'created_at');
```

### PartitionIndex

Manage indexes on partitions:

```php
use Uzbek\LaravelPartitionManager\Services\PartitionIndex;

// Create index on partition
PartitionIndex::create('orders_2024_01', 'idx_orders_customer', ['customer_id']);

// Create unique index
PartitionIndex::create('orders_2024_01', 'idx_orders_ref', ['reference'], unique: true);

// Create index with specific method (btree, hash, gist, gin, brin)
PartitionIndex::create('logs_2024_01', 'idx_logs_message', ['message'], method: 'gin');

// Create index concurrently (non-blocking)
PartitionIndex::createConcurrently('orders_2024_01', 'idx_customer', ['customer_id']);

// Drop index
PartitionIndex::drop('idx_orders_customer');
PartitionIndex::drop('idx_orders_customer', cascade: true);
PartitionIndex::dropConcurrently('idx_orders_customer');

// List indexes on partition
$indexes = PartitionIndex::list('orders_2024_01');
```

### PartitionExport

Export partition data to files:

```php
use Uzbek\LaravelPartitionManager\Services\PartitionExport;

// Export to SQL file
PartitionExport::toSql('orders_2024_01', '/backup/orders_2024_01.sql');

// Export to compressed SQL
PartitionExport::toCompressedSql('orders_2024_01', '/backup/orders_2024_01.sql.gz');

// Export to CSV
PartitionExport::toCsv('orders_2024_01', '/backup/orders_2024_01.csv');

// Get export command (without executing)
$command = PartitionExport::getExportCommand('orders_2024_01', '/backup/orders.sql');
```

## API Reference

### Partition (Static Helper)

```php
Partition::create(string $table, Closure $callback): PostgresPartitionBuilder
Partition::for(string $table): QuickPartitionBuilder

// Quick generation
Partition::monthly(string $table, string $column, int $count = 12): void
Partition::yearly(string $table, string $column, int $count = 5): void
Partition::daily(string $table, string $column, int $count = 30): void
Partition::weekly(string $table, string $column, int $count = 12): void
Partition::quarterly(string $table, string $column, int $count = 8): void

// Queries
Partition::isPartitioned(string $table): bool
Partition::getPartitions(string $table): array
Partition::partitionExists(string $table, string $partitionName): bool
Partition::dropIfExists(string $table, bool $withSchema = false): void
Partition::dropSchemaIfEmpty(string $schema): void
Partition::dropSchemaIfExists(string $schema): void

// Attach partitions (for all partition types)
Partition::attachPartition(string $table, string $partitionName, mixed $from, mixed $to): void  // RANGE
Partition::attachListPartition(string $table, string $partitionName, array $values): void       // LIST
Partition::attachHashPartition(string $table, string $partitionName, int $modulus, int $remainder): void  // HASH

// Detach partition
Partition::detachPartition(string $table, string $partitionName, bool $concurrently = false): void
```

### PartitionType Enum

```php
use Uzbek\LaravelPartitionManager\Enums\PartitionType;

PartitionType::RANGE    // 'RANGE'
PartitionType::LIST     // 'LIST'
PartitionType::HASH     // 'HASH'

// Helper methods
$type->isRange(): bool
$type->isList(): bool
$type->isHash(): bool

// PostgreSQL strategy codes
PartitionType::PG_STRATEGY_RANGE  // 'r'
PartitionType::PG_STRATEGY_LIST   // 'l'
PartitionType::PG_STRATEGY_HASH   // 'h'

// Create from PostgreSQL strategy code
PartitionType::fromPgStrategy('r')  // Returns PartitionType::RANGE
```

### PostgresPartitionBuilder

```php
// Configuration
->setBlueprint(Blueprint $blueprint): self
->connection(string $connection): self
->partition(PartitionType|string $type): self  // Optional: auto-detected from add*Partition calls
->range(): self   // Optional: auto-detected
->list(): self    // Optional: auto-detected
->hash(): self    // Optional: auto-detected
->by(string|array $columns): self
->partitionByExpression(string $expression): self

// Adding single partitions (all support optional subPartitions parameter)
->addPartition(PartitionDefinition $partition): self
->addRangePartition(string $name, mixed $from, mixed $to, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->addListPartition(string $name, array $values, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->addHashPartition(string $name, int $modulus, int $remainder, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self

// Adding multiple partitions at once (non-terminal)
// All prefix parameters support % as placeholder for table name
->addListPartitions(array $values, ?string $schema = null): self
// Examples: ['new', 'void', 'used'] or [true => '%_active', false => '%_inactive']
->addHashPartitions(int $modulus, ?string $prefix = null, ?string $schema = null): self
// Example: addHashPartitions(4, '%_shard_') creates: orders_shard_0, orders_shard_1, etc.
->addMonthlyPartitions(int $count, ?string $startDate = null, ?string $prefix = null, ?string $schema = null): self
->addYearlyPartitions(int $count, ?int $startYear = null, ?string $prefix = null, ?string $schema = null): self
->addWeeklyPartitions(int $count, ?string $startDate = null, ?string $prefix = null, ?string $schema = null): self
->addDailyPartitions(int $count, ?string $startDate = null, ?string $prefix = null, ?string $schema = null): self
->dateRange(DateRangeBuilder $builder): self

// Terminal methods (execute immediately, includes generate())
->monthly(int $count = 12, ?string $startDate = null): void
->yearly(int $count = 5, ?int $startYear = null): void
->daily(int $count = 30, ?string $startDate = null): void
->weekly(int $count = 12, ?string $startDate = null): void
->quarterly(int $count = 8, ?int $startYear = null): void

// Schema management
->schema(string $schema): self
->schemaFor(string $partitionType, string $schema): self
->schemasFor(array $schemas): self

// Sub-partitions
->withSubPartitions(string $partitionName, SubPartitionBuilder $builder): self
->addSubPartitionToAll(AbstractSubPartitionBuilder $builder): self  // Apply same sub-partition to all partitions

// Advanced options
->withDefaultPartition(string $name = 'default'): self
->tablespace(string $tablespace): self
->check(string $name, string $expression): self
->enablePartitionPruning(bool $enable = true): self
->detachConcurrently(bool $enable = true): self

// Execution
->generate(): void

// Runtime operations
->attachPartition(string $tableName, string $partitionName, mixed $from, mixed $to): self
->detachPartition(string $partitionName, ?bool $concurrently = null): self
->dropPartition(string $partitionName): self
->analyze(): self
->vacuum(bool $full = false): self
```

### QuickPartitionBuilder

```php
QuickPartitionBuilder::table(string $table): self

->by(string $column): self
->schema(string $schema): self
->connection(string $connection): self

// Range partitions
->monthly(int $count = 12, ?string $startDate = null): void
->yearly(int $count = 5, ?int $startYear = null): void
->daily(int $count = 30, ?string $startDate = null): void
->weekly(int $count = 12, ?string $startDate = null): void
->quarterly(int $count = 8, ?int $startYear = null): void

// List and hash
->byList(string $column, array $partitions): void
->byHash(string $column, int $count = 4): void
```

### DateRangeBuilder

```php
DateRangeBuilder::monthly(): self
DateRangeBuilder::yearly(): self
DateRangeBuilder::daily(): self
DateRangeBuilder::weekly(): self
DateRangeBuilder::quarterly(): self

->from(DateTime|string $date): self
->to(DateTime|string $date): self
->count(int $count): self
->interval(string $interval): self
->nameFormat(string $format): self
->defaultSchema(string $schema): self
->build(string $prefix = ''): array
```

### SubPartitionBuilder

```php
SubPartitionBuilder::list(string $column): ListSubPartitionBuilder
SubPartitionBuilder::range(string $column): RangeSubPartitionBuilder
SubPartitionBuilder::hash(string $column): HashSubPartitionBuilder

// Common methods
->for(string $baseName): self  // Set base name for auto-generating partition names
->table(string $tableName): self  // Set table name for column type lookups (auto-set when used via PostgresPartitionBuilder)
->schema(string $schema): self
->add(SubPartition $partition): self
->getBaseName(): ?string
->getTableName(): ?string
->getPartitionType(): PartitionType
->getPartitionColumn(): string
->toArray(?string $defaultSchema = null): array

// ListSubPartitionBuilder
->addListPartition(string $name, array $values, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->addListPartitions(array $values, ?string $schema = null): self
// Examples:
//   ->addListPartitions(['new', 'void', 'used'])  // auto-generated names using baseName
//   ->addListPartitions(['new' => '%_new', 'void' => '%_void'])  // % = baseName placeholder
//   ->addListPartitions(['false' => 'inactive', 'true' => 'active'])  // casts based on column type (auto-detected)

// RangeSubPartitionBuilder
->addRangePartition(string $name, mixed $from, mixed $to, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->addYearlyPartitions(int $count, string|Carbon|null $startDate = null, ?string $prefix = null, ?string $schema = null): self
->addMonthlyPartitions(int $count, string|Carbon|null $startDate = null, ?string $prefix = null, ?string $schema = null): self
->addWeeklyPartitions(int $count, string|Carbon|null $startDate = null, ?string $prefix = null, ?string $schema = null): self
->addDailyPartitions(int $count, string|Carbon|null $startDate = null, ?string $prefix = null, ?string $schema = null): self
// If prefix is null, auto-generates using baseName: {baseName}_y, {baseName}_m, {baseName}_w, {baseName}_d

// HashSubPartitionBuilder
->addHashPartition(string $name, int $modulus, int $remainder, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->addHashPartitions(int $modulus, ?string $prefix = null, ?string $schema = null): self
// If prefix is null, auto-generates using baseName: {baseName}_p
```

### Value Objects

```php
// RangePartition
RangePartition::range(string $name)
    ->withRange(mixed $from, mixed $to): self
    ->withSchema(string $schema): self
    ->withSubPartitions(SubPartitionBuilder $builder): self

// ListPartition
ListPartition::list(string $name)
    ->withValues(array $values): self
    ->withValue(mixed $value): self
    ->withSchema(string $schema): self

// HashPartition
HashPartition::hash(string $name)
    ->withHash(int $modulus, int $remainder): self
    ->withSchema(string $schema): self

// Sub-partition value objects (all support nested sub-partitions)
RangeSubPartition::create(string $name)
    ->withRange(mixed $from, mixed $to): self
    ->withSchema(string $schema): self
    ->withTablespace(string $tablespace): self
    ->withSubPartitions(AbstractSubPartitionBuilder $builder): self

ListSubPartition::create(string $name)
    ->withValues(array $values): self
    ->withSchema(string $schema): self
    ->withTablespace(string $tablespace): self
    ->withSubPartitions(AbstractSubPartitionBuilder $builder): self

HashSubPartition::create(string $name)
    ->withHash(int $modulus, int $remainder): self
    ->withSchema(string $schema): self
    ->withTablespace(string $tablespace): self
    ->withSubPartitions(AbstractSubPartitionBuilder $builder): self

// Common SubPartition methods
->getName(): string
->getSchema(): ?string
->getTablespace(): ?string
->hasSubPartitions(): bool
->getSubPartitions(): ?AbstractSubPartitionBuilder
->toArray(): array
```

### PartitionManager Service

```php
// Query
->getPartitions(string $table, ?string $connection = null): array
->getPartitionInfo(string $table, string $partitionName, ?string $connection = null): ?object
->isPartitioned(string $table, ?string $connection = null): bool
->getPartitionStrategy(string $table, ?string $connection = null): ?PartitionType
->getPartitionColumns(string $table, ?string $connection = null): array
->getTableSize(string $table, ?string $connection = null): string
->getPartitionCount(string $table, ?string $connection = null): int
->getOldestPartition(string $table, ?string $connection = null): ?object
->getNewestPartition(string $table, ?string $connection = null): ?object

// Maintenance
->analyzePartition(string $partitionName, ?string $connection = null): void
->vacuumPartition(string $partitionName, bool $full = false, ?string $connection = null): void
->dropOldPartitions(string $table, DateTime $before, ?string $connection = null): array
```

## License

MIT
