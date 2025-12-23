<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Uzbek\LaravelPartitionManager\Services\PartitionManager;

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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/partition-manager.php' => config_path('partition-manager.php'),
            ], 'partition-manager-config');
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

            return $this->addColumn($type, $column);
        });
    }

    /** @return array<int, string> */
    public function provides(): array
    {
        return ['partition-manager', PartitionManager::class];
    }
}
