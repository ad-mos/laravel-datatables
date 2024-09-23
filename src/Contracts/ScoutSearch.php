<?php

namespace AdMos\DataTables\Contracts;

use AdMos\DataTables\DataTables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Scout\Builder;

interface ScoutSearch
{
    public function search(DataTables $dataTables): ?Builder;
}
