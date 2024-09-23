<?php

namespace AdMos\DataTables;

use AdMos\DataTables\Contracts\ScoutSearch;
use Carbon\Carbon;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Laravel\Scout\Builder as ScoutBuilder;

class DataTables
{
    const SIMPLE_PAGINATION_RECORDS = 100000;

    /** @var array */
    public $reqData;

    /** @var string */
    private $table;

    /** @var array */
    private $tableColumns;

    /** @var Model */
    public $model;

    /** @var Builder */
    private $query;

    /** @var Builder */
    private $originalQuery;

    /** @var array|null */
    private $aliases;

    /** @var DatabaseManager */
    private $DB;

    /** @var int */
    private $totalRecordsCount = null;

    /** @var bool */
    private $simplePagination = false;

    /** @var array */
    private $strictSearchColumns = [];

    /** @var ScoutSearch */
    private ?ScoutSearch $scout = null;

    public function __construct(Request $request, DatabaseManager $DB)
    {
        $this->reqData = $request->all();
        $this->DB = $DB;
    }

    public function withInput(array $requestData)
    {
        $this->reqData = $requestData;

        return $this;
    }

    public function simplePagination()
    {
        $this->simplePagination = true;

        return $this;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model   $model
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array                                 $aliases
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function provide(Model $model, Builder $query = null, array $aliases = null): JsonResponse
    {
        $query = $this->provider(...func_get_args());

        if ($query) {
            $response = [];

            $response['draw'] = +$this->reqData['draw'];

            if ($this->scout != null) {
                if ($scoutResponse = $this->scoutProvide($query, $response)) {
                    return new JsonResponse($scoutResponse);
                }
            }

            $response = $this->setResultCounters($response);

            $this->applyPagination();

            $response['data'] = $this->query
                ->get()
                ->toArray();

            return new JsonResponse($response);
        }

        return new JsonResponse('', 400);
    }

    public function scout(ScoutSearch $search): static
    {
        $this->scout = $search;

        return $this;
    }

    private function scoutProvide($query, $response)
    {
        $engine = $this->model->searchableUsing();

        if (!$search = $this->scout->search($this)) {
            return null;
        }

        [$length, $offset] = $this->getLengthOffset();

        $scout = $search->query(function (Builder $builder) use ($query) {
            $builder->setQuery($query->getQuery());
        });
        $rawResults = $engine->paginate($scout, $length, 1, $offset);

        if ($this->simplePagination) {
            $response['recordsTotal'] = self::SIMPLE_PAGINATION_RECORDS;
            $response['recordsFiltered'] = self::SIMPLE_PAGINATION_RECORDS;
        } else {
            $originalQuery = $this->originalQuery;
            $tmpScout = $search->query(function (Builder $builder) use ($originalQuery) {
                $builder->setQuery($originalQuery->getQuery());
            });
            $tmpResults = $engine->paginate($tmpScout, $length, 1, $offset);
            $response['recordsTotal'] = $tmpScout->totalCount($tmpResults);

            if ($this->withWheres() || $this->withHavings()) {
                $response['recordsFiltered'] = $scout->totalCount($rawResults);
            } else {
                $response['recordsFiltered'] = $response['recordsTotal'];
            }
        }

        $results = $this->model->newCollection($engine->map(
            $scout,
            $rawResults,
            $this->model
        )->all());

        $response['data'] = $results->toArray();

        return $response;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model   $model
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array                                 $aliases
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function provideQuery(Model $model, Builder $query = null, array $aliases = null): ?Builder
    {
        return $this->provider(...func_get_args());
    }

    private function provider(Model $model, Builder $query = null, array $aliases = null): ?Builder
    {
        if (
            array_key_exists('draw', $this->reqData) &&
            array_key_exists('start', $this->reqData) &&
            array_key_exists('length', $this->reqData)
        ) {
            $this->model = $model;
            $this->query = $query ?? $this->model->newQuery();
            $this->aliases = $aliases;

            $this->table = $model->getTable();
            $this->tableColumns = $this->DB
                ->connection($model->getConnectionName())
                ->getDoctrineSchemaManager()
                ->listTableColumns($this->table);
            $this->tableColumns = $this->removeKeyQuotes($this->tableColumns);

            $this->prepareSelects();
            $this->wrapWheres();

            $this->originalQuery = clone $this->query;

            if (array_key_exists('columns', $this->reqData) && is_array($this->reqData['columns'])) {
                $columns = $this->reqData['columns'];

                if (is_array($this->reqData['columns'])) {
                    $this->applySearch();
                    [$orderColumn, $orderDirection] = $this->getOrder();
                    if ($orderColumn && $orderDirection) {
                        $this->applyQueryOrder($orderColumn, $orderDirection);
                    }
                }
            }

            return $this->query;
        }

        return null;
    }

    public function setTotalRecordsCount(int $count)
    {
        $this->totalRecordsCount = $count;

        return $this;
    }

    public function setStrictSearchColumns(array $columns)
    {
        $this->strictSearchColumns = $columns;

        return $this;
    }

    private function wrapWheres()
    {
        $query = $this->query->getQuery();

        $nq = $query->forNestedWhere();
        $nq->mergeWheres($query->wheres, $query->bindings);

        $query->wheres = [];
        $query->bindings['where'] = [];

        $query->addNestedWhereQuery($nq);
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
                $selects[] = $this->DB->raw($this->table . '.' . $attr);
            }
        }

        if ($this->aliases) {
            foreach ($this->aliases as $alias => $value) {
                $selects[] = $this->DB->raw($value . ' AS `' . $alias . '`');
            }
        }

        if (isset($selects)) {
            $this->query->select($selects);
        }
    }

    private function applySearch()
    {
        if (!is_array($this->reqData['columns'])) {
            return;
        }

        foreach ($this->reqData['columns'] as $column) {
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
                $searchField . ' between ? and ?',
                [$from, $to],
            ];
        } else {
            if ($this->shouldUseLike($this->tableColumns, $column)) {
                return [
                    $searchField . ' like ?',
                    ['%' . $searchValue . '%'],
                ];
            } else {
                return [
                    $searchField . ' = ?',
                    [$searchValue],
                ];
            }
        }
    }

    private function isDateRange($value): bool
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

    public function getOrder(): array
    {
        if (is_array($this->reqData['columns']) && array_key_exists('order', $this->reqData)) {
            $orderColumnId = Arr::get($this->reqData, 'order.0.column');
            $orderByColumn = Arr::get($this->reqData['columns'], $orderColumnId . '.data');
            $direction = Arr::get($this->reqData, 'order.0.dir');

            return [$orderByColumn, $direction];
        }
        return [null, null];
    }

    public function getLengthOffset(): array
    {
        return [
            array_key_exists('length', $this->reqData) ? (int) $this->getRequestRecordsLimit() : 10,
            array_key_exists('start', $this->reqData) ? (int) $this->reqData['start'] : 0
        ];
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

        $this->query->orderByRaw($orderField . ' ' . $direction);
    }

    private function getField($column)
    {
        if (empty($this->aliases) || !array_key_exists($column, $this->aliases)) {
            if (array_key_exists($column, $this->tableColumns)) {
                return $this->table . '.' . $column;
            } else {
                return null;
            }
        } else {
            return $this->aliases[$column];
        }
    }

    private function setResultCounters(array $response): array
    {
        if ($this->simplePagination) {
            $response['recordsTotal'] = self::SIMPLE_PAGINATION_RECORDS;
            $response['recordsFiltered'] = self::SIMPLE_PAGINATION_RECORDS;
        } else {
            $response['recordsTotal'] = $this->totalRecordsCount ?? $this->getCount($this->originalQuery);

            if ($this->withWheres() || $this->withHavings()) {
                $response['recordsFiltered'] = $this->getCount($this->query);
            } else {
                $response['recordsFiltered'] = $response['recordsTotal'];
            }
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

    private function getCount(Builder $query): int
    {
        $countQuery = (clone $query)->getQuery();
        $countQuery->columns = [new Expression('0')];

        if (!empty($countQuery->groups) || !empty($countQuery->havings)) {
            return $this->DB
                ->table($this->DB->raw('(' . $countQuery->toSql() . ') as s'))
                ->setBindings($countQuery->getBindings())
                ->selectRaw('count(*) as count')
                ->first()
                ->count;
        } else {
            return $countQuery->count();
        }
    }

    private function applyPagination()
    {
        if (array_key_exists('start', $this->reqData)) {
            $this->query->offset(+$this->reqData['start']);
        }

        if (array_key_exists('length', $this->reqData)) {
            $this->query->limit($this->getRequestRecordsLimit());
        }
    }

    private function getRequestRecordsLimit(): int
    {
        $length = intval($this->reqData['length']);

        return $this->simplePagination ? $length + 1 : $length;
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
        if (in_array($column, $this->strictSearchColumns)) {
            return false;
        }

        if (!array_key_exists($column, $tableColumns)) {
            return true;
        }

        return !($tableColumns[$column]->getType() instanceof IntegerType ||
            $tableColumns[$column]->getType() instanceof SmallIntType ||
            $tableColumns[$column]->getType() instanceof BigIntType ||
            $tableColumns[$column]->getType() instanceof BooleanType);
    }
}
