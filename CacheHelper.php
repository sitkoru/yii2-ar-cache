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
        return self::getRedis()->executeCommand("SADD", [$setKey, $member]);
    }

    public static function getSetMembers($setKey)
    {
        return self::getRedis()->executeCommand("SMEMBERS", [$setKey]);
    }

    public static function removeFromSet($setKey, $member)
    {
        return self::getRedis()->executeCommand("SREM", [$setKey, $member]);
    }

    public static function addToList($listKey, $member)
    {
        return self::getRedis()->executeCommand("LPUSH", [$listKey, $member]);
    }

    public static function getListMembers($listKey, $start = 0, $length = -1)
    {
        return self::getRedis()->executeCommand("LRANGE", [$listKey, $start, $length]);
    }

    public static function getListLength($listKey)
    {
        return self::getRedis()->executeCommand("LLEN", [$listKey]);
    }

    public static function deleteList($listKey)
    {
        return self::getRedis()->executeCommand("DEL", [$listKey]);
    }

    public static function increment($key)
    {
        return self::getRedis()->executeCommand("INCR", [$key]);
    }
}
