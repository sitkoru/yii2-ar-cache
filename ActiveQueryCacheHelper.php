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
    private static $inited = false;
    public static $shaCache;
    public static $shaInvalidate;

    public static function initialize()
    {
        if (!self::$inited) {
            $path = __DIR__ . DIRECTORY_SEPARATOR . 'lua' . DIRECTORY_SEPARATOR . 'cache.lua';
            self::$shaCache = ActiveQueryCacheHelper::loadScript($path);
            $path = __DIR__ . DIRECTORY_SEPARATOR . 'lua' . DIRECTORY_SEPARATOR . 'invalidate.lua';
            self::$shaInvalidate = ActiveQueryCacheHelper::loadScript($path);
            self::$inited = true;
        }
    }

    private static $cacheTTL = 7200; //two hours by default

    /**
     * @param $ttl
     */
    public static function setTTL($ttl)
    {
        self::$cacheTTL = (int)$ttl;
    }

    /**
     * @return int
     */
    public static function getTTL()
    {
        return self::$cacheTTL;
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
     * @param ActiveRecord $className
     * @param              $condition
     * @param              $params
     *
     * @return array
     */
    protected static function getModelsToDelete($className, $condition, $params)
    {
        /**
         * @var ActiveRecord $className
         */
        $pks = $className::primaryKey();
        $pkName = reset($pks);
        $query = new Query();
        $results = $query->select($pkName)->from($className::tableName())->where(
            $condition,
            $params
        )->createCommand($className::getDb())->queryAll();

        return [$pkName, $results];
    }

    /**
     * @param ActiveRecord $model
     * @param array        $changedAttributes
     */
    public static function dropCaches($model, array $changedAttributes = [])
    {
        self::initialize();
        $attrs = $model->getAttributes();
        $changed = [];
        if ($changedAttributes) {
            $attrNames = array_keys($changedAttributes);
            foreach ($attrNames as $attrName) {
                if (array_key_exists($attrName, $attrs)) {
                    $changed[$attrName] = $attrs[$attrName];
                }
            }
        }
        $args = [
            $model->tableName(),
            json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            json_encode($changed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        ];
        CacheHelper::evalSHA(self::$shaInvalidate, $args, 0);
    }


    public static function dropCachesForCreateEvent($model, $param = null, $value = null)
    {
        if ($param) {
            $model->$param = $value;
            self::dropCaches($model, [$param => $value]);
        } else {
            self::dropCaches($model);
        }
    }
}
