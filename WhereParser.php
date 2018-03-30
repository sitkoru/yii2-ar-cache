<?php

namespace sitkoru\cache\ar;

use yii\base\InvalidParamException;
use yii\db\Exception;
use yii\db\Expression;
use yii\db\Query;

/**
 * Class WhereParser
 * @package sitkoru\cache\ar
 */
class WhereParser
{

    private $data = [];

    protected $conditionParsers = [
        'NOT'         => 'parseNotCondition',
        'AND'         => 'parseAndCondition',
        'OR'          => 'parseAndCondition',
        'BETWEEN'     => 'parseBetweenCondition',
        'NOT BETWEEN' => 'parseBetweenCondition',
        'IN'          => 'parseInCondition',
        'NOT IN'      => 'parseInCondition',
        'LIKE'        => 'parseLikeCondition',
        'NOT LIKE'    => 'parseLikeCondition',
        'OR LIKE'     => 'parseLikeCondition',
        'OR NOT LIKE' => 'parseLikeCondition',
        'EXISTS'      => 'parseExistsCondition',
        'NOT EXISTS'  => 'parseExistsCondition'
    ];

    public function parse($condition, $params)
    {
        $this->data = [];

        $this->parseCondition($condition, $params);

        return $this->data;
    }

    private function parseCondition($condition, $params)
    {
        if (is_array($condition) && array_key_exists(0, $condition)
        ) { // operator format: operator, operand 1, operand 2, ...
            $operator = strtoupper($condition[0]);
            $method = 'parseSimpleCondition';
            if (array_key_exists($operator, $this->conditionParsers)) {
                $method = $this->conditionParsers[$operator];
            }
            array_shift($condition);

            $this->$method($operator, $condition, $params);
        } else { // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
            $this->parseHashCondition($condition, $params);
        }
    }

    /**
     * Creates a condition based on column-value pairs.
     * @param array $condition the condition specification.
     * @param array $params    the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function parseHashCondition($condition, &$params)
    {
        $parts = [];
        foreach ($condition as $column => $value) {
            if (is_array($value) || $value instanceof Query) {
                // IN condition
                $parts[] = $this->parseInCondition('IN', [$column, $value], $params);
            } else {
                $this->data[] = [$column, '=', $value];
            }
        }

        return true;
    }

    /**
     * Connects two or more SQL expressions with the `AND` or `OR` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array  $operands the SQL expressions to connect.
     * @param array  $params   the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function parseAndCondition($operator, $operands, &$params)
    {
        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $this->parseCondition($operand, $params);
            }
        }

        return true;
    }

    /**
     * Inverts an SQL expressions with `NOT` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array  $operands the SQL expressions to connect.
     * @param array  $params   the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function parseNotCondition($operator, $operands, &$params)
    {
        //TODO: implement
        return true;
    }

    /**
     * Creates an SQL expressions with the `BETWEEN` operator.
     * @param string $operator the operator to use (e.g. `BETWEEN` or `NOT BETWEEN`)
     * @param array  $operands the first operand is the column name. The second and third operands
     *                         describe the interval that column value should be in.
     * @param array  $params   the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function parseBetweenCondition($operator, $operands, &$params)
    {
        //TODO: implement
        return true;
    }

    /**
     * @param $operator
     * @param $operands
     * @param $params
     * @return string
     * @throws Exception
     */
    public function parseInCondition($operator, $operands, &$params)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new Exception("Operator '$operator' requires two operands.");
        }

        list($column, $values) = $operands;

        if ($values === [] || $column === []) {
            return $operator === 'IN' ? '0=1' : '';
        }

        if ($values instanceof Query) {
            return $this->parseSubqueryInCondition($operator, $column, $values, $params);
        }

        $values = (array)$values;

        if (is_array($column)) {
            $column = reset($column);
            if (\count($column) > 1) {
                return $this->parseCompositeInCondition($operator, $column, $values, $params);
            }
        }
        foreach ($values as $i => $value) {
            if (is_array($value)) {
                $value = array_key_exists($column, $value) ? $value[$column] : null;
            }
            if ($value === null) {
                $values[$i] = 'NULL';
            } elseif ($value instanceof Expression) {
                $values[$i] = $value->expression;

            }
        }

        $this->data[] = [$column, $operator, $values];

        return true;
    }

    /**
     * Parse SQL for IN condition
     *
     * @param string $operator
     * @param array  $columns
     * @param Query  $values
     * @param array  $params
     * @return string SQL
     */
    protected function parseSubqueryInCondition($operator, $columns, $values, &$params)
    {
        //TODO: implement
        return;
    }

    /**
     * Builds SQL for IN condition
     *
     * @param string $operator
     * @param array  $columns
     * @param array  $values
     * @param array  $params
     * @return string SQL
     */
    protected function parseCompositeInCondition($operator, $columns, $values, &$params)
    {
        foreach ($columns as $i => $column) {
            if (array_key_exists($i, $values)) {
                $this->data[] = [$column, $operator, $values[$i]];
            }
        }

        return true;
    }

    /**
     * @param string $operator
     * @param array  $operands
     * @param array  $params
     * @return string
     * @throws InvalidParamException
     */
    public function parseLikeCondition($operator, $operands, &$params)
    {
        //TODO: Implement
        return;
    }

    /**
     * Creates an SQL expressions with the `EXISTS` operator.
     * @param string $operator the operator to use (e.g. `EXISTS` or `NOT EXISTS`)
     * @param array  $operands contains only one element which is a [[Query]] object representing the sub-query.
     * @param array  $params   the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if the operand is not a [[Query]] object.
     */
    public function parseExistsCondition($operator, $operands, &$params)
    {
        //TODO: Implement
        return;
    }

    /**
     * Creates an SQL expressions like `"column" operator value`.
     * @param string $operator the operator to use. Anything could be used e.g. `>`, `<=`, etc.
     * @param array  $operands contains two column names.
     * @param array  $params   the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function parseSimpleCondition($operator, $operands, &$params)
    {
        if (count($operands) !== 2) {
            throw new InvalidParamException("Operator '$operator' requires two operands.");
        }

        list($column, $value) = $operands;

        if ($value === null) {
            $this->data[] = [$column, $operator, null];

            return true;
        } elseif ($value instanceof Expression) {
            return true;
        } else {
            $this->data[] = [$column, $operator, $value];

            return true;
        }
    }
}