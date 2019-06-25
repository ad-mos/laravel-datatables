<?php

namespace AdMos\DataTables;

use Carbon\Carbon;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

class DataTables
{
    /** @var Request $request */
    private $request;

    /** @var string $table */
    private $table;

    /** @var array $tableColumns */
    private $tableColumns;

    /** @var Model $model */
    private $model;

    /** @var Builder $query */
    private $query;

    /** @var Builder $query */
    private $originalQuery;

    /** @var array|null $aliases */
    private $aliases;

    /** @var DatabaseManager */
    private $DB;

    public function __construct(Request $request, DatabaseManager $DB)
    {
        $this->request = $request;
        $this->DB = $DB;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model   $model
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array                                 $aliases
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function provide(Model $model, Builder $query = null, array $aliases = null)
    {
        if ($this->request->has(['draw', 'start', 'length'])) {
            $this->model = $model;
            $this->query = $query ?? $this->model->newQuery();
            $this->aliases = $aliases;

            $this->table = $model->getTable();
            $this->tableColumns = $this->DB
                ->connection($model->getConnectionName())
                ->getDoctrineSchemaManager()
                ->listTableColumns($this->table);
            $this->tableColumns = $this->removeKeyQuotes($this->tableColumns);

            $reqData = $this->request->all();
            $response = [];

            $this->prepareSelects();
            $this->originalQuery = clone $this->query;

            if (array_key_exists('columns', $reqData) && is_array($reqData['columns'])) {
                $columns = $reqData['columns'];

                if (is_array($reqData['columns'])) {
                    $this->applySearch($columns);
                    $this->applyOrder($reqData, $columns);
                }
            }

            $response['draw'] = +$reqData['draw'];
            $response = $this->setResultCounters($response);

            $this->applyPagination($reqData);

            $response['data'] = $this->query
                ->get()
                ->toArray();

            return new JsonResponse($response);
        }

        return new Response('', 400);
    }

    private function removeKeyQuotes($array)
    {
        foreach ($array as $key => $value) {
            $newKey = str_replace('`', '', $key);

            if ($key !== $newKey) {
                $array[$newKey] = $value;
                unset($array[$key]);
            }
        }

        return $array;
    }

    private function prepareSelects()
    {
        $tableAttr = array_keys(
            array_diff_key(
                array_flip(
                    array_keys($this->tableColumns)
                ),
                array_flip($this->model->getHidden())
            )
        );

        if (!empty($tableAttr)) {
            foreach ($tableAttr as $attr) {
                $selects[] = $this->DB->raw($this->table.'.'.$attr);
            }
        }

        if ($this->aliases) {
            foreach ($this->aliases as $alias => $value) {
                $selects[] = $this->DB->raw($value.' AS '.$alias);
            }
        }

        if (isset($selects)) {
            $this->query->select($selects);
        }
    }

    private function applySearch(array $columns)
    {
        foreach ($columns as $column) {
            $searchValue = Arr::get($column, 'search.value');
            $searchColumn = Arr::get($column, 'data');

            if (!is_null($searchValue) && !is_null($searchColumn)) {
                $searchField = $this->getField($searchColumn);
                if (!$searchField) {
                    continue;
                }

                $searchMethod = $this->getSearchMethod($searchField);
                [$searchQuery, $searchBindings] = $this->getSearchQuery($searchField, $searchValue, $searchColumn);

                $this->query->{$searchMethod}($searchQuery, $searchBindings);
            }
        }
    }

    private function getSearchQuery($searchField, $searchValue, $column)
    {
        if ($this->isDateRange($searchValue)) {
            [$from, $to] = explode(' - ', $searchValue);

            $from = $this->toMySQLDate($from);
            $to = $this->toMySQLDate($to, 1);

            return [
                $searchField.' between ? and ?',
                [$from, $to],
            ];
        } else {
            if ($this->shouldUseLike($this->tableColumns, $column)) {
                return [
                    $searchField.' like ?',
                    ['%'.$searchValue.'%'],
                ];
            } else {
                return [
                    $searchField.' = ?',
                    [$searchValue],
                ];
            }
        }
    }

    private function isDateRange($value) : bool
    {
        return (bool) (strlen($value) === 23) &&
            preg_match('^\\d{2}/\\d{2}/\\d{4} - \\d{2}/\\d{2}/\\d{4}^', $value);
    }

    private function toMySQLDate($value, $plusDay = 0)
    {
        return Carbon::createFromFormat('d/m/Y', $value)
            ->addDays($plusDay)
            ->toDateString();
    }

    private function applyOrder(array $reqData, array $columns)
    {
        if (array_key_exists('order', $reqData)) {
            $orderColumnId = Arr::get($reqData, 'order.0.column');
            $orderByColumn = Arr::get($columns, $orderColumnId.'.data');
            $direction = Arr::get($reqData, 'order.0.dir');

            $this->applyQueryOrder($orderByColumn, $direction);
        }
    }

    private function applyQueryOrder($orderByColumn, $direction)
    {
        if ($direction !== 'asc' && $direction !== 'desc') {
            return;
        }

        $orderField = $this->getField($orderByColumn);
        if (!$orderField) {
            return;
        }

        $this->query->orderByRaw($orderField.' '.$direction);
    }

    private function getField($column)
    {
        if (empty($this->aliases) || !array_key_exists($column, $this->aliases)) {
            if (array_key_exists($column, $this->tableColumns)) {
                return $this->table.'.'.$column;
            } else {
                return null;
            }
        } else {
            return $this->aliases[$column];
        }
    }

    private function setResultCounters(array $response) : array
    {
        $response['recordsTotal'] = $this->getCount($this->originalQuery);

        if ($this->withWheres() || $this->withHavings()) {
            $response['recordsFiltered'] = $this->getCount($this->query);
        } else {
            $response['recordsFiltered'] = $response['recordsTotal'];
        }

        return $response;
    }

    private function withWheres()
    {
        return !empty($this->query->getQuery()->wheres) &&
            $this->originalQuery->getQuery()->wheres !== $this->query->getQuery()->wheres;
    }

    private function withHavings()
    {
        return !empty($this->query->getQuery()->havings) &&
            $this->originalQuery->getQuery()->havings !== $this->query->getQuery()->havings;
    }

    private function getCount(Builder $query) : int
    {
        if (!empty($query->getQuery()->groups) || !empty($query->getQuery()->havings)) {
            return $this->DB
                ->table($this->DB->raw('('.$query->toSql().') as s'))
                ->setBindings($query->getQuery()->getBindings())
                ->selectRaw('count(*) as count')
                ->first()
                ->count;
        } else {
            return $query->getQuery()->count();
        }
    }

    private function applyPagination(array $reqData)
    {
        if (array_key_exists('start', $reqData)) {
            $this->query->offset(+$reqData['start']);
        }

        if (array_key_exists('length', $reqData)) {
            $this->query->limit(+$reqData['length']);
        }
    }

    private function getSearchMethod($alias)
    {
        $aggregate = [
            'AVG',
            'BIT_AND',
            'BIT_OR',
            'BIT_XOR',
            'COUNT',
            'GROUP_CONCAT',
            'JSON_ARRAYAGG',
            'JSON_OBJECTAGG',
            'MAX',
            'MIN',
            'STD',
            'STDDEV',
            'STDDEV_POP',
            'STDDEV_SAMP',
            'SUM',
            'VAR_POP',
            'VAR_SAMP',
            'VARIANCE',
        ];

        foreach ($aggregate as $m) {
            if (strpos($alias, $m) !== false) {
                return 'havingRaw';
            }
        }

        return 'whereRaw';
    }

    /**
     * @param Column[] $tableColumns
     * @param string   $column
     *
     * @return mixed
     */
    private function shouldUseLike($tableColumns, $column)
    {
        if (!array_key_exists($column, $tableColumns)) {
            return true;
        }

        return !($tableColumns[$column]->getType() instanceof IntegerType ||
            $tableColumns[$column]->getType() instanceof SmallIntType ||
            $tableColumns[$column]->getType() instanceof BigIntType);
    }
}
