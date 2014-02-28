<?php

namespace sitkoru\cache\ar;

use sitkoru\cache\ar\ActiveQueryRedisHelper;
use sitkoru\cache\ar\CacheActiveQuery;
use yii\db\ActiveRecord;

/**
 * Class ActiveRecordTrait
 *
 * @package sitkoru\cache\ar
 */
trait ActiveRecordTrait
{
    public $fromCache = false;

    public static function createQuery($config = [])
    {
        $config['modelClass'] = get_called_class();

        return new CacheActiveQuery($config);
    }

    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            ActiveQueryRedisHelper::dropCaches($this);

            return true;
        } else {
            return false;
        }
    }

    public function beforeDelete()
    {
        if (parent::beforeDelete()) {
            ActiveQueryRedisHelper::dropCaches($this);

            return true;
        } else {
            return false;
        }
    }

    public function refresh()
    {
        ActiveQueryRedisHelper::dropCaches($this);

        return parent::refresh();
    }

    /**
     * @param string $class
     * @param array  $link
     *
     * @return CacheActiveQuery
     */
    public function hasMany($class, $link)
    {
        return parent::hasMany($class, $link);
    }

    /**
     * @param string $class
     * @param array  $link
     *
     * @return CacheActiveQuery
     */
    public function hasOne($class, $link)
    {
        return parent::hasOne($class, $link);
    }

    /**
     * @inheritdoc
     */
    public static function deleteAll($condition = '', $params = [])
    {
        ActiveQueryRedisHelper::dropCachesForCondition(static::className(), $condition, $params);

        return parent::deleteAll($condition, $params);
    }
}
