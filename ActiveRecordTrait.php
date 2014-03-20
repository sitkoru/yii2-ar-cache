<?php

namespace sitkoru\cache\ar;

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

    public function afterSave($insert)
    {
        parent::afterSave($insert);
        ActiveQueryCacheHelper::dropCaches($this);
    }

    public function afterDelete()
    {
        parent::afterDelete();
        echo "After delete!";
        ActiveQueryCacheHelper::dropCaches($this);
    }

    public function refresh()
    {
        ActiveQueryCacheHelper::dropCaches($this);

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
        ActiveQueryCacheHelper::dropCachesForCondition(static::className(), $condition, $params);

        return parent::deleteAll($condition, $params);
    }
}
