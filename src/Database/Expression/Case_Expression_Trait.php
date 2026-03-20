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
 * @since         4.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Expression;

use Cake\Chronos\Chronos_Date;
use Cake\Database\Expression_Interface;
use Cake\Database\Query;
use Cake\Database\Typed_Result_Interface;
use Cake\Database\Value_Binder;
use DateTimeInterface;
use Stringable;
/**
 * Trait that holds shared functionality for case related expressions.
 *
 * @internal
 */
trait Case_Expression_Trait
{
    /**
     * Infers the abstract type for the given value.
     *
     * @param mixed $value The value for which to infer the type.
     * @return string|null The abstract type, or `null` if it could not be inferred.
     */
    protected function infer_type(mixed $value): ?string
    {
        $type = null;
        if (is_string($value)) {
            $type = 'string';
        } elseif (is_int($value)) {
            $type = 'integer';
        } elseif (is_float($value)) {
            $type = 'float';
        } elseif (is_bool($value)) {
            $type = 'boolean';
        } elseif ($value instanceof Chronos_Date) {
            $type = 'date';
        } elseif ($value instanceof DateTimeInterface) {
            $type = 'datetime';
        } elseif ($value instanceof Stringable) {
            $type = 'string';
        } elseif ($this->_type_map !== null && $value instanceof Identifier_Expression) {
            $type = $this->_type_map->type($value->get_identifier());
        } elseif ($value instanceof Typed_Result_Interface) {
            $type = $value->get_return_type();
        }
        return $type;
    }
    /**
     * Compiles a nullable value to SQL.
     *
     * @param \Cake\Database\ValueBinder $binder The value binder to use.
     * @param \Cake\Database\ExpressionInterface|object|scalar|null $value The value to compile.
     * @param string|null $type The value type.
     */
    protected function compile_nullable_value(Value_Binder $binder, mixed $value, ?string $type = null): string
    {
        if ($type !== null && !$value instanceof Expression_Interface) {
            $value = $this->_cast_to_expression($value, $type);
        }
        if ($value === null) {
            $value = 'NULL';
        } elseif ($value instanceof Query) {
            $value = sprintf('(%s)', $value->sql($binder));
        } elseif ($value instanceof Expression_Interface) {
            $value = $value->sql($binder);
        } else {
            $placeholder = $binder->placeholder('c');
            $binder->bind($placeholder, $value, $type);
            $value = $placeholder;
        }
        return $value;
    }
}