<?php

/**
 * Created by PhpStorm.
 * User: gelidan
 * Date: 2017. 03. 17.
 * Time: 19:06
 */
class PDODatabase {
    public $error;
    public $errorCode;
    public $stmt;
    public $mode;
    private $host;
    private $user;
    private $pass;
    private $dbname;
    private $dbh;

    public function __construct($mode = 5, $conn = 'local') {
        // Set credentials
        if ($conn == 'local') {
            $this->host = DB_HOST;
            $this->user = DB_USER;
            $this->pass = DB_PASS;
            $this->dbname = DB_SHEMA;
        } else {
            $this->host = DB_HOST_REMOTE;
            $this->user = DB_USER_REMOTE;
            $this->pass = DB_PASS_REMOTE;
            $this->dbname = DB_SHEMA_REMOTE;
        }
        // Set DSN
        $dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->dbname . ';charset=utf8';
        $this->mode = $mode;
        // Set options
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
        );
        // Create a new PDO instanace
        try {
            $this->dbh = new PDO($dsn, $this->user, $this->pass, $options);
        } // Catch any errors
        catch (PDOException $e) {
            $this->error = $e->getMessage();
        }

    }

    public function rawQuery($query) {
        $this->stmt = $this->dbh->query($query);
    }

    public function saveDatas($array, $idfield = 'id') {

        if ($array['table'] != '') {
            $this->query("SHOW COLUMNS FROM " . $array['table']);
            $fields = $this->resultset();
        }
        if ($array[$idfield] != '') {
            $check = "SELECT {$idfield} FROM " . $array['table'] . " WHERE {$idfield} = :{$idfield}";
            $this->query($check);
            $this->bind(":{$idfield}", $array[$idfield]);
            $result = $this->single();
            if ($result) {
                $sql = "UPDATE " . $array['table'] . " SET ";
                foreach ($fields as $field) {
                    if ($field->Field != NULL && $field->Field != $idfield) {
                        if (array_key_exists($field->Field, $array)) {
                            if ($array[$field->Field] == '') {
                                $array[$field->Field] = null;
                            }
                            $sql .= '`' . $field->Field . '` = :' . $field->Field . ', ';
                            $matches[':' . $field->Field] = $array[$field->Field];
                        }
                    }
                }
                $matches[':' . $idfield] = $array[$idfield];
                $sql = substr($sql, 0, -2);
                $sql .= " WHERE {$idfield} = :{$idfield};";
                $this->query($sql);
                $this->bindMore($matches);
                try {
                    $this->execute();
                    return true;
                } catch (PDOException $e) {
                    $this->error = $e->getMessage();
                    $this->errorCode = $e->getCode();
                    return false;
                }
            } else {
                return false;
            }
        } else {
            $sql = "INSERT INTO " . $array['table'] . " ";
            if (is_null($fields)) {
                die();
            }
            foreach ($fields as $field) {
                if ($field->Field != NULL) {
                    if (array_key_exists($field->Field, $array)) {
                        if ($array[$field->Field] != '') {
                            $columns[] = $field->Field;
                            $matches[':' . $field->Field] = $array[$field->Field];
                            $values .= ":" . $field->Field . ", ";
                        }
                    }
                }
            }
            $values = substr($values, 0, -2);
            $sql .= '(' . implode(", ", $columns) . ')';
            $sql .= ' VALUES (' . $values . ');';
            $this->query($sql);
            $this->bindMore($matches);
            try {
                $this->execute();
                return true;
            } catch (PDOException $e) {
                $this->error = $e->getMessage();
                $this->errorCode = $e->getCode();
                return false;
            }
        }
    }

    public function query($query) {
        $this->stmt = $this->dbh->prepare($query);
    }

    public function resultset() {
        $this->execute();
        return $this->stmt->fetchAll($this->mode);
    }

    public function execute() {
        try {
            return $this->stmt->execute();
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->errorCode = $e->getCode();
            return false;
        }
    }

    public function bind($param, $value, $type = null) {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->stmt->bindValue($param, $value, $type);
    }

    public function single() {
        $this->execute();
        return $this->stmt->fetch($this->mode);
    }

    public function bindMore($array) {
        foreach ($array as $param => $value) {
            $this->bind($param, $value);
        }
    }

    public function getRowWhere($tableName, $whereArray = null, $columns = null, $sortColumns = null, $sortAscending = true, $limit = null, $forceSingle = false, $groupby = null) {
        $sql = "SELECT ";
        if (!is_null($columns)) {
            $sql .= implode(', ', $columns);
        } else {
            $sql .= "*";
        }
        $sql .= " FROM " . $tableName . "";
        if (is_array($whereArray)) {
            $sql .= ' WHERE ';
            foreach ($whereArray as $key => $value) {
                $columnsArray[] = '' . $key . ' = :' . str_replace('.', '', $key);
                $matches[':' . str_replace('.', '', $key)] = $value;
            }
            $sql .= implode(' AND ', $columnsArray);
        }
        if (!is_null($groupby)) {
            $sql .= " GROUP BY :groupby ";
            $matches[':groupby'] = $groupby;
        }
        if (!is_null($sortColumns)) {
            $sql .= " ORDER BY :sort " . ($sortAscending ? "ASC" : "DESC");
            $matches[':sort'] = implode(', ', $sortColumns);
        }
        if (!is_null($limit)) {
            $sql .= " LIMIT :limit";
            $matches[':limit'] =$limit;
        }
        $this->query($sql);
        $this->bindMore($matches);
        try {
            $this->execute();
            $count = $this->stmt->rowCount();
            if ($forceSingle === true) {
                return $this->stmt->fetch($this->mode);
            } else {
                if ($count == 1) {
                    return $this->stmt->fetch($this->mode);
                } else {
                    return $this->stmt->fetchAll($this->mode);
                }
            }
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->errorCode = $e->getCode();
            return false;
        }
    }

    public function getMaxId($table, $id = 'id') {
        $sql = "SELECT :id FROM {$table} ORDER BY :id DESC LIMIT 0, 1";
        $this->query($sql);
        $this->bind(':id', $id);
        $row = $this->single();
        switch ($this->mode) {
            case '2':
                return $row[$id];
                break;
            default:
                return $row->$id;
                break;
        }

    }

    public function getRow($table, $id) {
        $sql = "SELECT * FROM {$table} WHERE id = :id;";
        $this->query($sql);
        $this->bind(':id', $id);
        return $this->single();
    }

    public function getAllRows($table) {
        $sql = "SELECT * FROM {$table};";
        $this->query($sql);
        return $this->resultset();
    }

    public function deleteRow($table, $id) {
        $sql = "DELETE FROM {$table} WHERE id = :id ";
        $this->query($sql);
        $this->bind(':id', $id);
        $this->execute();
    }

    public function rowCount() {
        return $this->stmt->rowCount();
    }

    public function lastInsertId() {
        return $this->dbh->lastInsertId();
    }

    public function beginTransaction() {
        return $this->dbh->beginTransaction();
    }

    public function endTransaction() {
        return $this->dbh->commit();
    }

    public function cancelTransaction() {
        return $this->dbh->rollBack();
    }

    public function getErrors() {
        $errors['code'] = $this->errorCode;
        $errors['message'] = $this->error;
        return $errors;
    }
}