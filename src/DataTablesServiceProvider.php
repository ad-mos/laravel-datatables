<?php

namespace AdMos\DataTables;

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
        $this->app->alias('datatables', DataTables::class);

        $this->app->singleton('datatables', function () {
            return new DataTables(request());
        });
    }

    public function boot()
    {
        //
    }
}
