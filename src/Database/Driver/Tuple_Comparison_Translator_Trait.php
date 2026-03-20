<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Driver;

use Cake\Database\Expression\Identifier_Expression;
use Cake\Database\Expression\Query_Expression;
use Cake\Database\Expression\Tuple_Comparison;
use Cake\Database\Query;
use Cake\Database\Query\Select_Query;
use InvalidArgumentException;
/**
 * Provides a translator method for tuple comparisons
 *
 * @internal
 */
trait Tuple_Comparison_Translator_Trait
{
    /**
     * Receives a TupleExpression and changes it so that it conforms to this
     * SQL dialect.
     *
     * It transforms expressions looking like '(a, b) IN ((c, d), (e, f))' into an
     * equivalent expression of the form '((a = c) AND (b = d)) OR ((a = e) AND (b = f))'.
     *
     * It can also transform expressions where the right hand side is a query
     * selecting the same amount of columns as the elements in the left hand side of
     * the expression:
     *
     * (a, b) IN (SELECT c, d FROM a_table) is transformed into
     *
     * 1 = (SELECT 1 FROM a_table WHERE (a = c) AND (b = d))
     *
     * @param \Cake\Database\Expression\TupleComparison $expression The expression to transform
     * @param \Cake\Database\Query $query The query to update.
     */
    protected function _transform_tuple_comparison(Tuple_Comparison $expression, Query $query): void
    {
        $fields = $expression->get_field();
        if (!is_array($fields)) {
            return;
        }
        $operator = strtoupper($expression->get_operator());
        if (!in_array($operator, ['IN', '='])) {
            throw new InvalidArgumentException(sprintf('Tuple comparison transform only supports the `IN` and `=` operators, `%s` given.', $operator));
        }
        $value = $expression->get_value();
        $true = new Query_Expression('1');
        if ($value instanceof Select_Query) {
            /** @var array<string> $selected */
            $selected = array_values($value->clause('select'));
            foreach ($fields as $i => $field) {
                $value->and_where([$field => new Identifier_Expression($selected[$i])]);
            }
            $value->select($true, true);
            $expression->set_field($true);
            $expression->set_operator('=');
            return;
        }
        $type = $expression->get_type();
        if ($type) {
            /** @var array<string, string> $typeMap */
            $type_map = array_combine($fields, $type) ?: [];
        } else {
            $type_map = [];
        }
        $surrogate = $query->get_connection()->select_query()->select($true);
        if (!is_array(current($value))) {
            $value = [$value];
        }
        $conditions = ['OR' => []];
        foreach ($value as $tuple) {
            $item = [];
            foreach (array_values($tuple) as $i => $value2) {
                $item[] = [$fields[$i] => $value2];
            }
            $conditions['OR'][] = $item;
        }
        $surrogate->where($conditions, $type_map);
        $expression->set_field($true);
        $expression->set_value($surrogate);
        $expression->set_operator('=');
    }
}