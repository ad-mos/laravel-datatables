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
    public function register(): void
    {
        $app = $this->app;

        $this->mergeConfigFrom(
            __DIR__.'/../config/ad-mos-datatables.php', 'ad-mos-datatables'
        );

        $class = config('ad-mos-datatables.class', DataTables::class);

        $app->alias('datatables', $class);

        $app->singleton('datatables', function () use ($app, $class) {
            return new $class(
                $app->make('request'),
                $app->make(DatabaseManager::class)
            );
        });
    }



    public function boot(): void
    {
        $this->offerPublishing();
    }

    protected function offerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ad-mos-datatables.php' => config_path('ad-mos-datatables.php'),
            ], 'admos-datatables-config');
        }
    }
}
