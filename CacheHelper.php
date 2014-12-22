<?php

namespace sitkoru\cache\ar;

use yii\redis\Connection;

/**
 * Class CacheHelper
 *
 * @package common\components\cache
 */
class CacheHelper
{
    /**
     * @return Connection
     */
    public static function getRedis()
    {
        return \Yii::$app->cache->redis;
    }

    public static function addToSet($setKey, $member)
    {
        return static::getRedis()->executeCommand("SADD", [$setKey, $member]);
    }

    public static function getSetMembers($setKey)
    {
        return static::getRedis()->executeCommand("SMEMBERS", [$setKey]);
    }

    public static function removeFromSet($setKey, $member)
    {
        return static::getRedis()->executeCommand("SREM", [$setKey, $member]);
    }

    public static function addToList($listKey, $member)
    {
        return static::getRedis()->executeCommand("LPUSH", [$listKey, $member]);
    }

    public static function getListMembers($listKey, $start = 0, $length = -1)
    {
        return static::getRedis()->executeCommand("LRANGE", [$listKey, $start, $length]);
    }

    public static function getListLength($listKey)
    {
        return static::getRedis()->executeCommand("LLEN", [$listKey]);
    }

    public static function deleteList($listKey)
    {
        return static::getRedis()->executeCommand("DEL", [$listKey]);
    }

    public static function increment($key)
    {
        return static::getRedis()->executeCommand("INCR", [$key]);
    }
}
