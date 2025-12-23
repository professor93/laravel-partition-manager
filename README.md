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
->list()
->by('country')
->addListPartition('users_us', ['US', 'CA'])
->addListPartition('users_eu', ['DE', 'FR', 'IT', 'ES'])
->addListPartition('users_asia', ['JP', 'CN', 'KR'])
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
->hash()
->by('id')
->hashPartitions(4)
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
->partition(PartitionType|string $type): self
->range(): self
->list(): self
->hash(): self
->by(string|array $columns): self
->partitionByExpression(string $expression): self

// Adding partitions (all support optional subPartitions parameter)
->addPartition(PartitionDefinition $partition): self
->addRangePartition(string $name, mixed $from, mixed $to, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->addListPartition(string $name, array $values, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->addHashPartition(string $name, int $modulus, int $remainder, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->hashPartitions(int $count, string $prefix = ''): self
->dateRange(DateRangeBuilder $builder): self

// Terminal methods (execute immediately)
->monthly(int $count = 12, ?string $startDate = null): void
->yearly(int $count = 5, ?int $startYear = null): void
->daily(int $count = 30, ?string $startDate = null): void
->weekly(int $count = 12, ?string $startDate = null): void
->quarterly(int $count = 8, ?int $startYear = null): void

// Schema management
->schema(string $schema): self
->schemaFor(string $partitionType, string $schema): self
->schemasFor(array $schemas): self

// Advanced options
->withSubPartitions(string $partitionName, SubPartitionBuilder $builder): self
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
->defaultSchema(string $schema): self
->add(SubPartition $partition): self
->getPartitionType(): PartitionType
->getPartitionColumn(): string
->toArray(?string $defaultSchema = null): array

// ListSubPartitionBuilder
->addListPartition(string $name, array $values, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self

// RangeSubPartitionBuilder
->addRangePartition(string $name, mixed $from, mixed $to, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->addYearlyPartitions(string $prefix, int $count, string|Carbon|null $startDate = null, ?string $schema = null): self
->addMonthlyPartitions(string $prefix, int $count, string|Carbon|null $startDate = null, ?string $schema = null): self
->addWeeklyPartitions(string $prefix, int $count, string|Carbon|null $startDate = null, ?string $schema = null): self
->addDailyPartitions(string $prefix, int $count, string|Carbon|null $startDate = null, ?string $schema = null): self

// HashSubPartitionBuilder
->addHashPartition(string $name, int $modulus, int $remainder, ?string $schema = null, ?AbstractSubPartitionBuilder $subPartitions = null): self
->addHashPartitions(string $prefix, int $modulus, ?string $schema = null): self
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
