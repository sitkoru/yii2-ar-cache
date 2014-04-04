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
    public $insert = false;

    public static function createQuery()
    {
        return new CacheActiveQuery(get_called_class());
    }

    public function afterSave($insert)
    {
        parent::afterSave($insert);
        $this->insert = $insert;
        ActiveQueryCacheHelper::dropCaches($this);
    }

    public function afterDelete()
    {
        parent::afterDelete();
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
