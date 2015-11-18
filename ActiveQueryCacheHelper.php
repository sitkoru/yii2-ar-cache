<?php

namespace sitkoru\cache\ar;

use yii\db\ActiveRecord;
use yii\db\Exception;
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
    public static $shaCache = '86bda7598e8952af3ca5aa2f23eedc54a5a11414';
    public static $shaInvalidate = '8cc3d1f5ba2ec9b0ceee2925dcdf516d67e18d70';

    private static $jsonOptions = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     *
     */
    public static function initialize()
    {
        if (!self::$inited) {

            if (!ActiveQueryCacheHelper::scriptExists(self::$shaCache)) {
                $path = __DIR__ . DIRECTORY_SEPARATOR . 'lua' . DIRECTORY_SEPARATOR . 'cache.lua';
                ActiveQueryCacheHelper::loadScript($path);
            }
            if (!ActiveQueryCacheHelper::scriptExists(self::$shaInvalidate)) {
                $path = __DIR__ . DIRECTORY_SEPARATOR . 'lua' . DIRECTORY_SEPARATOR . 'invalidate.lua';
                ActiveQueryCacheHelper::loadScript($path);
            }

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
        $query = (new Query())->select($pkName)->from($className::tableName())->where(
            $condition,
            $params
        );
        try {
            $results = $query->createCommand()->queryAll();
        } catch (Exception $ex) {
            $results = [];
        }

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

            json_encode($attrs, self::$jsonOptions),
            json_encode($changed, self::$jsonOptions)
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
