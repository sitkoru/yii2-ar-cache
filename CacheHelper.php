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

    public static function loadScript($path)
    {
        $script = file_get_contents($path);

        return static::getRedis()->executeCommand('SCRIPT', ['load', $script]);
    }

    public static function scriptExists($sha)
    {
        return reset(static::getRedis()->executeCommand('SCRIPT', ['exists', $sha]));
    }

    public static function evalSHA($sha, $args, $numKeys)
    {
        return static::getRedis()->executeCommand('EVALSHA', [$sha, $args, $numKeys]);
    }

    public static function get($key)
    {
        $res = static::getRedis()->executeCommand('get', [$key]);

        return $res !== false ? unserialize(zlib_decode($res)) : $res;
    }
}
