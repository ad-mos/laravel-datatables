<?php

namespace AdMos\DataTables\Mixins;

use AdMos\DataTables\DataTables;

/**
 * @mixin \Laravel\Scout\Builder
 */
class ScoutBuilder
{
    public function totalCount()
    {
        return function ($results) {
            return $this->getTotalCount($results);
        };
    }

//    public function toDatatables($response, $closure, $paginate = false, $simplePagination = false)
//    {
//        $engine = $this->engine();
//        if(!$paginate){
//
//        }
//
//        $results = $this->model->newCollection($engine->map(
//            $this, $rawResults = $engine->paginate($this, $perPage, $page), $this->model
//        )->all());
//
//        if ($simplePagination) {
//            $response['recordsTotal'] = DataTables::SIMPLE_PAGINATION_RECORDS;
//            $response['recordsFiltered'] = DataTables::SIMPLE_PAGINATION_RECORDS;
//        } else {
//            $response['recordsTotal'] = $this->;
//
//            if ($this->withWheres() || $this->withHavings()) {
//                $response['recordsFiltered'] = $this->getCount($this->query);
//            } else {
//                $response['recordsFiltered'] = $response['recordsTotal'];
//            }
//        }
//    }
}
