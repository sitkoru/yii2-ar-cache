<?php

namespace sitkoru\cache\ar;

use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * Class ActiveQueryCacheHelper
 *
 * @package sitkoru\cache\ar
 *
 */
class ActiveQueryCacheHelper extends CacheHelper
{
    private static $logClass;

    public static function log($message)
    {
        if (self::$logClass) {
            $logger = self::$logClass;
            $logger::log($message);
        }
    }

    const PROFILE_RESULT_HIT_ONE = 0;
    const PROFILE_RESULT_HIT_ALL = 1;
    const PROFILE_RESULT_MISS_ONE = 2;
    const PROFILE_RESULT_MISS_ALL = 3;
    const PROFILE_RESULT_DROP_PK = 4;
    const PROFILE_RESULT_DROP_DEPENDENCY = 5;
    const PROFILE_RESULT_NO_CACHE = 6;
    const PROFILE_RESULT_EMPTY_ONE = 7;
    const PROFILE_RESULT_EMPTY_ALL = 8;

    public static $types = [
        self::PROFILE_RESULT_HIT_ONE         => 'HIT ONE',
        self::PROFILE_RESULT_HIT_ALL         => 'HIT ALL',
        self::PROFILE_RESULT_MISS_ONE        => 'MISS ONE',
        self::PROFILE_RESULT_MISS_ALL        => 'MISS ALL',
        self::PROFILE_RESULT_DROP_PK         => 'DROP PK',
        self::PROFILE_RESULT_DROP_DEPENDENCY => 'DROP DEPENDENCY',
        self::PROFILE_RESULT_NO_CACHE        => 'NO CACHE',
        self::PROFILE_RESULT_EMPTY_ONE       => 'EMPTY ONE',
        self::PROFILE_RESULT_EMPTY_ALL       => 'EMPTY ALL'
    ];

    private static $cacheTTL = 7200; //two hours by default

    /**
     * @param $ttl
     */
    public static function setTTL($ttl)
    {
        self::$cacheTTL = intval($ttl);
    }

    /**
     * @return int
     */
    public static function getTTL()
    {
        return self::$cacheTTL;
    }

    /**
     * @param $className
     */
    public static function setLogClass($className)
    {
        self::$logClass = $className;
    }

    /**
     * @return string|null
     */
    public static function getLogClass()
    {
        return self::$logClass;
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
        $pks = $className::primaryKey(true);
        $pkName = reset($pks);
        $query = new Query();
        $results = $query->select($pkName)->from($className::tableName())->where($condition, $params)->createCommand(
        )->queryAll();
        return [$pkName, $results];
    }

    /**
     * @param ActiveRecord $model
     */
    public static function dropCaches($model)
    {
        self::log(
            "LD " . $model::className() . " " . json_encode($model->attributes)
        );
        $depended = self::getDependedCaches($model);
        if (count($depended)) {
            foreach ($depended as $cacheKey) {
                self::log("D " . $cacheKey['key']);
                self::profile(self::PROFILE_RESULT_DROP_DEPENDENCY, $cacheKey['key']);
                \Yii::$app->cache->delete($cacheKey['key']);
                CacheHelper::removeFromSet($cacheKey['setKey'], $cacheKey['member']);
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
        $pks = $model->getPrimaryKey(true);
        $pk = reset($pks);

        $setKey = $tableName . "_" . $pk;
        $setKeys = CacheHelper::getSetMembers($setKey);
        if ($setKeys) {
            foreach ($setKeys as $member) {
                $keys[] = [
                    'setKey' => $setKey,
                    'key'    => $member,
                    'member' => $member
                ];
            }
        }

        $keys = self::getEventsKeys($model, $keys);

        return $keys;
    }

    /**
     * @param ActiveRecordTrait $singleModel
     * @param                   $keys
     *
     * @return array
     */
    public static function getEventsKeys($singleModel, $keys)
    {
        //if ($singleModel->insert) {
        $keys = self::getKeysForCreateEvent($singleModel, $keys);
        //} else {
        $keys = self::getKeysForUpdateEvent($singleModel, $keys);
        // }

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

        list($setMembers, $setName) = self::getEvents($singleModel::tableName(), 'create');
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

    private static function getEvents($tableName, $type)
    {
        $setName = $tableName . "_" . $type;
        $setMembers = CacheHelper::getSetMembers($setName);
        return [$setMembers, $setName];
    }

    /**
     * @param ActiveRecord $singleModel
     * @param array        $keys
     *
     * @return array
     */
    public static function getKeysForUpdateEvent($singleModel, $keys)
    {
        list($setMembers, $setName) = self::getEvents($singleModel::tableName(), 'update');
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

    /***
     * @param      $result
     * @param      $key
     * @param bool $query
     */
    public static function profile($result, $key, $query = false)
    {
        if (defined('ENABLE_CACHE_PROFILE') && ENABLE_CACHE_PROFILE) {
            $entry = json_encode(
                [
                    'date'   => time(),
                    'result' => $result,
                    'key'    => $key,
                    'query'  => $query
                ]
            );
            self::increment('cacheResult' . $result);
            self::addToList("cacheLog", $entry);
        }
    }

    /**
     * @param $key
     * @param $data
     * @param $indexes
     * @param $dropConditions
     */
    public static function insertInCache($key, $data, $indexes, $dropConditions)
    {
        self::log("I " . $key);
        $result = \Yii::$app->cache->set($key, $data, self::$cacheTTL);

        if ($result) {
            foreach ($indexes as $modelName => $keys) {
                foreach ($keys as $pk) {
                    CacheHelper::addToSet($modelName . "_" . $pk, $key);
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
                    self::log("IC " . $setKey . " " . json_encode($event));
                    CacheHelper::addToSet($setKey, json_encode($event));
                }
            }
        }


    }

    /**
     * @param int $count
     * @param int $page
     * @return array
     */
    public static function getProfileRecords($count = 100, $page = 1)
    {
        $records = [];
        $end = $count * $page;
        $start = ($count * ($page - 1));
        $jsonEntries = CacheHelper::getListMembers("cacheLog", $start, $end);
        foreach ($jsonEntries as $entry) {
            $records[] = json_decode($entry, true);
        }
        return $records;
    }

    /**
     * @return array
     */
    public static function getProfileStats()
    {
        $stats = [
            'get'   => 0,
            'hit'   => 0,
            'miss'  => 0,
            'empty' => 0,
        ];
        foreach (self::$types as $key => $typeName) {
            $stats[$key] = self::getRedis()->get('cacheResult' . $key);
            if ($key == self::PROFILE_RESULT_HIT_ALL || $key == self::PROFILE_RESULT_HIT_ONE) {
                $stats['get'] += $stats[$key];
                $stats['hit'] += $stats[$key];
            }
            if ($key == self::PROFILE_RESULT_MISS_ALL || $key == self::PROFILE_RESULT_MISS_ONE) {
                $stats['get'] += $stats[$key];
                $stats['miss'] += $stats[$key];
            }
            if ($key == self::PROFILE_RESULT_EMPTY_ALL || $key == self::PROFILE_RESULT_EMPTY_ONE) {
                $stats['empty'] += $stats[$key];
                $stats['miss'] += $stats[$key];
            }
        }
        return $stats;
    }

    /**
     * @return integer
     */
    public static function getProfileRecordsCount()
    {
        return CacheHelper::getListLength('cacheLog');
    }

    /**
     * @param ActiveRecord $className
     * @param string       $type
     * @param string|null  $param
     * @param string|null  $value
     */
    public static function dropCachesForEvent($className, $type, $param = null, $value = null)
    {
        list($setMembers, $setName) = self::getEvents($className::tableName(), $type);

        if ($setMembers) {
            $keys = [];
            foreach ($setMembers as $member) {
                $event = json_decode($member, true);
                switch ($type) {
                    case 'create':
                        if (isset($event['param']) && isset($event['value'])) {
                            $eventParam = $event['param'];
                            $eventValue = $event['value'];
                            if ($eventParam == $param && $eventValue == $value) {
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
                        break;
                    case 'update':
                        if (isset($event['conditions']) && count($event['conditions']) > 0) {
                            $match = true;
                            foreach ($event['conditions'] as $eventParam => $eventValue) {
                                if (($eventParam && !$param) || $eventParam != $param || $eventValue != $value) {
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
                        break;
                }
            }
            foreach ($keys as $key) {
                self::profile(self::PROFILE_RESULT_DROP_DEPENDENCY, $key['key']);
                \Yii::$app->cache->delete($key['key']);
                CacheHelper::removeFromSet($key['setKey'], $key['member']);
            }
        }

    }
}
