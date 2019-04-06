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
use Illuminate\Http\Request;

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

    /** @var array|null $aliases */
    private $aliases;

    /** @var DatabaseManager */
    private $DB;


    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->DB = app(DatabaseManager::class);
    }

    /**
     * @param Illuminate\Database\Eloquent\Model $model
     * @param Illuminate\Database\Eloquent\Builder $query
     * @param array $aliases
     * @return \Illuminate\Http\Response
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

            return response()->json($response);
        }

        return response('', 400);
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
                    $searchField = $this->getSearchField($column['data']);

                    $searchMethod = $this->getSearchMethod($searchField);
                    $searchQuery = $this->getSearchQuery($searchField, $column);

                    $this->query->{$searchMethod}($searchQuery);
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

            return $searchField . ' between \'' . $from . '\' and \'' . $to . '\'';
        } else {
            if ($this->shouldUseLike($this->tableColumns, $column['data'])) {
                return $searchField . ' like \'%' . $value . '%\'';
            } else {
                return $searchField . ' = \'' . $value . '\'';
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

    private function getSearchField($column)
    {
        if (!$this->aliases || !array_key_exists($column, $this->aliases)) {
            return $this->table . '.' . $column;
        } else {
            return $this->aliases[$column];
        }
    }

    private function applyOrder(array $reqData, array $columns)
    {
        if (array_key_exists('order', $reqData)) {
            try {
                if (!$this->aliases || !array_key_exists($columns[$reqData['order'][0]['column']]['data'], $this->aliases)) {
                    $this->query->orderBy(
                        $this->table. '.' .$columns[$reqData['order'][0]['column']]['data'],
                        $reqData['order'][0]['dir']
                    );
                } else {
                    $this->query->orderByRaw(
                        $this->aliases[$columns[$reqData['order'][0]['column']]['data']] . ' ' .
                        $reqData['order'][0]['dir']
                    );
                }
            } catch (\Exception $exception) {}
        }
    }

    private function setResultCounters(array $response) : array
    {
        $response["recordsTotal"] = $this->model->count();
        if ($this->query->getQuery()->wheres) {
            if ($this->query->getQuery()->groups || $this->query->getQuery()->havings) {
                $response["recordsFiltered"] = $this->DB->table($this->DB->raw('('.$this->query->toSql().') as s'))
                    ->setBindings($this->query->getBindings())
                    ->selectRaw('count(*) as count')
                    ->first()->count;
            } else {
                $response["recordsFiltered"] = $this->query->count();
            }
        } else {
            $response["recordsFiltered"] = $response["recordsTotal"];
        }

        return $response;
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
