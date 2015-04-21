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
        )->createCommand()->queryAll();

        return [$pkName, $results];
    }

    /**
     * @param ActiveRecord $model
     * @param array        $changedAttributes
     */
    public static function dropCaches($model, array $changedAttributes = [])
    {
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
            json_encode($attrs),
            json_encode($changed)
        ];
        CacheHelper::evalSHA(CacheActiveQuery::$shaInvalidate, $args, 0);
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
