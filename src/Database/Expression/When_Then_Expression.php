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

use Cake\Database\Expression_Interface;
use Cake\Database\Query;
use Cake\Database\Type\Expression_Type_Caster_Trait;
use Cake\Database\Type_Map;
use Cake\Database\Value_Binder;
use Closure;
use InvalidArgumentException;
use LogicException;
/**
 * Represents a SQL when/then clause with a fluid API
 */
class When_Then_Expression implements Expression_Interface
{
    use Case_Expression_Trait;
    use Expression_Type_Caster_Trait;
    /**
     * The names of the clauses that are valid for use with the
     * `clause()` method.
     *
     * @var array<string>
     */
    protected array $valid_clause_names = ['when', 'then'];
    /**
     * The type map to use when using an array of conditions for the
     * `WHEN` value.
     */
    protected Type_Map $_type_map;
    /**
     * Then `WHEN` value.
     *
     * @var \Cake\Database\ExpressionInterface|object|scalar|null
     */
    protected mixed $when = null;
    /**
     * The `WHEN` value type.
     */
    protected array|string|null $when_type = null;
    /**
     * The `THEN` value.
     *
     * @var \Cake\Database\ExpressionInterface|object|scalar|null
     */
    protected mixed $then = null;
    /**
     * Whether the `THEN` value has been defined, eg whether `then()`
     * has been invoked.
     */
    protected bool $has_then_been_defined = false;
    /**
     * The `THEN` result type.
     */
    protected ?string $then_type = null;
    /**
     * Constructor.
     *
     * @param \Cake\Database\TypeMap|null $typeMap The type map to use when using an array of conditions for the `WHEN`
     *  value.
     */
    public function __construct(?Type_Map $type_map = null)
    {
        $this->_type_map = $type_map ?? new Type_Map();
    }
    /**
     * Sets the `WHEN` value.
     *
     * @param object|array|string|float|int|bool $when The `WHEN` value. When using an array of
     *  conditions, it must be compatible with `\Cake\Database\Query::where()`. Note that this argument is _not_
     *  completely safe for use with user data, as a user supplied array would allow for raw SQL to slip in! If you
     *  plan to use user data, either pass a single type for the `$type` argument (which forces the `$when` value to be
     *  a non-array, and then always binds the data), use a conditions array where the user data is only passed on the
     *  value side of the array entries, or custom bindings!
     * @param array<string, string>|string|null $type The when value type. Either an associative array when using array style
     *  conditions, or else a string. If no type is provided, the type will be tried to be inferred from the value.
     * @return $this
     * @throws \InvalidArgumentException In case the `$when` argument is an empty array.
     * @throws \InvalidArgumentException In case the `$when` argument is an array, and the `$type` argument is neither
     * an array, nor null.
     * @throws \InvalidArgumentException In case the `$when` argument is a non-array value, and the `$type` argument is
     * neither a string, nor null.
     * @see CaseStatementExpression::when() for a more detailed usage explanation.
     */
    public function when(object|array|string|float|int|bool $when, array|string|null $type = null): static
    {
        if (is_array($when)) {
            if (!$when) {
                throw new InvalidArgumentException('The `$when` argument must be a non-empty array');
            }
            if ($type !== null && !is_array($type)) {
                throw new InvalidArgumentException(sprintf('When using an array for the `$when` argument, the `$type` argument must be an ' . 'array too, `%s` given.', get_debug_type($type)));
            }
            // avoid dirtying the type map for possible consecutive `when()` calls
            $type_map = clone $this->_type_map;
            if (is_array($type) && $type !== []) {
                $type_map = $type_map->set_types($type);
            }
            $when = new Query_Expression($when, $type_map);
        } else {
            if ($type !== null && !is_string($type)) {
                throw new InvalidArgumentException(sprintf('When using a non-array value for the `$when` argument, the `$type` argument must ' . 'be a string, `%s` given.', get_debug_type($type)));
            }
            if ($type === null && !$when instanceof Expression_Interface) {
                $type = $this->infer_type($when);
            }
        }
        $this->when = $when;
        $this->when_type = $type;
        return $this;
    }
    /**
     * Sets the `THEN` result value.
     *
     * @param \Cake\Database\ExpressionInterface|object|scalar|null $result The result value.
     * @param string|null $type The result type. If no type is provided, the type will be inferred from the given
     *  result value.
     * @return $this
     */
    public function then(mixed $result, ?string $type = null): static
    {
        if ($result !== null && !is_scalar($result) && !(is_object($result) && !$result instanceof Closure)) {
            throw new InvalidArgumentException(sprintf('The `$result` argument must be either `null`, a scalar value, an object, ' . 'or an instance of `\%s`, `%s` given.', Expression_Interface::class, get_debug_type($result)));
        }
        $this->then = $result;
        $this->then_type = $type ?? $this->infer_type($result);
        $this->has_then_been_defined = true;
        return $this;
    }
    /**
     * Returns the expression's result value type.
     *
     * @see WhenThenExpression::then()
     */
    public function get_result_type(): ?string
    {
        return $this->then_type;
    }
    /**
     * Returns the available data for the given clause.
     *
     * ### Available clauses
     *
     * The following clause names are available:
     *
     * * `when`: The `WHEN` value.
     * * `then`: The `THEN` result value.
     *
     * @param string $clause The name of the clause to obtain.
     * @return \Cake\Database\ExpressionInterface|object|scalar|null
     * @throws \InvalidArgumentException In case the given clause name is invalid.
     */
    public function clause(string $clause): mixed
    {
        if (!in_array($clause, $this->valid_clause_names, true)) {
            throw new InvalidArgumentException(sprintf('The `$clause` argument must be one of `%s`, the given value `%s` is invalid.', implode('`, `', $this->valid_clause_names), $clause));
        }
        return $this->{$clause};
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        if ($this->when === null) {
            throw new LogicException('Case expression has incomplete when clause. Missing `when()`.');
        }
        if (!$this->has_then_been_defined) {
            throw new LogicException('Case expression has incomplete when clause. Missing `then()` after `when()`.');
        }
        $when = $this->when;
        if (is_string($this->when_type) && !$when instanceof Expression_Interface) {
            $when = $this->_cast_to_expression($when, $this->when_type);
        }
        if ($when instanceof Query) {
            $when = sprintf('(%s)', $when->sql($binder));
        } elseif ($when instanceof Expression_Interface) {
            $when = $when->sql($binder);
        } else {
            $placeholder = $binder->placeholder('c');
            if (is_string($this->when_type)) {
                $when_type = $this->when_type;
            } else {
                $when_type = null;
            }
            $binder->bind($placeholder, $when, $when_type);
            $when = $placeholder;
        }
        $then = $this->compile_nullable_value($binder, $this->then, $this->then_type);
        return "WHEN {$when} THEN {$then}";
    }
    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        if ($this->when instanceof Expression_Interface) {
            $callback($this->when);
            $this->when->traverse($callback);
        }
        if ($this->then instanceof Expression_Interface) {
            $callback($this->then);
            $this->then->traverse($callback);
        }
        return $this;
    }
    /**
     * Clones the inner expression objects.
     */
    public function __clone()
    {
        if ($this->when instanceof Expression_Interface) {
            $this->when = clone $this->when;
        }
        if ($this->then instanceof Expression_Interface) {
            $this->then = clone $this->then;
        }
    }
}