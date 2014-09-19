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
        \Yii::info(
            "Look in cache for " . $key,
            'cache'
        );
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {

            $resultFromCache = [];
            if ($fromCache == ['null']) {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_EMPTY_ALL, $key, $rawSql);
                \Yii::info(
                    "Success empty for " . $key,
                    'cache'
                );
            } else {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_HIT_ALL, $key, $rawSql);
                \Yii::info(
                    "Success for " . $key,
                    'cache'
                );
                foreach ($fromCache as $i => $model) {
                    $key = $i;
                    if ($model instanceof ActiveRecord) {
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
            \Yii::info(
                "Miss for " . $key,
                'cache'
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
        \Yii::info(
            "Generate cache key for " . $key . ':  . md5($key)',
            'cache'
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

    private function getParsedWhere($sql = null)
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
                    $where[] = [
                        'col'      => trim($element['base_expr'], '`'),
                        'operator' => $operator,
                        'value'    => $value,
                    ];
                    $i += 3;
                    break;
                case 'operator':
                    $i++;
                    break;
                case 'bracket_expression':
                    $this->parseWhere($element['sub_tree'], $where);
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
                    default:
                        die($parsedWhere[$i + 2]);
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
                        die($parsedWhere[$i + 2]);
                }
                break;
            default:
                var_dump($i);
                var_dump($parsedWhere);
                die($valueType);
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
        \Yii::info(
            "Look in cache for " . $key,
            'cache'
        );
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {
            if ($fromCache == 'null') {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_EMPTY_ONE, $key, $rawSql);
                \Yii::info(
                    "Success empty for " . $key,
                    'cache'
                );
                $fromCache = null;
            } else {
                ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_HIT_ONE, $key, $rawSql);
                \Yii::info(
                    "Success for " . $key,
                    'cache'
                );
                if ($fromCache instanceof ActiveRecord) {
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
            \Yii::info(
                "Miss for " . $key,
                'cache'
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

    public function asArray($value = true)
    {
        if ($value) {
            $this->noCache = true;
        }
        return parent::asArray($value);
    }

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
