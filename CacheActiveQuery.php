<?php

namespace sitkoru\cache\ar;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Class CacheActiveQuery
 *
 * @package sitkoru\cache\ar
 */
class CacheActiveQuery extends ActiveQuery
{


    private $dropConditions = [];
    private $disableCache = false;

    private function isCacheEnabled()
    {
        if ($this->disableCache || (defined('DISABLED_AR_CACHE') && DISABLED_AR_CACHE)) {
            return false;
        }
        return true;
    }

    /**
     * @inheritdoc
     */
    public function all($db = null)
    {
        ActiveQueryCacheHelper::initialize();

        if (!$this->disableCache) {
            $command = $this->createCommand($db);
            $rawSql = $command->rawSql;
            $key = $this->generateCacheKey($rawSql, 'all');

            /**
             * @var ActiveRecord[] $fromCache
             */
            $fromCache = CacheHelper::get($key);
            if ($fromCache) {

                $resultFromCache = [];
                foreach ($fromCache as $i => $model) {
                    $key = $i;
                    if ($model instanceof ActiveRecord) {
                        //restore key
                        $model->afterFind();
                    }
                    if (is_string($this->indexBy)) {
                        $key = $model instanceof ActiveRecord ? $model->{$this->indexBy} : $model[$this->indexBy];
                    }
                    $resultFromCache[$key] = $model;
                }

                return $resultFromCache;
            } else {
                $models = parent::all($db);
                if ($models) {
                    $this->insertInCacheAll($key, $models);
                }

                return $models;
            }
        } else {
            return parent::all($db);
        }

    }

    /**
     * @inheritdoc
     */
    public function one($db = null)
    {
        ActiveQueryCacheHelper::initialize();
        if (!$this->disableCache) {
            $command = $this->createCommand($db);
            $key = $this->generateCacheKey($command->rawSql, 'one');
            /**
             * @var ActiveRecord $fromCache
             */
            $fromCache = CacheHelper::get($key);
            if ($fromCache) {
                if (is_string($fromCache) && $fromCache === 'null') {
                    $fromCache = null;
                } else {
                    if ($fromCache instanceof ActiveRecord) {
                        //restore key
                        $fromCache->afterFind();
                    }
                }

                return $fromCache;
            } else {
                $model = parent::one();
                if ($model) {
                    $this->insertInCacheOne($key, $model);
                }
                if ($model && $model instanceof ActiveRecord) {
                    return $model;
                } else {
                    return null;
                }
            }
        } else {
            return parent::one();
        }
    }

    /**
     * @param bool $value
     * @return static
     */
    public function asArray($value = true)
    {
        if ($value) {
            $this->disableCache = true;
        }

        return parent::asArray($value);
    }

    /**
     * @param                $key
     * @param ActiveRecord[] $models
     *
     * @return bool
     */
    private function insertInCacheAll($key, $models)
    {
        $toCache = [];
        if ($models) {
            foreach ($models as $k => $model) {
                $copy = clone $model;
                $copy->fromCache = true;
                $toCache[$k] = $copy;
            }
        }
        $this->insertInCache($key, $toCache);

        return true;
    }

    /**
     * @param              $key
     * @param ActiveRecord $model
     *
     * @return bool
     */
    private function insertInCacheOne($key, $model)
    {
        /** @var $class ActiveRecord */
        $copy = clone $model;
        $copy->fromCache = true;
        $this->insertInCache($key, $copy);

        return true;
    }

    private function insertInCache($key, $toCache)
    {
        $conditions = $this->getDropConditions();
        $args = [
            $key,
            zlib_encode(serialize($toCache), ZLIB_ENCODING_DEFLATE),
            json_encode($conditions),
            ActiveQueryCacheHelper::getTTL()
        ];
        CacheHelper::evalSHA(ActiveQueryCacheHelper::$shaCache, $args, 1);
    }

    /**
     * @param string  $sql
     *
     * @param         $mode
     *
     * @return string
     */
    private function generateCacheKey($sql, $mode)
    {
        $key = $mode;
        $key .= strtolower($this->modelClass);
        $key .= $sql;
        if (count($this->where) === 0 && count($this->dropConditions) === 0) {
            $this->dropCacheOnCreate();
        }
        //pagination
        if ($this->limit > 0) {
            $key .= 'limit' . $this->limit;
        }
        if ($this->offset > 0) {
            $key .= 'offset' . $this->offset;
        }

        return 'q:' . md5($key);
    }

    /**
     * @param string|null  $param
     * @param string|array $value
     *
     * @return self
     */
    public function dropCacheOnCreate($param = null, $value = null)
    {
        /**
         * @var ActiveRecord $className
         */
        $className = $this->modelClass;
        $tableName = $className::tableName();
        if (!array_key_exists($tableName, $this->dropConditions)) {
            $this->dropConditions[$tableName] = [];
        }
        if ($param) {
            if (!array_key_exists($param, $this->dropConditions[$tableName])) {
                $this->dropConditions[$tableName][$param] = [];
            }
            $this->dropConditions[$tableName][$param][] = [$param, $value];
        } else {
            $this->dropConditions[$tableName]['create'] = true;
        }


        return $this;
    }

    /**
     * @param string     $param
     * @param null|array $condition
     *
     * @return self
     */
    public function dropCacheOnUpdate($param, $condition = null)
    {
        /**
         * @var ActiveRecord $className
         */
        $className = $this->modelClass;
        $tableName = $className::tableName();
        if (!array_key_exists($tableName, $this->dropConditions)) {
            $this->dropConditions[$tableName] = [];
        }
        if (!array_key_exists($param, $this->dropConditions[$tableName])) {
            $this->dropConditions[$tableName][$param] = [];
        }
        $cond = '*';
        if ($condition) {
            $cond = [];
            $cond['conditions'] = [];
            foreach ($condition as $dep => $value) {
                $cond['conditions'][$dep] = $value;
            }
        }
        $this->dropConditions[$tableName][$param][] = $cond;

        return $this;
    }

    private function getDropConditions()
    {
        $this->fillDropConditions();

        $conditions = [];
        foreach ($this->dropConditions as $tableName => $entries) {
            $table = [$tableName];
            $tableConditions = [];
            foreach ($entries as $column => $values) {
                if ($column === 'create' && $values === true) {
                    $tableConditions[] = [];
                } else {
                    foreach ($values as $value) {
                        if (is_array($value)) {
                            if (array_key_exists('conditions', $value)) {
                                $arr = [];
                                foreach ($value as $key => $val) {
                                    if ($key === 'conditions') {
                                        foreach ($val as $dep => $cond) {
                                            if (is_array($cond)) {
                                                foreach ($cond as $condValue) {
                                                    $arr[] = [$dep, $condValue];
                                                }
                                            } else {
                                                $arr[] = [$dep, $cond];
                                            }

                                        }
                                    } else {
                                        $arr[] = [$column, $val];
                                    }
                                }
                                $tableConditions[] = $arr;
                            } else {
                                if (array_key_exists(1, $value) && is_array($value[1])) {
                                    foreach ($value[1] as $val) {
                                        $tableConditions[] = [[$value[0], $val]];
                                    }
                                } else {
                                    $tableConditions[] = [$value];
                                }
                            }
                        } else {
                            $tableConditions[] = [[$column, $value]];
                        }
                    }
                }
            }
            $table[] = $tableConditions;
            $conditions[] = $table;
        }

        return $conditions;
    }

    /**
     */
    private function fillDropConditions()
    {
        foreach ($this->from as $tableName) {
            if (!array_key_exists($tableName, $this->dropConditions)) {
                $this->dropConditions[$tableName] = [];
            }
            if (count($this->where) !== 0) {
                $where = $this->getParsedWhere();
                foreach ($where as $condition) {
                    $column = $condition[0];
                    $operator = $condition[1];
                    $value = $condition[2];
                    if (in_array(
                        $operator,
                        [
                            'NOT IN',
                            '!=',
                            '>',
                            '<',
                            '>=',
                            '<='
                        ],
                        true
                    )) {
                        continue;
                    }
                    $this->dropConditions[$tableName][$column][] = [$column, $value];

                }
            } elseif (!$this->dropConditions[$tableName]) {
                $this->dropConditions[$tableName]['create'] = true;
            }
        }

        return $this->dropConditions;
    }

    protected function getParsedWhere()
    {
        $parser = new WhereParser(\Yii::$app->db);
        $data = $parser->parse($this->where, $this->params);

        return $data;
    }


    /**
     * @return static
     */
    public function noCache()
    {
        $this->disableCache = true;

        return $this;

    }

    /**
     * @return int
     */
    public function deleteAll()
    {
        /**
         * @var $class ActiveRecord
         */
        $params = [];
        $class = $this->modelClass;

        return $class::deleteAll($this->where, $params);
    }

    /**
     * @return ActiveRecord|null
     */
    public function any()
    {
        $query = clone  $this;
        $result = $query->limit(1)->noCache()->one();
        unset($query);

        return $result;
    }

}
