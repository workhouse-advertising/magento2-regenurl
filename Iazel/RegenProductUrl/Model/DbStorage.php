<?php

namespace Iazel\RegenProductUrl\Model;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\UrlRewrite\Model\Storage\DbStorage as DbStorageBase;

class DbStorage extends DbStorageBase
{
    public function replace($urls)
    {
        return ($urls) ? $this->actuallyReplace($urls) : [];
    }

    protected function actuallyReplace($urls)
    {
        // var_dump(Mysql::REPLACE);
        // die();
        $this->connection->beginTransaction();
        try {
            $data = [];
            foreach ($urls as $url) {
                $data[] = $url->toArray();
            }
            // $this->insertMultiple($data);
            $this->replaceMultiple($this->resource->getTableName(self::TABLE_NAME), $data);
            // $this->connection->insertMultiple($this->resource->getTableName(self::TABLE_NAME), $data);
            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
        return $urls;
    }

    /**
     * NOTE: This crap was adapted from \Magento\Framework\DB\Adapter\Pdo\Mysql so don't blame me 
     *       for how awful it is.
     *
     * @param array $data
     * @return array
     */
    protected function replaceMultiple($table, $data)
    {
        $row = reset($data);
        // support insert syntaxes
        // if (!is_array($row)) {
        //     return $this->insert($table, $data);
        // }

        // validate data array
        $cols = array_keys($row);
        $insertArray = [];
        foreach ($data as $row) {
            $line = [];
            if (array_diff($cols, array_keys($row))) {
                throw new \Zend_Db_Exception("Invalid data for insert. Array keys aren't the same for all rows.");
            }
            foreach ($cols as $field) {
                $line[] = $row[$field];
            }
            $insertArray[] = $line;
        }
        unset($row);

        return $this->connection->insertArray($table, $cols, $insertArray, Mysql::REPLACE);
    }

    
}