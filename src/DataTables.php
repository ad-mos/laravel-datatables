<?php

namespace AdMos\DataTables;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class DataTables
{
    const SIMPLE_PAGINATION_RECORDS = 100000;

    /** @var array */
    private $reqData;

    /** @var string */
    private $table;

    /** @var array */
    private $tableColumns;

    /** @var Model */
    private $model;

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

    private ?int $timeout = null;
    private int $defaultTimeout = 45000;
    private string $timeoutErrorText = 'Час очікування запиту вийшов, змініть критерії пошуку, або спробуйте пізніше.';

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
        $this->provider(...func_get_args());

        if ($this->query) {
            try {
                $response = [];

                $response['draw'] = +$this->reqData['draw'];
                $response = $this->affectTimeout(fn() => $this->setResultCounters($response));

                $this->applyPagination();

                $response['data'] = $this->query
                    ->get()
                    ->toArray();

                return new JsonResponse($response);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'execution time exceeded')) {
                    return $this->provideTimeoutError();
                }

                throw $e;
            }
        }

        return new JsonResponse('', 400);
    }

    private function affectTimeout(callable $action)
    {
        $time = microtime(true);
        $result = $action();
        $time = (int) ((microtime(true) - $time) * 1000);

        $timeout = $this->getTimeout();
        if ($timeout > 0) {
            $timeout -= $time;
            if ($timeout <= 0) {
                throw new QueryException('Query execution time exceeded', [], new Exception());
            }
        }

        return $result;
    }

    private function provideTimeoutError(): JsonResponse
    {
        $this->defaultTimeout = 0;

        return new JsonResponse([
            'draw' => +$this->reqData['draw'],
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => $this->timeoutErrorText,
        ]);
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
        $this->defaultTimeout = 0;

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
            $this->tableColumns = $this->getTableColumns($model);
            $this->tableColumns = $this->removeKeyQuotes($this->tableColumns);

            $this->prepareSelects();
            $this->wrapWheres();

            $this->originalQuery = clone $this->query;

            if (array_key_exists('columns', $this->reqData) && is_array($this->reqData['columns'])) {
                $columns = $this->reqData['columns'];

                if (is_array($this->reqData['columns'])) {
                    $this->applySearch($columns);
                    $this->applyOrder($columns);
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

    public function setTimeout(int $milliseconds, ?string $errorText = null)
    {
        $this->timeout = $milliseconds;
        $this->timeoutErrorText = $errorText ?? $this->timeoutErrorText;

        return $this;
    }

    private function getTimeout(): int
    {
        return $this->timeout ?? $this->defaultTimeout;
    }

    private function getTimeoutHint(): string
    {
        $timeout = $this->getTimeout();

        if ($timeout === 0) {
            return '';
        }

        return " /*+ MAX_EXECUTION_TIME({$timeout}) */ ";
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

    /**
     * Get table columns with backwards compatibility for Laravel 10 and 11+.
     */
    private function getTableColumns(Model $model): array
    {
        $connection = $this->DB->connection($model->getConnectionName());

        // Laravel 11+ removed getDoctrineSchemaManager, use Schema::getColumns instead
        if (!method_exists($connection, 'getDoctrineSchemaManager')) {
            $columns = Schema::connection($model->getConnectionName())->getColumns($this->table);

            // Convert to associative array keyed by column name with type info
            $result = [];
            foreach ($columns as $column) {
                $result[$column['name']] = [
                    'name' => $column['name'],
                    'type' => $column['type_name'],
                ];
            }

            return $result;
        }

        // Laravel 10 and below - use Doctrine DBAL
        return $connection->getDoctrineSchemaManager()->listTableColumns($this->table);
    }

    /**
     * Check if a column is an integer type (works with both Doctrine Column and Laravel 11 array).
     */
    private function isIntegerType($column): bool
    {
        // Laravel 11+ format (array with 'type' key)
        if (is_array($column) && isset($column['type'])) {
            $type = strtolower($column['type']);

            return in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'bool', 'boolean']);
        }

        // Laravel 10 and below - Doctrine Column object
        if (is_object($column) && method_exists($column, 'getType')) {
            $typeName = get_class($column->getType());

            // Check for Doctrine type classes using string comparison
            // to avoid class loading issues when Doctrine DBAL is not installed
            return in_array($typeName, [
                'Doctrine\DBAL\Types\IntegerType',
                'Doctrine\DBAL\Types\SmallIntType',
                'Doctrine\DBAL\Types\BigIntType',
                'Doctrine\DBAL\Types\BooleanType',
            ]);
        }

        return false;
    }

    private function prepareSelects()
    {
        if (!empty($timeoutHint = $this->getTimeoutHint())) {
            $selects = [$this->DB->raw($timeoutHint . '0')];
        }

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
                $selects[] = $this->DB->raw($value.' AS `'.$alias.'`');
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

    private function applyOrder(array $columns)
    {
        if (array_key_exists('order', $this->reqData)) {
            $orderColumnId = Arr::get($this->reqData, 'order.0.column');
            $orderByColumn = Arr::get($columns, $orderColumnId.'.data');
            $direction = Arr::get($this->reqData, 'order.0.dir');

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

        return $this->DB
            ->table($this->DB->raw('(' . $countQuery->toSql() . ') as s'))
            ->setBindings($countQuery->getBindings())
            ->selectRaw($this->getTimeoutHint() . 'count(*) as count')
            ->get()
            ->first()
            ->count;
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
     * @param array  $tableColumns
     * @param string $column
     *
     * @return bool
     */
    private function shouldUseLike($tableColumns, $column)
    {
        if (in_array($column, $this->strictSearchColumns)) {
            return false;
        }

        if (!array_key_exists($column, $tableColumns)) {
            return true;
        }

        return !$this->isIntegerType($tableColumns[$column]);
    }
}
