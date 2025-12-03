<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Uzbek\LaravelPartitionManager\Services\PartitionManager;

class PartitionManagerServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     */
    public bool $defer = true;

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
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/partition-manager.php' => config_path('partition-manager.php'),
            ], 'partition-manager-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['partition-manager', PartitionManager::class];
    }
}