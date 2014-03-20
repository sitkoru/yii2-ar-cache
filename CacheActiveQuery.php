<?php

namespace sitkoru\cache\ar;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Command;

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
        $key = $this->generateCacheKey($command, 'all');
        /**
         * @var ActiveRecord[] $fromCache
         */
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {
            foreach ($fromCache as $i => $model) {
                if ($model instanceof ActiveRecord) {
                    $model->afterFind();
                    $fromCache[$i] = $model;
                }

            }

            return $fromCache;
        } else {
            $models = parent::all($db);
            if ($models) {
                if (!$this->noCache) {
                    $this->insertInCacheAll($key, $models);
                }

                return $models;
            } else {
                return [];
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function one($db = null)
    {
        $command = $this->createCommand($db);
        $key = $this->generateCacheKey($command, 'one');
        /**
         * @var ActiveRecord $fromCache
         */
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {
            if ($fromCache instanceof ActiveRecord) {
                $fromCache->afterFind();
            }

            return $fromCache;
        } else {
            $model = parent::one();
            if ($model && $model instanceof ActiveRecord) {
                if (!$this->noCache) {
                    $this->insertInCacheOne($key, $model);
                }

                return $model;
            } else {
                return null;
            }
        }
    }

    /**
     * @param Command $command
     *
     * @param         $mode
     *
     * @return string
     */
    private function generateCacheKey($command, $mode)
    {
        $key = $mode;
        $key .= strtolower($this->modelClass);
        $key .= $command->rawSql;
        if (count($this->where) == 0 && count($this->dropConditions) == 0) {
            $this->dropCacheOn('create');
        }
        //pagination
        if ($this->limit > 0) {
            $key .= "limit" . $this->limit;
        }
        if ($this->offset > 0) {
            $key .= "offset" . $this->offset;
        }

        return md5($key);
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
        $indexes = [
            $class::tableName() => [
                $model->primaryKey
            ]
        ];
        $toCache = clone $model;
        $toCache->fromCache = true;
        ActiveQueryCacheHelper::insertInCache($key, $toCache, $indexes, $this->dropConditions);

        return true;
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
        $toCache = $models;
        foreach ($toCache as $index => $model) {
            $mToCache = clone $model;
            $mToCache->fromCache = true;
            $toCache[$index] = $mToCache;
            $indexes[$class::tableName()][] = $mToCache->primaryKey;
        }

        ActiveQueryCacheHelper::insertInCache($key, $toCache, $indexes, $this->dropConditions);

        return true;
    }

    /**
     * Sets the [[_dropConditions]] property.
     *
     * @param         $event
     * @param bool    $param
     * @param boolean $value whether to return the query results in terms of arrays instead of Active Records.
     *
     * @return CacheActiveQuery the query object itself
     */
    public function dropCacheOn($event, $param = false, $value = false)
    {
        $this->dropConditions[] = [
            'event' => $event,
            'param' => $param,
            'value' => $value
        ];

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

    public function asArray($value = true)
    {
        $this->noCache = true;
        return parent::asArray($value);
    }
}
