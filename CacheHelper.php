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
}
