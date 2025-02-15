<?php namespace Chumper\Datatable\Engines;

use Exception;
use Assetic\Extension\Twig\AsseticFilterFunction;
use Chumper\Datatable\Columns\DateColumn;
use Chumper\Datatable\Columns\FunctionColumn;
use Chumper\Datatable\Columns\TextColumn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Config;
use Request;


/**
 * Class BaseEngine
 * @package Chumper\Datatable\Engines
 */
abstract class BaseEngine {

    const ORDER_ASC = 'asc';
    const ORDER_DESC = 'desc';

    /**
     * @var array
     */
    protected $config = array();

    /**
     * @var mixed
     */
    protected $rowClass = null;

    /**
     * @var mixed
     */
    protected $rowId = null;

    /**
     * @var array
     */
    protected $rowData = null;

    /**
     * @var array
     */
    protected $columnSearches = array();

    /**
     * @var array
     * support for DB::raw fields on where
     */
    protected $fieldSearches = array();

    /**
     * @var array
     * support for DB::raw fields on where
     * sburkett - added for column-based exact matching                                                                                                            
     */                                                                                                                                                            
    protected $columnSearchExact = array(); 

    /**
     * @var
     */
    protected  $sEcho;

    /**
     * @var \Illuminate\Support\Collection
     */
    protected $columns;

    /**
     * @var array
     */
    protected  $searchColumns = array();

    /**
     * @var array
     */
    protected $showColumns = array();

    /**
     * @var array
     */
    protected  $orderColumns = array();

    /**
     * @var int
     */
    protected $skip = 0;

    /**
     * @var null
     */
    protected $limit = null;

    /**
     * @var null
     */
    protected $search = null;

    /**
     * @var null
     * Will be an array if order is set
     * array(
     *  0 => column
     *  1 => name:cast:length
     * )
     */
    protected $orderColumn = null;

    /**
     * @var string
     */
    protected $orderDirection = BaseEngine::ORDER_ASC;

    /**
     * @var boolean If the return should be alias mapped
     */
    protected $aliasMapping = false;

    /**
     * @var bool If the search should be done with exact matching
     */
    protected $exactWordSearch = false;


    function __construct()
    {
        $this->columns = new Collection();
        $this->config = Config::get('chumper.datatable.engine');
        $this->setExactWordSearch( $this->config['exactWordSearch'] );
        return $this;
    }

    /**
     * @return $this
     * @throws \Exception
     */
    public function addColumn()
    {
        if(func_num_args() != 2 && func_num_args() != 1)
            throw new Exception('Invalid number of arguments');

        if(func_num_args() == 1)
        {
            //add a predefined column
            $this->columns->put(func_get_arg(0)->getName(), func_get_arg(0));
        }
        else if(is_callable(func_get_arg(1)))
        {
            $this->columns->put(func_get_arg(0), new FunctionColumn(func_get_arg(0), func_get_arg(1)));
        }
        else
        {
            $this->columns->put(func_get_arg(0), new TextColumn(func_get_arg(0),func_get_arg(1)));
        }
        return $this;
    }

    /**
     * @param $name
     * @return mixed
     */
    public function getColumn($name)
    {
        return $this->columns->get($name,null);
    }

    /**
     * @return array
     */
    public function getOrder()
    {
        return array_keys($this->columns->toArray());
    }

    /**
     * @return array
     */
    public function getOrderingColumns()
    {
        return $this->orderColumns;
    }

    /**
     * @return array
     */
    public function getSearchingColumns()
    {
        return $this->searchColumns;
    }

    /**
     * @return $this
     */
    public function clearColumns()
    {
        $this->columns = new Collection();
        return $this;
    }

    /**
     * @param $cols
     * @return $this
     */
    public function showColumns($cols)
    {
        if ( ! is_array($cols)) {
            $cols = func_get_args();
        }

        foreach ($cols as $property) {
            //quick fix for created_at and updated_at columns
            if(in_array($property, array('created_at', 'updated_at')))
            {
                $this->columns->put($property, new DateColumn($property, DateColumn::DAY_DATE));
            }
            else
            {
                $this->columns->put($property, new FunctionColumn($property, function($model) use($property){
                    try{return is_array($model)?$model[$property]:$model->$property;}catch(Exception $e){return null;}    
                }));
            }
            $this->showColumns[] = $property;
        }
        return $this;
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function make()
    {
        //TODO Handle all inputs
        $this->handleInputs();
        $this->prepareSearchColumns();

        $output = array(
            "aaData" => $this->internalMake($this->columns, $this->searchColumns)->toArray(),
            "sEcho" => intval($this->sEcho),
            "iTotalRecords" => $this->totalCount(),
            "iTotalDisplayRecords" => $this->count(),
        );
        return Response::json($output);
    }

    /**
     * @param $cols
     * @return $this
     */
    public function searchColumns($cols)
    {
        if ( ! is_array($cols)) {
            $cols = func_get_args();
        }

        $this->searchColumns = $cols;

        return $this;
    }

    /**
     * @param $cols
     * @return $this
     */
    public function orderColumns($cols)
    {
        if ( ! is_array($cols)) {
            $cols = func_get_args();
        }

        if (count($cols) == 1 && $cols[0] == '*')
            $cols = $this->showColumns;

        $this->orderColumns = $cols;
        return $this;
    }

    /**
     * @param $function Set a function for a dynamic row class
     * @return $this
     */
    public function setRowClass($function)
    {
        $this->rowClass = $function;
        return $this;
    }

    /**
     * @param $function Set a function for a dynamic row id
     * @return $this
     */
    public function setRowId($function)
    {
        $this->rowId = $function;
        return $this;
    }

    /**
     * @param $function Set a function for dynamic html5 data attributes
     * @return $this
     */
    public function setRowData($function)
    {
        $this->rowData = $function;
        return $this;
    }

    public function setAliasMapping($value = true)
    {
        $this->aliasMapping = $value;
        return $this;
    }

    public function setExactWordSearch($value = true)
    {
        $this->exactWordSearch = $value;
        return $this;
    }
    
    /**
     * @param $columnNames Sets up a lookup table for which columns should use exact matching -sburkett
     * @return $this
     */
    public function setExactMatchColumns($columnNames)
    {
      foreach($columnNames as $columnIndex)
        $this->columnSearchExact[ $columnIndex ] = true;

      return $this;
    }

    public function getRowClass()
    {
        return $this->rowClass;
    }

    public function getRowId()
    {
        return $this->rowId;
    }

    public function getRowData()
    {
        return $this->rowData;
    }

    public function getAliasMapping()
    {
        return $this->aliasMapping;
    }
    //-------------protected functionS-------------------

    /**
     * @param $value
     */
    protected function handleiDisplayStart($value)
    {
        //skip
        $this->skip($value);
    }

    /**
     * @param $value
     */
    protected function handleiDisplayLength($value)
    {
        //limit nicht am query, sondern den ganzen
        //holen und dann dynamisch in der Collection taken und skippen
        $this->take($value);
    }

    /**
     * @param $value
     */
    protected function handlesEcho($value)
    {
        $this->sEcho = $value;
    }

    /**
     * @param $value
     */
    protected function handlesSearch($value)
    {
        //handle search on columns sSearch, bRegex
        $this->search($value);
    }


    /**
     * @param $value
     */
    protected function handleiSortCol_0($value)
    {
        if(Request::get('sSortDir_0') == 'desc')
            $direction = BaseEngine::ORDER_DESC;
        else
            $direction = BaseEngine::ORDER_ASC;

        //check if order is allowed
        if(empty($this->orderColumns))
        {
            $this->order(array(0 => $value, 1 => $this->getNameByIndex($value)), $direction);
            return;
        }

        //prepare order array
        $cleanNames = array();
        foreach($this->orderColumns as $c)
        {
            if(strpos($c,':') !== FALSE)
            {
                $cleanNames[] = substr($c, 0, strpos($c,':'));
            }
            else
            {
                $cleanNames[] = $c;
            }
        }

        $i = 0;
        foreach($this->columns as $name => $column)
        {
            if($i == $value && in_array($name, $cleanNames))
            {
                $this->order(array(0 => $value, 1 => $this->orderColumns[array_search($name,$cleanNames)]), $direction);
                return;
            }
            $i++;
        }
    }

    /**
     * @param int $columnIndex
     * @param string $searchValue
     *
     * @return void
     */
    protected function handleSingleColumnSearch($columnIndex, $searchValue)
    {
        //dd($columnIndex, $searchValue, $this->searchColumns);
        if (!isset($this->searchColumns[$columnIndex])) return;
        if (empty($searchValue) && $searchValue !== '0') return;

        $columnName = $this->searchColumns[$columnIndex];
        $this->searchOnColumn($columnName, $searchValue);
    }

    /**
     *
     */
    protected function handleInputs()
    {
        //Handle all inputs magically
        foreach (Request::all() as $key => $input) {

            // handle single column search
            if ($this->isParameterForSingleColumnSearch($key))
            {
                $columnIndex = str_replace('sSearch_','',$key);
                $this->handleSingleColumnSearch($columnIndex, $input);
                continue;
            }

            if(method_exists($this, $function = 'handle'.$key))
                $this->$function($input);
        }
    }

    /**
     * @param $parameterName
     *
     * @return bool
     */
    protected function isParameterForSingleColumnSearch($parameterName)
    {
        static $parameterNamePrefix = 'sSearch_';
        return str_contains($parameterName, $parameterNamePrefix);
    }

    protected function prepareSearchColumns()
    {
        if(count($this->searchColumns) == 0 || empty($this->searchColumns))
            $this->searchColumns = $this->showColumns;
    }

    /**
     * @param $column
     * @param $order
     */
    protected function order($column, $order = BaseEngine::ORDER_ASC)
    {
        $this->orderColumn = $column;
        $this->orderDirection = $order;
    }

    /**
     * @param $value
     */
    protected function search($value)
    {
        $this->search = $value;
    }

    /**
     * @param string $columnName
     * @param mixed $value
     */
    protected function searchOnColumn($columnName, $value)
    {
        $this->fieldSearches[] = $columnName;
        $this->columnSearches[] = $value;
    }

    /**
     * @param $value
     */
    protected function skip($value)
    {
        $this->skip = $value;
    }

    /**
     * @param $value
     */
    protected function take($value)
    {
        $this->limit = $value;
    }

    public function getNameByIndex($index)
    {
        $i = 0;
        foreach($this->columns as $name => $col)
        {
            if($index == $i)
            {
                return $name;
            }
            $i++;
        }
    }

    public function getExactWordSearch()
    {
        return $this->exactWordSearch;
    }

    abstract protected function totalCount();
    abstract protected function count();
    abstract protected function internalMake(Collection $columns, array $searchColumns = array());
}
