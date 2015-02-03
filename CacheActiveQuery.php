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
    private $noCache = false;

    /**
     * @inheritdoc
     */
    public function all($db = null)
    {
        $command = $this->createCommand($db);
        $rawSql = $command->rawSql;
        $key = $this->generateCacheKey($rawSql, 'all');
        /**
         * @var ActiveRecord[] $fromCache
         */
        ActiveQueryCacheHelper::log(
            'LA ' . $key
        );
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {

            $resultFromCache = [];
            if ($fromCache == ['null']) {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_EMPTY_ALL, $key, $rawSql);
                ActiveQueryCacheHelper::log(
                    'SEA ' . $key
                );
            } else {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_HIT_ALL, $key, $rawSql);
                ActiveQueryCacheHelper::log(
                    'SA ' . $key
                );
                foreach ($fromCache as $i => $model) {
                    $key = $i;
                    if ($model instanceof ActiveRecord) {
                        //restore key
                        ActiveQueryCacheHelper::insertKeyForPK($model, $key);
                        $model->afterFind();
                    }
                    if (is_string($this->indexBy)) {
                        $key = $model instanceof ActiveRecord ? $model->{$this->indexBy} : $model[$this->indexBy];
                    }
                    $resultFromCache[$key] = $model;
                }
            }

            return $resultFromCache;
        } else {
            ActiveQueryCacheHelper::log(
                'MA ' . $key
            );
            ActiveQueryCacheHelper::profile(
                $this->noCache ? ActiveQueryCacheHelper::PROFILE_RESULT_NO_CACHE : ActiveQueryCacheHelper::PROFILE_RESULT_MISS_ALL,
                $key,
                $rawSql
            );
            $models = parent::all($db);
            if (!$this->noCache) {
                $this->insertInCacheAll($key, $models);
            }

            return $models;
        }
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
        ActiveQueryCacheHelper::log(
            'G ' . $sql . ':  ' . md5($key)
        );

        return md5($key);
    }

    /**
     * @param string|null  $param
     * @param string|array $value
     *
     * @return self
     */
    public function dropCacheOnCreate($param = null, $value = null)
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        foreach ($value as $val) {
            $event = [
                'type'  => 'create',
                'param' => $param,
                'value' => $val
            ];
            $this->dropConditions[] = $event;
        }

        return $this;
    }

    /**
     * @param                $key
     * @param ActiveRecord[] $models
     *
     * @return bool
     */
    private function insertInCacheAll($key, $models)
    {
        /** @var $class ActiveRecord */
        $class = $this->modelClass;
        $indexes = [
            $class::tableName() => [
            ]
        ];
        if ($models) {
            $toCache = $models;
            foreach ($toCache as $index => $model) {
                $mToCache = clone $model;
                $mToCache->fromCache = true;
                $toCache[$index] = $mToCache;
                $pks = $mToCache->getPrimaryKey(true);
                $indexes[$class::tableName()][] = reset($pks);
            }
        } else {
            $toCache = ['null'];
            $indexes[$class::tableName()][] = null;
            $this->generateDropConditionsForEmptyResult();
        }

        ActiveQueryCacheHelper::insertInCache($key, $toCache, $indexes, $this->dropConditions);

        return true;
    }

    /**
     */
    private function generateDropConditionsForEmptyResult()
    {
        $conditions = 0;
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
                $this->dropCacheOnCreate($column, $value);
                $conditions++;
            }
        }
        if ($conditions === 0) {
            $this->dropCacheOnCreate();
        }
    }

    protected function getParsedWhere()
    {
        $parser = new WhereParse(\Yii::$app->db);
        $data = $parser->parse($this->where, $this->params);

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function one($db = null)
    {
        $command = $this->createCommand($db);
        $rawSql = $command->rawSql;
        $key = $this->generateCacheKey($command->rawSql, 'one');
        /**
         * @var ActiveRecord $fromCache
         */
        ActiveQueryCacheHelper::log(
            'LO ' . $key
        );
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {
            if (is_string($fromCache) && $fromCache === 'null') {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_EMPTY_ONE, $key, $rawSql);
                ActiveQueryCacheHelper::log(
                    'SEO ' . $key
                );
                $fromCache = null;
            } else {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_HIT_ONE, $key, $rawSql);
                ActiveQueryCacheHelper::log(
                    'SO ' . $key
                );
                if ($fromCache instanceof ActiveRecord) {
                    //restore key
                    ActiveQueryCacheHelper::insertKeyForPK($fromCache, $key);
                    $fromCache->afterFind();
                }
            }

            return $fromCache;
        } else {
            ActiveQueryCacheHelper::profile(
                $this->noCache ? ActiveQueryCacheHelper::PROFILE_RESULT_NO_CACHE : ActiveQueryCacheHelper::PROFILE_RESULT_MISS_ONE,
                $key,
                $rawSql
            );
            ActiveQueryCacheHelper::log(
                'MO ' . $key
            );
            $model = parent::one();
            if (!$this->noCache) {
                $this->insertInCacheOne($key, $model);
            }
            if ($model && $model instanceof ActiveRecord) {
                return $model;
            } else {
                return null;
            }
        }
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
        $class = $this->modelClass;
        if ($model) {
            $keys = $model->getPrimaryKey(true);
            $pk = reset($keys);
            $indexes = [
                $class::tableName() => [
                    $pk
                ]
            ];
            $toCache = clone $model;
            $toCache->fromCache = true;
        } else {
            $toCache = 'null';
            $indexes[$class::tableName()] = ['null'];
            $this->generateDropConditionsForEmptyResult();
        }
        ActiveQueryCacheHelper::insertInCache($key, $toCache, $indexes, $this->dropConditions);

        return true;
    }

    /**
     * @param string     $param
     * @param null|array $condition
     *
     * @return self
     */
    public function dropCacheOnUpdate($param, $condition = null)
    {
        $event = [
            'type'       => 'update',
            'param'      => $param,
            'conditions' => []
        ];
        if ($condition) {
            foreach ($condition as $param => $value) {
                $event['conditions'] = [$param => $value];
            }
        }
        $this->dropConditions[] = $event;

        return $this;
    }

    /**
     * @return static
     */
    public function noCache()
    {
        $this->noCache = true;

        return $this;

    }

    /**
     * @param bool $value
     * @return static
     */
    public function asArray($value = true)
    {
        if ($value) {
            $this->noCache = true;
        }

        return parent::asArray($value);
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
}
