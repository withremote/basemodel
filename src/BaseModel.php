<?php

/**
 * Base Model
 *
 * @package         BaseModel
 * @subpackage      Models
 * @category        Models
 * @author          Nate Nolting <me@natenolting.com>
 * @link            http://www.natenolting.com
 *
 */

namespace Withremote\BaseModel;

class BaseModel {

    /** @var \PDO  */
    protected $database;

    /** @var string table name */
    protected $table;

    /** @var  string Primary Key */
    protected $primaryKey;

    public function __construct(\PDO $dbCon)
    {
        $this->database = $dbCon;
        $this->database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Return table name
     *
     * @return string
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * return Primary Key
     *
     * @return string
     */
    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    public function getResults($query, array $params)
    {
        $results = $this->database->prepare($query);
        $results->execute($params);
        return $this->fetchAll($results);

    }

    /**
     *
     * Get row
     *
     * @param       $query
     * @param array $params
     *
     * @return array|bool
     */
    public function getRow($query, array $params)
    {
        $results = $this->database->prepare($query);
        $results->execute($params);
        return $this->fetch($results);
    }

    /**
     * Fetch results
     *
     * @param \PDOStatement|bool $results
     *
     * @return array
     */
    protected function fetchAll(\PDOStatement $results)
    {
        return $results->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * Fetch row
     *
     * @param \PDOStatement $results
     *
     * @return object|bool
     */
    protected function fetch(\PDOStatement $results)
    {
        return $results->fetch(\PDO::FETCH_OBJ);
    }

    /**
     * Get by primary key
     *
     * @param $pk
     *
     * @throws \PDOException
     * @return object|bool
     */
    public function get($pk)
    {
        if (strtolower($this->getPrimaryKey()) === 'id' && !ctype_digit($pk)) {
            throw new \PDOException($this->getPrimaryKey() .' must be a numerical value');
        }
        $get = $this->getBy($this->getPrimaryKey(), array($pk), false);
        if($get) {
            return $get;
        } else {
            throw new \PDOException('Record not found');
        }

    }

    /**
     * Alias of insert method
     *
     * @param array $insert
     *
     * @return bool|string
     */
    public function create(array $insert)
    {
        return $this->insert($insert);
    }

    /**
     * Insert new record into database
     *
     * @param array $insert
     *
     * @throws \PDOException
     * @return bool|string
     */
    public function insert(array $insert)
    {

        foreach ($insert as $key => $val) {
            if (is_array($val)) {
                $this->insert($val);
                continue;
            }
        }

        /**
         * Filter columns
         */
        $filteredInsert = $this->filterColumns($insert);
        if (array_key_exists($this->getPrimaryKey(), $filteredInsert)) {
            unset($filteredInsert[$this->getPrimaryKey()]);
        }

        if (empty($filteredInsert)) {
            throw new \PDOException('No valid columns found for insert');
        }

        $tableColumns = $this->getTableColumns();
        $query = "INSERT INTO `" . $this->getTable() . "` (";
        $parameters = array();
        foreach ($tableColumns as $column) {
            switch ($column) {

                case ($this->getPrimaryKey()):
                    break;
                default:
                    $query .= '`'.$column . '`,';
                    break;
            }
        }

        $query = rtrim($query, ',') . ') VALUES (';

        foreach ($tableColumns as $column) {

            switch ($column) {
                case($this->getPrimaryKey()):
                    break;
                default:
                    $query .= ':' . $column . ',';
                    if (array_key_exists($column, $filteredInsert)) {
                        $parameters[':' . $column] = $filteredInsert[$column];
                    } else {
                        $parameters[':' . $column] = '';
                    }
                    break;
            }
        }

        $query = rtrim($query, ',') . ')';

        $prepare = $this->database->prepare($query);

        try {
            $this->database->beginTransaction();

            $prepare->execute($parameters);
            $insertId = $this->database->lastInsertId();
            $this->database->commit();

            return $insertId;

        } catch (\PDOException $ex) {
           throw new \PDOException($ex->getMessage());

        }


    }

    /**
     * Get table columns
     * @return array
     */
    public function getTableColumns()
    {
        $columns = $this->database->prepare("DESCRIBE " . $this->getTable());
        $columns->execute();
        /** @var array $columnNames list of columns */
        return $columns->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Delete a record
     *
     * @param $id
     *
     * @return bool
     */
    public function delete($id)
    {
        $id = intval($id);
        if (!$this->get($id)) {
            return false;
        }
        $sql = "DELETE FROM ".$this->getTable() . " WHERE ". $this->getPrimaryKey() . " = :pk";
        $stmt = $this->database->prepare($sql);
        $stmt->bindParam(':pk', $id, \PDO::PARAM_INT);
        return $stmt->execute();

    }

    /**
     * Get by query string
     *
     * @param      $string
     * @param      $parameters
     * @param bool $all whether to get all results or just one row
     *
     * @return mixed
     */
    public function getByQuery($string, array $parameters, $all = true)
    {
        $query = "SELECT * FROM `" . $this->getTable() . "` WHERE " . $string;

        if ($all) {
            $get = $this->getResults($query, $parameters);
        } else {
            $get = $this->getRow($query, $parameters);
        }

        return $get;

    }

    /**
     * Get by column
     *
     * @param      $column
     * @param      $parameter
     * @param bool $all
     *
     * @return array|bool|object
     * @throws \PDOException
     */
    public function getBy($column, array $parameter, $all = true)
    {

        if (!in_array($column, $this->getTableColumns())) {
            throw new \PDOException('Unknown column '. $column);
        }

        if ($column === $this->getPrimaryKey() && strtolower($this->getPrimaryKey()) === 'id' && !ctype_digit($parameter[$this->getPrimaryKey()])) {
            return $this->get($parameter);
        }

        return $this->getByQuery($column ." = ?", $parameter, $all);


    }

    /**
     * Update a row by primary key
     *
     * @param       $id
     * @param array $params
     *
     * @throws \PDOException
     * @return bool
     */
    public function update($id, array $params)
    {
        /**
         * Filter the incoming columns
         */
        $filterParams = $this->filterColumns($params);
        if (array_key_exists($this->getPrimaryKey(), $filterParams)) {
            unset($filterParams[$this->getPrimaryKey()]);
        }

        /**
         * If no columns are set then throw an exception
         */
        if (empty($filterParams)) {
            throw new \PDOException('No valid columns set for update');
        }

        $query = "UPDATE ".$this->getTable() . " SET ";
        foreach (array_keys($filterParams) as $paramKey) {
            $query .= '`'.$paramKey .'` = ?, ';
        }

        $query = rtrim($query, ', ') . ' WHERE '.$this->getPrimaryKey() .' = ?';
        $prep = $this->database->prepare($query);
        return $prep->execute(array_merge(array_values($filterParams), array($id)));

    }

    /**
     * Get all rows
     *
     * @return array
     */
    public function getAll()
    {
        $results = $this->database->prepare("SELECT * FROM `".$this->getTable()."`");
        $results->execute();
        return $this->fetchAll($results);
    }

    /**
     * Run a basic query
     *
     * @param       $query
     * @param array $params
     *
     * @return bool
     */
    public function query($query, array $params)
    {
        $do =$this->database->prepare($query);
        return $do->execute($params);
    }

    /**
     * Crate mysql time stamp
     *
     * @return bool|string
     */
    protected function timeStamp()
    {
        return date("Y-m-d H:i:s");

    }

    public function getTimeStamp()
    {
        return $this->timeStamp();
    }

    /**
     * Filter columns by
     *
     * @param array $columns
     *
     * @return array
     */
    protected function filterColumns(array $columns)
    {
        $output = array();
        foreach ($columns as $key => $val) {
            if (in_array($key, $this->getTableColumns())) {
                $output[$key] = $val;
            }
        }

        return $output;

    }

}