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
namespace Cake\Collection;

use Append_Iterator;
use ArrayIterator;
use Backed_Enum;
use Cake\Collection\Iterator\Buffered_Iterator;
use Cake\Collection\Iterator\Extract_Iterator;
use Cake\Collection\Iterator\Filter_Iterator;
use Cake\Collection\Iterator\Insert_Iterator;
use Cake\Collection\Iterator\Map_Reduce;
use Cake\Collection\Iterator\Nest_Iterator;
use Cake\Collection\Iterator\Replace_Iterator;
use Cake\Collection\Iterator\Sort_Iterator;
use Cake\Collection\Iterator\Stoppable_Iterator;
use Cake\Collection\Iterator\Tree_Iterator;
use Cake\Collection\Iterator\Unfold_Iterator;
use Cake\Collection\Iterator\Unique_Iterator;
use Cake\Collection\Iterator\Zip_Iterator;
use Countable;
use Generator;
use InvalidArgumentException;
use Iterator;
use Limit_Iterator;
use LogicException;
use Recursive_Iterator_Iterator;
use const SORT_ASC;
use const SORT_DESC;
use const SORT_NUMERIC;
use Unit_Enum;
/**
 * Offers a handful of methods to manipulate iterators
 *
 * @template TKey
 * @template TValue
 * @require-implements \Cake\Collection\CollectionInterface
 */
trait Collection_Trait
{
    use Extract_Trait;
    /**
     * Returns a new collection.
     *
     * Allows classes which use this trait to determine their own
     * type of returned collection interface
     *
     * @param mixed ...$args Constructor arguments.
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    protected function new_collection(mixed ...$args): Collection_Interface
    {
        return new Collection(...$args);
    }
    /**
     * @inheritDoc
     */
    public function each(callable $callback)
    {
        foreach ($this->optimize_unwrap() as $k => $v) {
            $callback($v, $k);
        }
        return $this;
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function filter(?callable $callback = null): Collection_Interface
    {
        $callback ??= fn($v): bool => (bool) $v;
        return new Filter_Iterator($this->unwrap(), $callback);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function reject(?callable $callback = null): Collection_Interface
    {
        $callback ??= fn($v): bool => (bool) $v;
        return new Filter_Iterator($this->unwrap(), fn($value, $key, $items): bool => !$callback($value, $key, $items));
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function unique(?callable $callback = null): Collection_Interface
    {
        $callback ??= fn($v) => $v;
        return new Unique_Iterator($this->unwrap(), $callback);
    }
    /**
     * @inheritDoc
     */
    public function every(callable $callback): bool
    {
        foreach ($this->optimize_unwrap() as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }
        return true;
    }
    /**
     * Returns true if the callback returns true for any element in the collection.
     *
     * The callback accepts the value and key of the element being tested.
     *
     * ### Example:
     *
     * ```
     * $hasYoungPeople = (new Collection([24, 45, 15]))->any(function ($value, $key) {
     *  return $value < 21;
     * });
     * ```
     *
     * @param callable $callback a callback function
     */
    public function any(callable $callback): bool
    {
        foreach ($this->optimize_unwrap() as $key => $value) {
            if ($callback($value, $key) === true) {
                return true;
            }
        }
        return false;
    }
    /**
     * @inheritDoc
     */
    public function some(callable $callback): bool
    {
        return $this->any($callback);
    }
    /**
     * @inheritDoc
     */
    public function contains(mixed $value): bool
    {
        foreach ($this->optimize_unwrap() as $v) {
            if ($value === $v) {
                return true;
            }
        }
        return false;
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function map(callable $callback): Collection_Interface
    {
        return new Replace_Iterator($this->unwrap(), $callback);
    }
    /**
     * @inheritDoc
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $is_first = func_num_args() < 2;
        $result = $initial;
        foreach ($this->optimize_unwrap() as $k => $value) {
            if ($is_first) {
                $result = $value;
                $is_first = false;
                continue;
            }
            $result = $callback($result, $value, $k);
        }
        return $result;
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, mixed>
     */
    public function extract(callable|string $path): Collection_Interface
    {
        $extractor = new Extract_Iterator($this->unwrap(), $path);
        if (is_string($path) && str_contains($path, '{*}')) {
            return $extractor->filter(fn($data) => is_iterable($data))->unfold();
        }
        return $extractor;
    }
    /**
     * @inheritDoc
     */
    public function max(callable|string $path, int $sort = SORT_NUMERIC): mixed
    {
        return (new Sort_Iterator($this->unwrap(), $path, SORT_DESC, $sort))->first();
    }
    /**
     * @inheritDoc
     */
    public function min(callable|string $path, int $sort = SORT_NUMERIC): mixed
    {
        return (new Sort_Iterator($this->unwrap(), $path, SORT_ASC, $sort))->first();
    }
    /**
     * @inheritDoc
     */
    public function avg(callable|string|null $path = null): float|int|null
    {
        $result = $this;
        if ($path !== null) {
            $result = $result->extract($path);
        }
        $result = $result->reduce(function (array $acc, $current): array {
            [$count, $sum] = $acc;
            return [$count + 1, $sum + $current];
        }, [0, 0]);
        if ($result[0] === 0) {
            return null;
        }
        return $result[1] / $result[0];
    }
    /**
     * @inheritDoc
     */
    public function median(callable|string|null $path = null): float|int|null
    {
        $items = $this;
        if ($path !== null) {
            $items = $items->extract($path);
        }
        $values = $items->to_list();
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return null;
        }
        $middle = (int) ($count / 2);
        if ($count % 2) {
            return $values[$middle];
        }
        return ($values[$middle - 1] + $values[$middle]) / 2;
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function sort_by(callable|string $path, int $order = SORT_DESC, int $sort = SORT_NUMERIC): Collection_Interface
    {
        return new Sort_Iterator($this->unwrap(), $path, $order, $sort);
    }
    /**
     * Splits a collection into sets, grouped by the result of running each value
     * through the callback. If $callback is a string instead of a callable,
     * groups by the property named by $callback on each of the values.
     *
     * When $callback is a string it should be a property name to extract or
     * a dot separated path of properties that should be followed to get the last
     * one in the path.
     *
     * ### Example:
     *
     * ```
     * $items = [
     *  ['id' => 1, 'name' => 'foo', 'parent_id' => 10],
     *  ['id' => 2, 'name' => 'bar', 'parent_id' => 11],
     *  ['id' => 3, 'name' => 'baz', 'parent_id' => 10],
     * ];
     *
     * $group = (new Collection($items))->groupBy('parent_id');
     *
     * // Or
     * $group = (new Collection($items))->groupBy(function ($e) {
     *  return $e['parent_id'];
     * });
     *
     * // Result will look like this when converted to array
     * [
     *  10 => [
     *      ['id' => 1, 'name' => 'foo', 'parent_id' => 10],
     *      ['id' => 3, 'name' => 'baz', 'parent_id' => 10],
     *  ],
     *  11 => [
     *      ['id' => 2, 'name' => 'bar', 'parent_id' => 11],
     *  ]
     * ];
     * ```
     *
     * @param callable|string $path The column name to use for grouping or callback that returns the value.
     *   or a function returning the grouping key out of the provided element
     * @param bool $preserveKeys Whether to preserve the keys of the existing
     *   collection when the values are grouped. Defaults to false.
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function group_by(callable|string $path, bool $preserve_keys = false): Collection_Interface
    {
        $callback = $this->_property_extractor($path);
        $group = [];
        foreach ($this->optimize_unwrap() as $key => $value) {
            $path_value = $callback($value);
            if ($path_value === null) {
                throw new InvalidArgumentException('Cannot group by path that does not exist or contains a null value. ' . 'Use a callback to return a default value for that path.');
            }
            if ($path_value instanceof Backed_Enum) {
                $path_value = $path_value->value;
            } elseif ($path_value instanceof Unit_Enum) {
                $path_value = $path_value->name;
            }
            if ($preserve_keys) {
                $group[$path_value][$key] = $value;
                continue;
            }
            $group[$path_value][] = $value;
        }
        return $this->new_collection($group);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<mixed, TValue>
     */
    public function index_by(callable|string $path): Collection_Interface
    {
        $callback = $this->_property_extractor($path);
        $group = [];
        foreach ($this->optimize_unwrap() as $value) {
            $path_value = $callback($value);
            if ($path_value === null) {
                throw new InvalidArgumentException('Cannot index by path that does not exist or contains a null value. ' . 'Use a callback to return a default value for that path.');
            }
            if ($path_value instanceof Backed_Enum) {
                $path_value = $path_value->value;
            } elseif ($path_value instanceof Unit_Enum) {
                $path_value = $path_value->name;
            }
            $group[$path_value] = $value;
        }
        return $this->new_collection($group);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<mixed, int>
     */
    public function count_by(callable|string $path): Collection_Interface
    {
        $callback = $this->_property_extractor($path);
        $mapper = fn($value, $key, Map_Reduce $mr) => $mr->emit_intermediate($value, $callback($value));
        $reducer = fn($values, $key, Map_Reduce $mr) => $mr->emit(count($values), $key);
        return $this->new_collection(new Map_Reduce($this->unwrap(), $mapper, $reducer));
        // @phpstan-ignore return.type
    }
    /**
     * @inheritDoc
     */
    public function sum_of(callable|string|null $path = null): float|int
    {
        if ($path === null) {
            return array_sum($this->to_list());
        }
        $callback = $this->_property_extractor($path);
        $sum = 0;
        foreach ($this->optimize_unwrap() as $k => $v) {
            $sum += $callback($v, $k);
        }
        return $sum;
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<int, TValue>
     */
    public function shuffle(): Collection_Interface
    {
        $items = $this->to_list();
        shuffle($items);
        return $this->new_collection($items);
        // @phpstan-ignore return.type
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<int, TValue>
     */
    public function sample(int $length = 10): Collection_Interface
    {
        return $this->new_collection(new Limit_Iterator($this->shuffle(), 0, $length));
        // @phpstan-ignore return.type
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function take(int $length = 1, int $offset = 0): Collection_Interface
    {
        return $this->new_collection(new Limit_Iterator($this, $offset, $length));
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function skip(int $length): Collection_Interface
    {
        return $this->new_collection(new Limit_Iterator($this, $length));
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function match(array $conditions): Collection_Interface
    {
        return $this->filter($this->_create_matcher_filter($conditions));
    }
    /**
     * @inheritDoc
     */
    public function first_match(array $conditions): mixed
    {
        return $this->match($conditions)->first();
    }
    /**
     * @inheritDoc
     */
    public function first(): mixed
    {
        $iterator = new Limit_Iterator($this, 0, 1);
        foreach ($iterator as $result) {
            return $result;
        }
        return null;
    }
    /**
     * @inheritDoc
     */
    public function last(): mixed
    {
        $iterator = $this->optimize_unwrap();
        if (is_array($iterator)) {
            return array_pop($iterator);
        }
        if ($iterator instanceof Countable) {
            $count = count($iterator);
            if ($count === 0) {
                return null;
            }
            $iterator = new Limit_Iterator($iterator, $count - 1, 1);
        }
        $result = null;
        foreach ($iterator as $result) {
            // No-op
        }
        return $result;
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function take_last(int $length): Collection_Interface
    {
        if ($length < 1) {
            throw new InvalidArgumentException('The takeLast method requires a number greater than 0.');
        }
        $iterator = $this->optimize_unwrap();
        if (is_array($iterator)) {
            return $this->new_collection(array_slice($iterator, $length * -1));
        }
        if ($iterator instanceof Countable) {
            $count = count($iterator);
            if ($count === 0) {
                return $this->new_collection([]);
            }
            $iterator = new Limit_Iterator($iterator, max(0, $count - $length), $length);
            return $this->new_collection($iterator);
        }
        $generator = function ($iterator, $length): Generator {
            $result = [];
            $bucket = 0;
            $offset = 0;
            /**
             * Consider the collection of elements [1, 2, 3, 4, 5, 6, 7, 8, 9], in order
             * to get the last 4 elements, we can keep a buffer of 4 elements and
             * fill it circularly using modulo logic, we use the $bucket variable
             * to track the position to fill next in the buffer. This how the buffer
             * looks like after 4 iterations:
             *
             * 0) 1 2 3 4 -- $bucket now goes back to 0, we have filled 4 elementes
             * 1) 5 2 3 4 -- 5th iteration
             * 2) 5 6 3 4 -- 6th iteration
             * 3) 5 6 7 4 -- 7th iteration
             * 4) 5 6 7 8 -- 8th iteration
             * 5) 9 6 7 8
             *
             *  We can see that at the end of the iterations, the buffer contains all
             *  the last four elements, just in the wrong order. How do we keep the
             *  original order? Well, it turns out that the number of iteration also
             *  give us a clue on what's going on, Let's add a marker for it now:
             *
             * 0) 1 2 3 4
             *    ^ -- The 0) above now becomes the $offset variable
             * 1) 5 2 3 4
             *      ^ -- $offset = 1
             * 2) 5 6 3 4
             *        ^ -- $offset = 2
             * 3) 5 6 7 4
             *          ^ -- $offset = 3
             * 4) 5 6 7 8
             *    ^  -- We use module logic for $offset too
             *          and as you can see each time $offset is 0, then the buffer
             *          is sorted exactly as we need.
             * 5) 9 6 7 8
             *      ^ -- $offset = 1
             *
             * The $offset variable is a marker for splitting the buffer in two,
             * elements to the right for the marker are the head of the final result,
             * whereas the elements at the left are the tail. For example consider step 5)
             * which has an offset of 1:
             *
             * - $head = elements to the right = [6, 7, 8]
             * - $tail = elements to the left =  [9]
             * - $result = $head + $tail = [6, 7, 8, 9]
             *
             * The logic above applies to collections of any size.
             */
            foreach ($iterator as $k => $item) {
                $result[$bucket] = [$k, $item];
                $bucket = ++$bucket % $length;
                $offset++;
            }
            $offset %= $length;
            $head = array_slice($result, $offset);
            $tail = array_slice($result, 0, $offset);
            foreach ($head as $v) {
                yield $v[0] => $v[1];
            }
            foreach ($tail as $v) {
                yield $v[0] => $v[1];
            }
        };
        return $this->new_collection($generator($iterator, $length));
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function append(iterable $items): Collection_Interface
    {
        $list = new Append_Iterator();
        $list->append($this->unwrap());
        $list->append($this->new_collection($items)->unwrap());
        return $this->new_collection($list);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function append_item(mixed $item, mixed $key = null): Collection_Interface
    {
        if ($key !== null) {
            $data = [$key => $item];
        } else {
            $data = [$item];
        }
        return $this->append($data);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function prepend(mixed $items): Collection_Interface
    {
        return $this->new_collection($items)->append($this);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function prepend_item(mixed $item, mixed $key = null): Collection_Interface
    {
        if ($key !== null) {
            $data = [$key => $item];
        } else {
            $data = [$item];
        }
        return $this->prepend($data);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function combine(callable|string $key_path, callable|string $value_path, callable|string|null $group_path = null): Collection_Interface
    {
        $options = ['keyPath' => $this->_property_extractor($key_path), 'valuePath' => $this->_property_extractor($value_path), 'groupPath' => $group_path ? $this->_property_extractor($group_path) : null];
        $mapper = function ($value, $key, Map_Reduce $map_reduce) use ($options) {
            $row_key = $options['keyPath'];
            $row_val = $options['valuePath'];
            if (!$options['groupPath']) {
                $map_key = $row_key($value, $key);
                if ($map_key === null) {
                    throw new InvalidArgumentException('Cannot index by path that does not exist or contains a null value. ' . 'Use a callback to return a default value for that path.');
                }
                if ($map_key instanceof Backed_Enum) {
                    $map_key = $map_key->value;
                } elseif ($map_key instanceof Unit_Enum) {
                    $map_key = $map_key->name;
                }
                $map_reduce->emit($row_val($value, $key), $map_key);
                return null;
            }
            $key = $options['groupPath']($value, $key);
            if ($key === null) {
                throw new InvalidArgumentException('Cannot group by path that does not exist or contains a null value. ' . 'Use a callback to return a default value for that path.');
            }
            $map_key = $row_key($value, $key);
            if ($map_key === null) {
                throw new InvalidArgumentException('Cannot index by path that does not exist or contains a null value. ' . 'Use a callback to return a default value for that path.');
            }
            $map_reduce->emit_intermediate([$map_key => $row_val($value, $key)], $key);
        };
        $reducer = function ($values, $key, Map_Reduce $map_reduce): void {
            $result = [];
            foreach ($values as $value) {
                $result += $value;
            }
            $map_reduce->emit($result, $key);
        };
        return $this->new_collection(new Map_Reduce($this->unwrap(), $mapper, $reducer));
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function nest(callable|string $id_path, callable|string $parent_path, string $nesting_key = 'children'): Collection_Interface
    {
        $parents = [];
        $id_path = $this->_property_extractor($id_path);
        $parent_path = $this->_property_extractor($parent_path);
        $is_object = true;
        $mapper = function (array $row, $key, Map_Reduce $map_reduce) use (&$parents, $id_path, $parent_path, $nesting_key): void {
            $row[$nesting_key] = [];
            $id = $id_path($row, $key);
            $parent_id = $parent_path($row, $key);
            $parents[$id] =& $row;
            $map_reduce->emit_intermediate($id, $parent_id);
        };
        $reducer = function ($values, $key, Map_Reduce $map_reduce) use (&$parents, &$is_object, $nesting_key) {
            static $found_out_type = false;
            if (!$found_out_type) {
                $is_object = is_object(current($parents));
                $found_out_type = true;
            }
            if (!$key || !isset($parents[$key])) {
                foreach ($values as $id) {
                    $parents[$id] = $is_object ? $parents[$id] : new ArrayIterator($parents[$id], 1);
                    $map_reduce->emit($parents[$id]);
                }
                return null;
            }
            $children = [];
            foreach ($values as $id) {
                $children[] =& $parents[$id];
            }
            $parents[$key][$nesting_key] = $children;
        };
        return $this->new_collection(new Map_Reduce($this->unwrap(), $mapper, $reducer))->map(function ($value) use ($is_object) {
            /** @var \ArrayIterator<int|string, mixed>|\ArrayObject<int|string, mixed> $value */
            return $is_object ? $value : $value->get_array_copy();
        });
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function insert(string $path, mixed $values): Collection_Interface
    {
        return new Insert_Iterator($this->unwrap(), $path, $values);
    }
    /**
     * @inheritDoc
     */
    public function to_array(bool $keep_keys = true): array
    {
        $iterator = $this->unwrap();
        if ($iterator instanceof ArrayIterator) {
            $items = $iterator->get_array_copy();
            return $keep_keys ? $items : array_values($items);
        }
        // RecursiveIteratorIterator can return duplicate key values causing
        // data loss when converted into an array
        if ($keep_keys && $iterator::class === Recursive_Iterator_Iterator::class) {
            $keep_keys = false;
        }
        return iterator_to_array($this, $keep_keys);
    }
    /**
     * @inheritDoc
     */
    public function to_list(): array
    {
        return $this->to_array(false);
    }
    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return $this->to_array();
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function compile(bool $keep_keys = true): Collection_Interface
    {
        return $this->new_collection($this->to_array($keep_keys));
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function lazy(): Collection_Interface
    {
        $generator = function (): Generator {
            foreach ($this->unwrap() as $k => $v) {
                yield $k => $v;
            }
        };
        return $this->new_collection($generator());
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function buffered(): Collection_Interface
    {
        return new Buffered_Iterator($this->unwrap());
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function list_nested(string|int $order = 'desc', callable|string $nesting_key = 'children'): Collection_Interface
    {
        if (is_string($order)) {
            $order = strtolower($order);
            $modes = ['desc' => Recursive_Iterator_Iterator::SELF_FIRST, 'asc' => Recursive_Iterator_Iterator::CHILD_FIRST, 'leaves' => Recursive_Iterator_Iterator::LEAVES_ONLY];
            if (!isset($modes[$order])) {
                throw new InvalidArgumentException(sprintf("Invalid direction `%s` provided. Must be one of: 'desc', 'asc', 'leaves'.", $order));
            }
            $order = $modes[$order];
        }
        assert(in_array($order, [Recursive_Iterator_Iterator::LEAVES_ONLY, Recursive_Iterator_Iterator::SELF_FIRST, Recursive_Iterator_Iterator::CHILD_FIRST], true));
        return new Tree_Iterator(new Nest_Iterator($this, $nesting_key), $order);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function stop_when(callable|array $condition): Collection_Interface
    {
        if (!is_callable($condition)) {
            $condition = $this->_create_matcher_filter($condition);
        }
        return new Stoppable_Iterator($this->unwrap(), $condition);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function unfold(?callable $callback = null): Collection_Interface
    {
        $callback ??= fn($v) => $v;
        return $this->new_collection(new Recursive_Iterator_Iterator(new Unfold_Iterator($this->unwrap(), $callback), Recursive_Iterator_Iterator::LEAVES_ONLY));
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, TValue>
     */
    public function through(callable $callback): Collection_Interface
    {
        $result = $callback($this);
        return $result instanceof Collection_Interface ? $result : $this->new_collection($result);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function zip(iterable ...$items): Collection_Interface
    {
        return new Zip_Iterator(array_merge([$this->unwrap()], $items));
    }
    /**
     * {@inheritDoc}
     *
     * @param iterable $items Items to zip.
     * @param callable $callback The callback to apply.
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function zip_with(iterable $items, mixed $callback): Collection_Interface
    {
        if (func_num_args() > 2) {
            $items = func_get_args();
            $callback = array_pop($items);
        } else {
            $items = [$items];
        }
        /** @var callable $callback */
        return new Zip_Iterator(array_merge([$this->unwrap()], $items), $callback);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, array<TValue>>
     */
    public function chunk(int $chunk_size): Collection_Interface
    {
        // @phpstan-ignore return.type
        return $this->map(function ($v, $k, Iterator $iterator) use ($chunk_size): array {
            $values = [$v];
            for ($i = 1; $i < $chunk_size; $i++) {
                $iterator->next();
                if (!$iterator->valid()) {
                    break;
                }
                $values[] = $iterator->current();
            }
            return $values;
        });
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<TKey, array<TKey, TValue>>
     */
    public function chunk_with_keys(int $chunk_size, bool $keep_keys = true): Collection_Interface
    {
        // @phpstan-ignore return.type
        return $this->map(function ($v, $k, Iterator $iterator) use ($chunk_size, $keep_keys): array {
            $key = 0;
            if ($keep_keys) {
                $key = $k;
            }
            $values = [$key => $v];
            for ($i = 1; $i < $chunk_size; $i++) {
                $iterator->next();
                if (!$iterator->valid()) {
                    break;
                }
                if ($keep_keys) {
                    $values[$iterator->key()] = $iterator->current();
                } else {
                    $values[] = $iterator->current();
                }
            }
            return $values;
        });
    }
    /**
     * @inheritDoc
     */
    public function is_empty(): bool
    {
        // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        foreach ($this as $el) {
            return false;
        }
        return true;
    }
    /**
     * @inheritDoc
     */
    public function unwrap(): Iterator
    {
        $iterator = $this;
        // Unwrap Collection class and simple user subclasses.
        // Internal CakePHP iterators/result sets have their own unwrap() implementations.
        // We unwrap if the class is Collection itself, or a non-Cake subclass,
        // or an anonymous class extending Collection.
        while ($iterator instanceof Collection && ($iterator::class === Collection::class || !str_starts_with($iterator::class, 'Cake\\') || str_contains($iterator::class, '@anonymous'))) {
            $iterator = $iterator->get_inner_iterator();
        }
        if ($iterator !== $this && $iterator instanceof Collection_Interface) {
            return $iterator->unwrap();
        }
        return $iterator;
    }
    /**
     * {@inheritDoc}
     *
     * @param callable|null $operation A callable that allows you to customize the product result.
     * @param callable|null $filter A filtering callback that must return true for a result to be part
     *   of the final results.
     * @return \Cake\Collection\CollectionInterface<int, array<mixed>>
     * @throws \LogicException
     */
    public function cartesian_product(?callable $operation = null, ?callable $filter = null): Collection_Interface
    {
        if ($this->is_empty()) {
            return $this->new_collection([]);
            // @phpstan-ignore return.type
        }
        $collection_arrays = [];
        $collection_arrays_keys = [];
        $collection_arrays_counts = [];
        foreach ($this->to_list() as $value) {
            /** @phpstan-ignore argument.type (cartesianProduct requires array values) */
            $value_count = count($value);
            /** @phpstan-ignore argument.type */
            if ($value_count !== count($value, COUNT_RECURSIVE)) {
                throw new LogicException('Cannot find the cartesian product of a multidimensional array');
            }
            /** @phpstan-ignore argument.type (cartesianProduct requires array values) */
            $collection_arrays_keys[] = array_keys($value);
            $collection_arrays_counts[] = $value_count;
            $collection_arrays[] = $value;
        }
        $result = [];
        $last_index = count($collection_arrays) - 1;
        // holds the indexes of the arrays that generate the current combination
        $current_indexes = array_fill(0, $last_index + 1, 0);
        $change_index = $last_index;
        while (!($change_index === 0 && $current_indexes[0] === $collection_arrays_counts[0])) {
            $current_combination = array_map(fn($value, array $keys, $index) => $value[$keys[$index]], $collection_arrays, $collection_arrays_keys, $current_indexes);
            if ($filter === null || $filter($current_combination)) {
                $result[] = $operation === null ? $current_combination : $operation($current_combination);
            }
            $current_indexes[$last_index]++;
            for ($change_index = $last_index; $current_indexes[$change_index] === $collection_arrays_counts[$change_index] && $change_index > 0; $change_index--) {
                $current_indexes[$change_index] = 0;
                $current_indexes[$change_index - 1]++;
            }
        }
        return $this->new_collection($result);
        // @phpstan-ignore return.type
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface<int, array<mixed>>
     * @throws \LogicException
     */
    public function transpose(): Collection_Interface
    {
        $array_value = $this->to_list();
        /** @phpstan-ignore argument.type (transpose requires array values) */
        $length = count(current($array_value));
        $result = [];
        foreach ($array_value as $row) {
            /** @phpstan-ignore argument.type (transpose requires array values) */
            if (count($row) !== $length) {
                throw new LogicException('Child arrays do not have even length');
            }
        }
        for ($column = 0; $column < $length; $column++) {
            $result[] = array_column($array_value, $column);
        }
        return $this->new_collection($result);
        // @phpstan-ignore return.type
    }
    /**
     * @inheritDoc
     */
    public function count(): int
    {
        $traversable = $this->optimize_unwrap();
        if (is_array($traversable)) {
            return count($traversable);
        }
        return iterator_count($traversable);
    }
    /**
     * @inheritDoc
     */
    public function count_keys(): int
    {
        return count($this->to_array());
    }
    /**
     * Unwraps this iterator and returns the simplest
     * traversable that can be used for getting the data out
     */
    protected function optimize_unwrap(): Iterator|array
    {
        $iterator = $this->unwrap();
        if ($iterator::class === ArrayIterator::class) {
            return $iterator->get_array_copy();
        }
        return $iterator;
    }
}