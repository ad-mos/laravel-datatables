<?php

namespace AdMos\DataTables\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \AdMos\DataTables\DataTables
 * @method static \Illuminate\Http\Response provide($model, $query, $aliases)
 *
 * @see \AdMos\DataTables\DataTables
 */
class DataTables extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'datatables';
    }
}
