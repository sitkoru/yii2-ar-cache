<?php

namespace sitkoru\cache\ar;

/**
 * Class RedisHelper
 *
 * @package common\components\cache
 */
class RedisHelper
{
    /**
     * @var array
     */
    private static $caches = false;

    /**
     * @param bool $force
     *
     * @return array
     */
    public static function getCachesTable($force = false)
    {
        if ($force || self::$caches == false) {
            $caches = \Yii::$app->cache->get('yii_caches_table');
            if ($caches) {
                self::$caches = json_decode($caches, true);
            } else {
                self::$caches = [];
            }
        }

        return self::extractCachesData();
    }

    /**
     * @return array
     */
    private static function extractCachesData()
    {
        $caches = [];
        if (self::$caches && count(self::$caches) > 0) {
            foreach (self::$caches as $field => $value) {
                $caches[$field] = json_decode($value, true);
            }
        }

        return $caches;
    }

    /**
     * @param $caches
     */
    public static function setCachesTable($caches)
    {
        $oldCaches = self::extractCachesData();
        $diff = ArrayHelper::arrayRecursiveDiff($caches, $oldCaches);
        self::refreshCaches();
        $newCaches = self::extractCachesData();
        $result = array_merge_recursive($newCaches, $diff);
        self::updateCachesTable($result);
    }

    /**
     *
     */
    private static function refreshCaches()
    {
        self::getCachesTable(true);
    }

    /**
     * @param $caches
     */
    public static function updateCachesTable($caches)
    {
        foreach ($caches as $key => $data) {
            self::$caches[$key] = json_encode($data);
        }
        foreach (self::$caches as $key => $data) {
            if (!isset($caches[$key])) {
                unset(self::$caches[$key]);
            }
        }
        \Yii::$app->cache->set('yii_caches_table', json_encode(self::$caches));
    }
}
