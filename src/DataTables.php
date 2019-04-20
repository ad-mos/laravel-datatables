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
     * @param Illuminate\Database\Eloquent\Model $model
     * @param Illuminate\Database\Eloquent\Builder $query
     * @param array $aliases
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

            $reqData = $this->request->all();

            $this->prepareSelects();
            $this->originalQuery = clone $query;

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

        if ($tableAttr) {
            foreach ($tableAttr as $attr) {
                $selects[] = $this->DB->raw($this->table . '.' . $attr);
            }
        }

        if ($this->aliases) {
            foreach ($this->aliases as $alias => $value) {
                $selects[] = $this->DB->raw($value . ' AS ' . $alias);
            }
        }

        if (isset($selects)) {
            $this->query->select($selects);
        }
    }

    private function applySearch(array $columns)
    {
        foreach ($columns as $column) {
            try {
                if ($column['search']['value'] !== null) {
                    $searchField = $this->getField($column['data']);
                    if (!$searchField) continue;

                    $searchMethod = $this->getSearchMethod($searchField);
                    [$searchQuery,$searchBindings] = $this->getSearchQuery($searchField, $column);

                    $this->query->{$searchMethod}($searchQuery, $searchBindings);
                }
            } catch (\Exception $exception) {}
        }
    }

    private function getSearchQuery($searchField, $column)
    {
        $value = $column['search']['value'];

        if ($this->isDateRange($value)) {
            [$from,$to] = explode(' - ', $value);

            $from = $this->toMySQLDate($from);
            $to = $this->toMySQLDate($to, 1);

            return [
                $searchField . ' between ? and ?',
                [$from, $to]
            ];
        } else {
            if ($this->shouldUseLike($this->tableColumns, $column['data'])) {
                return [
                    $searchField . ' like ?',
                    ['%'.$value.'%']
                ];
            } else {
                return [
                    $searchField . ' = ?',
                    [$value]
                ];
            }
        }
    }

    private function isDateRange($value) : bool
    {
        return (bool) (strlen($value) === 23) &&
            preg_match("^\\d{2}/\\d{2}/\\d{4} - \\d{2}/\\d{2}/\\d{4}^", $value);
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
            try {
                $orderColumnId = +$reqData['order'][0]['column'];
                $orderByColumn = $columns[$orderColumnId]['data'];

                $direction = $reqData['order'][0]['dir'];
                if ($direction !== 'asc' && $direction !== 'desc') return;

                $orderField = $this->getField($orderByColumn);
                if (!$orderField) return;

                $this->query->orderByRaw($orderField . ' ' . $direction);
            } catch (\Exception $exception) {}
        }
    }

    private function getField($column)
    {
        if (!$this->aliases || !array_key_exists($column, $this->aliases)) {
            if (array_key_exists($column, $this->tableColumns)) {
                return $this->table . '.' . $column;
            } else {
                return null;
            }
        } else {
            return $this->aliases[$column];
        }
    }

    private function setResultCounters(array $response) : array
    {
        $response["recordsTotal"] = $this->getCount($this->originalQuery);

        if ($this->query->getQuery()->wheres &&
            $this->originalQuery->getQuery()->wheres !== $this->query->getQuery()->wheres) {
            $response["recordsFiltered"] = $this->getCount($this->query);
        } else {
            $response["recordsFiltered"] = $response["recordsTotal"];
        }

        return $response;
    }

    private function getCount(Builder $query) : int
    {
        if ($query->getQuery()->groups || $query->getQuery()->havings) {
            return $this->DB
                ->table($this->DB->raw('(' . $query->toSql() . ') as s'))
                ->setBindings($query->getBindings())
                ->selectRaw('count(*) as count')
                ->first()
                ->count;
        } else {
            return $query->count();
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
        $mustUseHaving = ['GROUP_CONCAT', 'COUNT', 'MIN', 'IFNULL'];

        foreach ($mustUseHaving as $m) {
            if (strpos($alias, $m) !== false) {
                return 'havingRaw';
            }
        }

        return 'whereRaw';
    }

    /**
     * @param Column[] $tableColumns
     * @param string $column
     * @return mixed
     */
    private function shouldUseLike($tableColumns, $column)
    {
        if (!array_key_exists($column, $tableColumns)) return true;

        return !($tableColumns[$column]->getType() instanceof IntegerType ||
            $tableColumns[$column]->getType() instanceof SmallIntType ||
            $tableColumns[$column]->getType() instanceof BigIntType);
    }
}
