<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | This option controls the default database connection that will be used
    | for all partition operations. You can override this per-operation by
    | calling the connection() method on the builder or passing a connection
    | parameter to PartitionManager methods.
    |
    | Supported: Any valid Laravel database connection name
    | Default: The application's default database connection (usually 'pgsql')
    |
    */

    'default_connection' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Default Behaviors
    |--------------------------------------------------------------------------
    |
    | These options control the default behaviors for partition operations.
    | Each can be overridden on a per-operation basis using the corresponding
    | builder methods (e.g., enablePartitionPruning(), detachConcurrently()).
    |
    */

    'defaults' => [

        /*
        | Partition Pruning
        |--------------------------------------------------------------------------
        | Enable or disable partition pruning optimization. When enabled, PostgreSQL
        | can skip scanning irrelevant partitions during query execution, improving
        | performance for queries with partition key filters.
        |
        | This sets the enable_partition_pruning session parameter.
        |
        | Default: true (recommended for optimal query performance)
        */
        'enable_partition_pruning' => true,

        /*
        | Detach Concurrently
        |--------------------------------------------------------------------------
        | Use the CONCURRENTLY option when detaching partitions. When enabled,
        | partition detachment operations will not block other queries, but they
        | will take longer to complete and require a second transaction to finalize.
        |
        | This is a PostgreSQL 14+ feature and is fully supported since this
        | package requires PostgreSQL 14 or higher.
        |
        | Default: true (non-blocking detach for better concurrency)
        */
        'detach_concurrently' => true,

        /*
        | Analyze After Create
        |--------------------------------------------------------------------------
        | Automatically run ANALYZE on the main table after creating partitions.
        | This updates table statistics for the query planner, which is important
        | for optimal query performance but adds time to partition creation.
        |
        | Recommended: true for production environments
        |
        | Default: true
        */
        'analyze_after_create' => true,

        /*
        | Vacuum After Drop
        |--------------------------------------------------------------------------
        | Automatically run VACUUM on the main table after dropping partitions.
        | This reclaims storage space and updates statistics, but adds time to
        | the drop operation.
        |
        | Recommended: true for regular maintenance, false for bulk operations
        |
        | Default: true
        */
        'vacuum_after_drop' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Partition Naming Conventions
    |--------------------------------------------------------------------------
    |
    | These options control how partition names are automatically generated.
    | Custom naming patterns help organize partitions and make them easier
    | to identify and manage in PostgreSQL.
    |
    */

    'naming' => [

        /*
        | Name Prefix
        |--------------------------------------------------------------------------
        | A string to prepend to all automatically generated partition names.
        | Useful for namespacing partitions or adding environment indicators.
        |
        | Example: 'prod_' would generate names like 'prod_logs_2024_01'
        |
        | Default: '' (no prefix)
        */
        'prefix' => '',

        /*
        | Name Suffix
        |--------------------------------------------------------------------------
        | A string to append to all automatically generated partition names.
        | Can be used to add metadata or version indicators to partition names.
        |
        | Example: '_v1' would generate names like 'logs_2024_01_v1'
        |
        | Default: '' (no suffix)
        */
        'suffix' => '',

        /*
        | Name Separator
        |--------------------------------------------------------------------------
        | The character(s) used to separate components in partition names.
        | This appears between the table name, date components, and any
        | prefix/suffix values.
        |
        | Example: '_' generates 'logs_2024_01', '-' generates 'logs-2024-01'
        |
        | Default: '_' (underscore)
        */
        'separator' => '_',

        /*
        | Monthly Date Format
        |--------------------------------------------------------------------------
        | PHP date format string used for monthly partition names. This controls
        | how the date portion of monthly partition names appears.
        |
        | Common formats:
        | - 'Y_m'     → '2024_01' (year_month with leading zero)
        | - 'Y_n'     → '2024_1' (year_month without leading zero)
        | - 'Y_M'     → '2024_Jan' (year_short month name)
        | - 'Y_m_d'   → '2024_01_01' (full date)
        |
        | Default: 'Y_m' (e.g., 2024_01, 2024_12)
        */
        'date_format' => 'Y_m',

        /*
        | Daily Date Format
        |--------------------------------------------------------------------------
        | PHP date format string used for daily partition names. This controls
        | how the date portion of daily partition names appears.
        |
        | Common formats:
        | - 'Y_m_d'   → '2024_01_15' (year_month_day)
        | - 'Ymd'     → '20240115' (compact format)
        | - 'Y_z'     → '2024_015' (year_day of year)
        | - 'Y_W'     → '2024_W03' (year_ISO week)
        |
        | Default: 'Y_m_d' (e.g., 2024_01_15)
        */
        'day_format' => 'Y_m_d',

    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Control whether partition operations are logged and which Laravel
    | logging channel to use. Logs include partition creation, drops,
    | errors, and maintenance operations.
    |
    */

    'logging' => [

        /*
        | Enable Logging
        |--------------------------------------------------------------------------
        | Enable or disable logging for partition operations. When enabled,
        | partition creation, deletion, errors, and maintenance operations
        | will be logged to the specified channel.
        |
        | Recommended: true for production to track partition lifecycle
        |
        | Default: true
        */
        'enabled' => env('PARTITION_LOGGING', true),

        /*
        | Log Channel
        |--------------------------------------------------------------------------
        | The Laravel log channel to use for partition operation logs.
        | Must be a valid channel defined in config/logging.php.
        |
        | Common channels: 'daily', 'single', 'stack', 'syslog', 'errorlog'
        |
        | Default: 'daily' (separate daily log files)
        */
        'channel' => env('PARTITION_LOG_CHANNEL', 'daily'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Partition Templates
    |--------------------------------------------------------------------------
    |
    | Define reusable partition configurations that can be applied to multiple
    | tables. Templates reduce code duplication and ensure consistency across
    | similar tables (e.g., all time-series tables, all tenant tables).
    |
    | Template values support the % placeholder which is replaced with the
    | table name. For example, schema '%_archive' for table 'orders' becomes
    | 'orders_archive'.
    |
    | Usage in migrations:
    |     Partition::create('orders', fn($t) => $t->id())
    |         ->fromTemplate('monthly_archive')
    |         ->generate();
    |
    */

    'templates' => [

        // Example: Monthly partitioned table with archive schema
        // 'monthly_archive' => [
        //     'type' => 'range',              // range, list, or hash
        //     'column' => 'created_at',       // partition column
        //     'interval' => 'monthly',        // daily, weekly, monthly, yearly
        //     'count' => 12,                  // number of partitions
        //     'schema' => '%_archive',        // schema with % placeholder
        //     'default_partition' => true,    // create DEFAULT partition
        //     'future_partitions' => 3,       // additional future partitions
        // ],

        // Example: Hash partitioned multi-tenant table
        // 'tenant_hash' => [
        //     'type' => 'hash',
        //     'column' => 'tenant_id',
        //     'modulus' => 16,                // number of hash buckets
        //     'schema' => 'tenants',
        // ],

        // Example: Yearly partitioned cold storage table
        // 'yearly_cold_storage' => [
        //     'type' => 'range',
        //     'column' => 'created_at',
        //     'interval' => 'yearly',
        //     'count' => 5,
        //     'tablespace' => 'cold_storage', // PostgreSQL tablespace
        // ],

        // Example: List partitioned by status
        // 'status_list' => [
        //     'type' => 'list',
        //     'column' => 'status',
        //     'values' => ['pending', 'active', 'completed', 'cancelled'],
        //     'default_partition' => true,
        // ],

    ],

];