<?php

namespace sitkoru\cache\ar;

use PHPSQL\Parser;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Class CacheActiveQuery
 *
 * @package sitkoru\cache\ar
 */
class CacheActiveQuery extends ActiveQuery
{
    private $dropConditions = [];
    private $noCache = false;

    /**
     * @inheritdoc
     */
    public function all($db = null)
    {
        $command = $this->createCommand($db);
        $rawSql = $command->rawSql;
        $key = $this->generateCacheKey($rawSql, 'all');
        /**
         * @var ActiveRecord[] $fromCache
         */
        ActiveQueryCacheHelper::log(
            "LA " . $key
        );
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {

            $resultFromCache = [];
            if ($fromCache == ['null']) {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_EMPTY_ALL, $key, $rawSql);
                ActiveQueryCacheHelper::log(
                    "SEA " . $key
                );
            } else {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_HIT_ALL, $key, $rawSql);
                ActiveQueryCacheHelper::log(
                    "SA " . $key
                );
                foreach ($fromCache as $i => $model) {
                    $key = $i;
                    if ($model instanceof ActiveRecord) {
                        //restore key
                        ActiveQueryCacheHelper::insertKeyForPK($model, $key);
                        $model->afterFind();
                    }
                    if (is_string($this->indexBy)) {
                        $key = $model instanceof ActiveRecord ? $model->{$this->indexBy} : $model[$this->indexBy];
                    }
                    $resultFromCache[$key] = $model;
                }
            }
            return $resultFromCache;
        } else {
            ActiveQueryCacheHelper::log(
                "MA " . $key
            );
            ActiveQueryCacheHelper::profile(
                $this->noCache ? ActiveQueryCacheHelper::PROFILE_RESULT_NO_CACHE : ActiveQueryCacheHelper::PROFILE_RESULT_MISS_ALL,
                $key,
                $rawSql
            );
            $models = parent::all($db);
            if (!$this->noCache) {
                $this->insertInCacheAll($key, $models);
            }
            return $models;
        }
    }

    /**
     * @param string  $sql
     *
     * @param         $mode
     *
     * @return string
     */
    private function generateCacheKey($sql, $mode)
    {
        $key = $mode;
        $key .= strtolower($this->modelClass);
        $key .= $sql;
        if (count($this->where) == 0 && count($this->dropConditions) == 0) {
            $this->dropCacheOnCreate();
        }
        //pagination
        if ($this->limit > 0) {
            $key .= "limit" . $this->limit;
        }
        if ($this->offset > 0) {
            $key .= "offset" . $this->offset;
        }
        ActiveQueryCacheHelper::log(
            "G " . $sql . ':  ' . md5($key)
        );
        return md5($key);
    }

    /**
     * @param string|null  $param
     * @param string|array $value
     *
     * @return self
     */
    public function dropCacheOnCreate($param = null, $value = null)
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        foreach ($value as $val) {
            $event = [
                'type'  => 'create',
                'param' => $param,
                'value' => $val
            ];
            $this->dropConditions[] = $event;
        }

        return $this;
    }

    /**
     * @param                $key
     * @param ActiveRecord[] $models
     *
     * @return bool
     */
    private function insertInCacheAll($key, $models)
    {
        /** @var $class ActiveRecord */
        $class = $this->modelClass;
        $indexes = [
            $class::tableName() => [
            ]
        ];
        if ($models) {
            $toCache = $models;
            foreach ($toCache as $index => $model) {
                $mToCache = clone $model;
                $mToCache->fromCache = true;
                $toCache[$index] = $mToCache;
                $pks = $mToCache->getPrimaryKey(true);
                $indexes[$class::tableName()][] = reset($pks);
            }
        } else {
            $toCache = ['null'];
            $indexes[$class::tableName()][] = null;
            $this->generateDropConditionsForEmptyResult();
        }

        ActiveQueryCacheHelper::insertInCache($key, $toCache, $indexes, $this->dropConditions);

        return true;
    }

    /**
     */
    private function generateDropConditionsForEmptyResult()
    {

        if (count($this->where) == 0) {
            $this->dropCacheOnCreate();
        } else {
            $where = $this->getParsedWhere();
            foreach ($where as $condition) {
                if (in_array(
                    $condition['operator'],
                    [
                        'NOT IN',
                        '!=',
                        '>',
                        '<',
                        '>=',
                        '<=',
                    ]
                )) {
                    continue;
                }
                if (!$condition['value']) {
                    continue;
                }
                $this->dropCacheOnCreate($condition['col'], $condition['value']);
            }
        }
    }

    protected function getParsedWhere($sql = null)
    {
        $where = [];
        if (!$sql) {
            $sql = $this->createCommand()->rawSql;
        }
        $parser = new Parser();
        $parsed = $parser->parse($sql);
        if (isset($parsed['WHERE']) && count($parsed['WHERE']) > 0) {
            //var_dump($parsed['WHERE']);
            $this->parseWhere($parsed['WHERE'], $where);
        }
        return $where;
    }

    private function parseWhere($parsedWhere, &$where)
    {
        for ($i = 0; $i < count($parsedWhere);) {
            $element = $parsedWhere[$i];
            switch ($element['expr_type']) {
                case 'colref':
                    $operator = $parsedWhere[$i + 1]['base_expr'];
                    $value = $this->getWhereValue($parsedWhere, $operator, $i);
                    if ($value != 'skipparse') {
                        $where[] = [
                            'col'      => trim($element['base_expr'], '`'),
                            'operator' => $operator,
                            'value'    => $value,
                        ];
                        $i += 3;
                    }
                    break;
                case 'operator':
                    $i++;
                    break;
                case 'bracket_expression':
                    $base = $element['base_expr']; //(`groupId`, `type`, `level`)
                    if (stripos($base, ',') === false) {
                        $this->parseWhere($element['sub_tree'], $where);
                    }
                    $i++;
                    break;
                default:
                    $i++;
                    break;
            }
        }
        return $where;
    }

    /**
     * @param $parsedWhere
     * @param $operator
     * @param $i
     *
     * @return array|string
     */
    private function getWhereValue($parsedWhere, &$operator, &$i)
    {
        $value = null;
        $valueType = $parsedWhere[$i + 2]['expr_type'];
        switch ($valueType) {
            case 'const':
                $value = trim($parsedWhere[$i + 2]['base_expr'], "'");
                break;
            case 'in-list':
                $value = [];
                foreach ($parsedWhere[$i + 2]['sub_tree'] as $subElement) {
                    if ($subElement['expr_type'] == 'const') {
                        $value[] = $subElement['base_expr'];
                    } else {
                        //TODO: smthng
                    }
                }
                break;
            case 'colref':
                switch ($operator) {
                    case '+':
                        $value = trim($parsedWhere[$i + 3]['base_expr'], "'");
                        $i++;
                        break;
                    case ',':
                        var_dump($parsedWhere);
                        $value = 'skipparse';
                        break;
                    default:
                        $value = 'skipparse';
                        $i++;
                    //var_dump($parsedWhere);
                    //die('Colref: ' . json_encode($parsedWhere[$i + 2]));
                }
                break;
            case 'operator':
                switch ($parsedWhere[$i + 2]['base_expr']) {
                    case 'IN':
                        if ($operator == 'NOT') {
                            $tmp = 'IN';
                            $i = $i + 1;
                            $value = $this->getWhereValue($parsedWhere, $tmp, $i);
                            $operator = "NOT IN";
                        }
                        break;
                    case 'NOT':
                        if ($operator == 'IS') {
                            $tmp = 'NOT';
                            $i = $i + 1;
                            $value = $this->getWhereValue($parsedWhere, $tmp, $i);
                            $operator = "IS NOT";
                        }
                        break;
                    default:
                        die('Default: ' . $parsedWhere[$i + 2]);
                }
                break;
            default:
                var_dump($i);
                var_dump($parsedWhere);
                var_dump($valueType);
                die('Error: ' . $valueType);
                break;
        }
        return $value;
    }

    /**
     * @inheritdoc
     */
    public function one($db = null)
    {
        $command = $this->createCommand($db);
        $rawSql = $command->rawSql;
        $key = $this->generateCacheKey($command->rawSql, 'one');
        /**
         * @var ActiveRecord $fromCache
         */
        ActiveQueryCacheHelper::log(
            "LO " . $key
        );
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {
            if ($fromCache == 'null') {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_EMPTY_ONE, $key, $rawSql);
                ActiveQueryCacheHelper::log(
                    "SEO " . $key
                );
                $fromCache = null;
            } else {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_HIT_ONE, $key, $rawSql);
                ActiveQueryCacheHelper::log(
                    "SO " . $key
                );
                if ($fromCache instanceof ActiveRecord) {
                    //restore key
                    ActiveQueryCacheHelper::insertKeyForPK($fromCache, $key);
                    $fromCache->afterFind();
                }
            }
            return $fromCache;
        } else {
            ActiveQueryCacheHelper::profile(
                $this->noCache ? ActiveQueryCacheHelper::PROFILE_RESULT_NO_CACHE : ActiveQueryCacheHelper::PROFILE_RESULT_MISS_ONE,
                $key,
                $rawSql
            );
            ActiveQueryCacheHelper::log(
                "MO " . $key
            );
            $model = parent::one();
            if (!$this->noCache) {
                $this->insertInCacheOne($key, $model);
            }
            if ($model && $model instanceof ActiveRecord) {
                return $model;
            } else {
                return null;
            }
        }
    }

    /**
     * @param              $key
     * @param ActiveRecord $model
     *
     * @return bool
     */
    private function insertInCacheOne($key, $model)
    {
        /** @var $class ActiveRecord */
        $class = $this->modelClass;
        if ($model) {
            $keys = $model->getPrimaryKey(true);
            $pk = reset($keys);
            $indexes = [
                $class::tableName() => [
                    $pk
                ]
            ];
            $toCache = clone $model;
            $toCache->fromCache = true;
        } else {
            $toCache = 'null';
            $indexes[$class::tableName()] = ['null'];
            $this->generateDropConditionsForEmptyResult();
        }
        ActiveQueryCacheHelper::insertInCache($key, $toCache, $indexes, $this->dropConditions);

        return true;
    }

    /**
     * @param string     $param
     * @param null|array $condition
     *
     * @return self
     */
    public function dropCacheOnUpdate($param, $condition = null)
    {
        $event = [
            'type'       => 'update',
            'param'      => $param,
            'conditions' => []
        ];
        if ($condition) {
            foreach ($condition as $param => $value) {
                $event['conditions'] = [$param => $value];
            }
        }
        $this->dropConditions[] = $event;

        return $this;
    }

    /**
     * @return static
     */
    public function noCache()
    {
        $this->noCache = true;

        return $this;

    }

    /**
     * @param bool $value
     * @return static
     */
    public function asArray($value = true)
    {
        if ($value) {
            $this->noCache = true;
        }
        return parent::asArray($value);
    }

    /**
     * @return int
     */
    public function deleteAll()
    {
        /**
         * @var $class ActiveRecord
         */
        $params = [];
        $class = $this->modelClass;
        return $class::deleteAll($this->where, $params);
    }
}
