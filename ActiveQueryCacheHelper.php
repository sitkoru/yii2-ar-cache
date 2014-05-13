<?php

namespace sitkoru\cache\ar;

use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * Class ActiveQueryCacheHelper
 *
 * @package sitkoru\cache\ar
 */
class ActiveQueryCacheHelper extends CacheHelper
{
    /**
     * @param ActiveRecord $model
     */
    public static function dropCaches($model)
    {
        \Yii::info(
            "Drop caches. Look depended caches for " . $model::className() . " " . json_encode($model->attributes),
            'cache'
        );
        $depended = self::getDependedCaches($model);
        if (count($depended)) {
            foreach ($depended as $cacheKey) {
                \Yii::info(
                    "Drop cache " . $cacheKey['key'],
                    'cache'
                );
                \Yii::$app->cache->delete($cacheKey['key']);
                CacheHelper::getRedis()->executeCommand("SREM", [$cacheKey['setKey'], $cacheKey['member']]);
            }
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

        $tableName = $model->tableName();

        $pk = $model->getPrimaryKey(false);

        $setKey = $tableName . "_" . $pk;
        $setKeys = CacheHelper::getRedis()->executeCommand("SMEMBERS", [$setKey]);
        if ($setKeys) {
            foreach ($setKeys as $member) {
                $keys[] = [
                    'setKey' => $setKey,
                    'key'    => $member,
                    'member' => $member
                ];
            }
        }

        if (isset($caches[$tableName])) {
            $keys = self::getEventsKeys($caches, $tableName, $model, $keys);
        }

        return $keys;
    }

    /**
     * @param $singleModel
     * @param $keys
     *
     * @return array
     */
    public static function getKeysForCreateEvent(ActiveRecord $singleModel, $keys)
    {

        $setName = $singleModel::tableName() . "_create";
        $setMembers = CacheHelper::getRedis()->executeCommand("SMEMBERS", [$setName]);
        if ($setMembers) {
            foreach ($setMembers as $member) {
                $event = json_decode($member, true);
                if (isset($event['param']) && isset($event['value'])) {
                    $param = $event['param'];
                    $value = $event['value'];
                    if (isset($singleModel->$param) && $singleModel->$param == $value) {
                        $keys[] = [
                            'setKey' => $setName,
                            'key'    => $event['key'],
                            'member' => $member

                        ];
                    }
                } else {
                    $keys[] = [
                        'setKey' => $setName,
                        'key'    => $event['key'],
                        'member' => $member

                    ];
                }
            }
        }

        return $keys;
    }

    /**
     * @param ActiveRecord $singleModel
     * @param array        $keys
     *
     * @return array
     */
    public static function getKeysForUpdateEvent($singleModel, $keys)
    {

        $setName = $singleModel::tableName() . "_create";
        $setMembers = CacheHelper::getRedis()->executeCommand("SMEMBERS", [$setName]);
        if ($setMembers) {
            foreach ($setMembers as $member) {
                $event = json_decode($member, true);
                if (count($event['conditions']) > 0) {
                    $match = true;
                    foreach ($event['conditions'] as $param => $value) {
                        if (!isset($singleModel->$param) || $singleModel->$param != $value) {
                            $match = false;
                        }
                    }
                    if ($match) {
                        $keys[] = [
                            'setKey' => $setName,
                            'key'    => $event['key'],
                            'member' => $member

                        ];
                    }
                } else {
                    $keys[] = [
                        'setKey' => $setName,
                        'key'    => $event['key'],
                        'member' => $member

                    ];
                }
            }
        }

        return $keys;
    }

    /**
     * @param $singleModel
     * @param $keys
     *
     * @return array
     */
    public static function getEventsKeys($singleModel, $keys)
    {
        $keys = self::getKeysForCreateEvent($singleModel, $keys);
        $keys = self::getKeysForUpdateEvent($singleModel, $keys);

        return $keys;
    }

    /**
     * @param $key
     * @param $data
     * @param $indexes
     * @param $dropConditions
     */
    public static function insertInCache($key, $data, $indexes, $dropConditions)
    {
        \Yii::info(
            "Insert in cache for " . $key,
            'cache'
        );
        $result = \Yii::$app->cache->set($key, $data);

        if ($result) {
            foreach ($indexes as $modelName => $keys) {
                foreach ($keys as $pk) {
                    CacheHelper::getRedis()->executeCommand("SADD", [$modelName . "_" . $pk, $key]);
                }
                foreach ($dropConditions as $event) {
                    $setKey = "";
                    switch ($event['type']) {
                        case 'create':
                            $setKey = $modelName . '_create';
                            break;
                        case 'update':
                            $setKey = $modelName . '_update';
                            break;
                        default:
                            continue;
                            break;
                    }
                    $event['key'] = $key;
                    CacheHelper::getRedis()->executeCommand("SADD", [$setKey, json_encode($event)]);
                }
            }
        }


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
