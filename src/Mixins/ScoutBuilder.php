<?php

namespace AdMos\DataTables\Mixins;

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
}
