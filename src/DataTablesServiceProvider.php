<?php

namespace AdMos\DataTables;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

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

    public function boot()
    {
        //
    }
}
