<?php

namespace AdMos\DataTables;

use AdMos\DataTables\Mixins\ScoutBuilder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Builder;

class DataTablesServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $app = $this->app;

        $app->alias('datatables', DataTables::class);

        $app->singleton('datatables', function () use ($app) {
            return new DataTables(
                $app->make('request'),
                $app->make(DatabaseManager::class)
            );
        });
    }

    /**
     * @throws \ReflectionException
     */
    public function boot()
    {
        Builder::mixin(new ScoutBuilder());
    }
}
