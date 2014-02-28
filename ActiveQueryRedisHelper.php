<?php

namespace sitkoru\cache\ar;

use common\components\cache\RedisHelper;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * Class ActiveQueryRedisHelper
 *
 * @package sitkoru\cache\ar
 */
class ActiveQueryRedisHelper extends RedisHelper
{
    /**
     * @param ActiveRecord $model
     */
    public static function dropCaches($model)
    {
        $depended = self::getDependedCaches($model);
        if (count($depended)) {
            foreach ($depended as $cacheKey) {
                \Yii::$app->cache->delete($cacheKey);
            }
            self::deleteCachesFromTable($depended);
        }
    }

    /**
     * @param  $className
     * @param  $condition
     * @param  $params
     */
    public static function dropCachesForCondition($className, $condition, $params)
    {
        list($pkName, $results) = self::getModelsToDelete($className, $condition, $params);
        if ($results) {
            $model = new $className;
            foreach ($results as $row) {
                $model->$pkName = $row[$pkName];
                self::dropCaches($model);
            }
        }
    }

    /**
     * @param ActiveRecord $model
     *
     * @return array
     */
    public static function getDependedCaches(ActiveRecord $model)
    {
        $keys = [];
        $caches = self::getCachesTable();

        $tableName = $model->tableName();

        $pk = $model->getPrimaryKey(false);
        if (isset($caches[$tableName]["pk_" . $pk])) {
            foreach ($caches[$tableName]["pk_" . $pk] as $cacheKey) {
                $keys[] = $cacheKey;
            }
        }
        if (isset($caches[$tableName])) {
            $keys = self::getEventsKeys($caches, $tableName, $model, $keys);
        }

        return $keys;
    }

    /**
     * @param $keys
     */
    public static function deleteCachesFromTable($keys)
    {
        $caches = self::getCachesTable();
        foreach ($caches as $table => $entries) {
            foreach ($entries as $i => $entry) {
                foreach ($entry as $j => $key) {
                    if (in_array($key, $keys)) {
                        unset($caches[$table][$i][$j]);
                    }
                }
                if (count($caches[$table][$i]) == 0) {
                    unset($caches[$table][$i]);
                }
            }
            if (count($caches[$table]) == 0) {
                unset($caches[$table]);
            }
        }
        self::updateCachesTable($caches);
    }

    /**
     * @param $singleModel
     * @param $event
     * @param $values
     * @param $keys
     *
     * @return array
     */
    public static function getKeysForCreateEvent($singleModel, $event, $values, $keys)
    {
        if ($singleModel->isNewRecord) {
            if (isset($event[2]) && isset($event[3])) {
                $param = $event[2];
                $value = $event[3];
                if (isset($singleModel->$param) && $singleModel->$param == $value) {
                    foreach ($values as $value) {
                        $keys[] = $value;
                    }
                }
            } else {
                foreach ($values as $value) {
                    $keys[] = $value;
                }
            }
        }

        return $keys;
    }

    /**
     * @param $caches
     * @param $className
     * @param $singleModel
     * @param $keys
     *
     * @return array
     */
    public static function getEventsKeys($caches, $className, $singleModel, $keys)
    {
        foreach ($caches[$className] as $key => $values) {
            if (stripos($key, "event_") === 0) {
                $event = explode("_", $key);
                switch ($event[1]) {
                    case "create":
                        $keys = self::getKeysForCreateEvent($singleModel, $event, $values, $keys);
                        break;
                }
            }
        }

        return $keys;
    }

    /**
     * @param $key
     * @param $dropConditions
     * @param $caches
     * @param $modelName
     *
     * @return mixed
     */
    public static function insertKeysForConditions($key, $dropConditions, $caches, $modelName)
    {
        foreach ($dropConditions as $condition) {
            $entryKey = "event_" . $condition['event'];
            if ($condition['param']) {
                $entryKey .= "_" . $condition['param'] . "_" . $condition['value'];
            }
            if (!isset($caches[$modelName][$entryKey])) {
                $caches[$modelName][$entryKey] = [];
            }
            $caches[$modelName][$entryKey][] = $key;
        }

        return $caches;
    }

    /**
     * @param $key
     * @param $data
     * @param $indexes
     * @param $dropConditions
     */
    public static function insertInCache($key, $data, $indexes, $dropConditions)
    {
        $result = \Yii::$app->cache->set($key, $data);
        $caches = self::getCachesTable();
        if ($result) {
            foreach ($indexes as $modelName => $keys) {
                if (!isset($caches[$modelName])) {
                    $caches[$modelName] = [];
                }
                foreach ($keys as $pk) {
                    if (!isset($caches[$modelName]["pk_" . $pk])) {
                        $caches[$modelName]["pk_" . $pk] = [];
                    }
                    if (!in_array($key, $caches[$modelName]["pk_" . $pk])) {
                        $caches[$modelName]["pk_" . $pk][] = $key;
                    }
                }
                if (count($dropConditions) > 0) {
                    $caches = self::insertKeysForConditions($key, $dropConditions, $caches, $modelName);
                }
            }
        }
        self::setCachesTable($caches);
    }

    /**
     * @param $className
     * @param $condition
     * @param $params
     *
     * @return array
     */
    protected static function getModelsToDelete($className, $condition, $params)
    {
        /**
         * @var ActiveRecord $className
         */
        $pkName = $className::primaryKey()[0];
        $query = new Query();
        $results = $query->select($pkName)->from($className::tableName())->where($condition, $params)->createCommand(
        )->queryAll();
        return array($pkName, $results);
    }
}
